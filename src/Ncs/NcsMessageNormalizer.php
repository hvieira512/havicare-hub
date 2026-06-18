<?php

namespace Hub\Ncs;

use Hub\FeatureNormalizer;
use Hub\RawPayload;

final class NcsMessageNormalizer
{
    /**
     * @param array{imei: string, supplier: string, model: string, deviceType: string, licenseId: string, simNumber: string, deviceId: string, sourceSystem: string, sourceDeviceId: string} $device
     * @return array{raw: array<string, mixed>, status?: array<string, mixed>, event?: array<string, mixed>, telemetry?: array<string, mixed>}
     */
    public function normalize(NcsTopic $topic, array $message, array $device): array
    {
        $raw = $this->rawPayload($topic, $message, $device);

        return match ($topic->kind) {
            'status' => $this->normalizeStatus($topic, $message, $device, $raw),
            'events' => $this->normalizeEvent($topic, $message, $device, $raw),
            default => ['raw' => $raw],
        };
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $device
     * @return array{raw: array<string, mixed>, status?: array<string, mixed>, event?: array<string, mixed>, telemetry?: array<string, mixed>}
     */
    private function normalizeStatus(NcsTopic $topic, array $message, array $device, array $raw): array
    {
        if ($topic->statusName !== 'online') {
            return ['raw' => $raw];
        }

        $status = isset($message['payload']) && is_array($message['payload']) ? $message['payload'] : [];
        $statusData = isset($status['status']) && is_array($status['status']) ? $status['status'] : $status;
        if (!array_key_exists('online', $statusData)) {
            throw new \InvalidArgumentException('NCS status/online payload is missing online');
        }

        $online = (bool)$statusData['online'];
        $statusPayload = RawPayload::status(
            (string)$device['imei'],
            (string)$device['supplier'],
            (string)$device['model'],
            $online ? 'online' : 'offline'
        );
        $statusPayload['source'] = $this->source($topic);
        $statusPayload['data'] = ['online' => $online];

        $eventPayload = [
            'schemaVersion' => 1,
            'type' => $online ? 'device.connected' : 'device.disconnected',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $statusPayload['device'],
            'source' => $this->source($topic),
            'data' => ['online' => $online],
        ];

        return [
            'raw' => $raw,
            'status' => $statusPayload,
            'event' => $eventPayload,
        ];
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $device
     * @return array{raw: array<string, mixed>, event: array<string, mixed>, telemetry?: array<string, mixed>}
     */
    private function normalizeEvent(NcsTopic $topic, array $message, array $device, array $raw): array
    {
        $payload = isset($message['payload']) && is_array($message['payload']) ? $message['payload'] : [];
        $locationPayload = $this->locationPayload($payload);
        $normalizedLocation = $locationPayload !== null ? FeatureNormalizer::normalize('location', $locationPayload) : [];

        $key = $this->scalarOrNull($payload['key'] ?? null);
        $ncsEvent = $key !== null ? $this->resolveNcsEvent((string)$key) : [];

        $eventData = array_filter([
            'from' => trim((string)($message['from'] ?? '')),
            'topicSourceId' => $topic->sourceId,
            'messageType' => $message['type'] ?? null,
            'code' => $this->scalarOrNull($payload['code'] ?? $message['type'] ?? null),
            'key' => $key,
            'deviceId' => $this->scalarOrNull($payload['id'] ?? null),
            'event' => $ncsEvent['event'] ?? null,
            'alarm' => $ncsEvent['alarm'] ?? null,
            'location' => $normalizedLocation !== [] ? $normalizedLocation : $locationPayload,
            'transparent' => $payload['transparent'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $event = [
            'schemaVersion' => 1,
            'type' => 'ncs.event',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->device($device),
            'source' => $this->source($topic),
            'data' => $eventData,
        ];

        $normalized = [
            'raw' => $raw,
            'event' => $event,
        ];

        if ($normalizedLocation !== []) {
            $telemetry = [
                'schemaVersion' => 2,
                'type' => 'location',
                'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'device' => $this->device($device),
                'data' => $normalizedLocation,
                'source' => $this->source($topic),
                'extra' => array_filter([
                    'code' => $eventData['code'] ?? null,
                    'key' => $eventData['key'] ?? null,
                    'deviceId' => $eventData['deviceId'] ?? null,
                    'transparent' => $eventData['transparent'] ?? null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ];
            if ($telemetry['extra'] === []) {
                unset($telemetry['extra']);
            }
            $normalized['telemetry'] = $telemetry;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private function rawPayload(NcsTopic $topic, array $message, array $device): array
    {
        return [
            'schemaVersion' => 1,
            'direction' => 'uplink',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => [
                'id' => (string)$device['imei'],
                'supplier' => (string)$device['supplier'],
                'model' => (string)$device['model'],
            ],
            'debug' => array_filter([
                'protocol' => 'voerka-ncs',
                'transport' => 'mqtt',
                'payload' => $message,
                'sourceTopic' => $topic->original,
                'sourceScope' => $topic->scope,
                'sourceMessageKind' => $topic->kind,
                'sourceStatus' => $topic->statusName,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * @param array<string, mixed> $device
     * @return array<string, string>
     */
    private function device(array $device): array
    {
        $payload = ['id' => (string)$device['imei']];
        if ((string)($device['supplier'] ?? '') !== '') {
            $payload['supplier'] = (string)$device['supplier'];
        }
        if ((string)($device['model'] ?? '') !== '') {
            $payload['model'] = (string)$device['model'];
        }

        return $payload;
    }

    /**
     * @return array{protocol: string, nativeType: string, topic: string}
     */
    private function source(NcsTopic $topic): array
    {
        return [
            'protocol' => 'voerka-ncs',
            'nativeType' => $topic->nativeType(),
            'topic' => $topic->original,
        ];
    }

    /**
     * @return array{event: string, alarm: bool}
     */
    private function resolveNcsEvent(string $key): array
    {
        return match ($key) {
            '8' => ['event' => 'help_call', 'alarm' => true],
            '0', '1', '2' => ['event' => 'reset', 'alarm' => false],
            default => ['event' => 'general_alert', 'alarm' => true],
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function locationPayload(array $payload): ?array
    {
        $location = $payload['location'] ?? null;
        if (is_array($location)) {
            return $location;
        }

        return null;
    }

    private function scalarOrNull(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }
}
