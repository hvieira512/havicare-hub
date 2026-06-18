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

        $eventData = array_filter([
            'from' => trim((string)($message['from'] ?? '')),
            'topicSourceId' => $topic->sourceId,
            'messageType' => $message['type'] ?? null,
            'code' => $this->scalarOrNull($payload['code'] ?? $message['type'] ?? null),
            'key' => $this->scalarOrNull($payload['key'] ?? null),
            'deviceId' => $this->scalarOrNull($payload['id'] ?? null),
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
        $json = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode NCS raw payload');
        }

        $raw = RawPayload::raw(
            (string)$device['imei'],
            (string)$device['supplier'],
            (string)$device['model'],
            'mqtt',
            'voerka-ncs',
            $json,
            'uplink'
        );
        $raw['debug']['sourceTopic'] = $topic->original;
        $raw['debug']['sourceScope'] = $topic->scope;
        $raw['debug']['sourceMessageKind'] = $topic->kind;
        if ($topic->statusName !== null) {
            $raw['debug']['sourceStatus'] = $topic->statusName;
        }

        return $raw;
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
