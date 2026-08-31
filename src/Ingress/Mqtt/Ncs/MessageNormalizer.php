<?php

namespace Hub\Ingress\Mqtt\Ncs;

use Hub\Device\RawPayload;

final class MessageNormalizer
{
    /**
     * @param array{imei: string, supplier: string, model: string, commercialName?: string, deviceType: string, licenseId: string} $device
     * @return array{raw: array<string, mixed>, status?: array<string, mixed>, event?: array<string, mixed>}
     */
    public function normalize(Topic $topic, array $message, array $device): array
    {
        $raw = $this->rawPayload($topic, $message, $device);

        return match ($topic->kind) {
            'status' => $this->normalizeStatus($topic, $message, $device, $raw),
            'events' => $this->normalizeEvent($message, $device, $raw),
            default => ['raw' => $raw],
        };
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $device
     * @return array{raw: array<string, mixed>, status?: array<string, mixed>, event?: array<string, mixed>}
     */
    private function normalizeStatus(Topic $topic, array $message, array $device, array $raw): array
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
            $online ? 'online' : 'offline',
            null,
            (string)($device['commercialName'] ?? '')
        );

        $eventPayload = [
            'schemaVersion' => 1,
            'type' => $online ? 'device.connected' : 'device.disconnected',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $statusPayload['device'],
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
     * @return array{raw: array<string, mixed>, event?: array<string, mixed>}
     */
    private function normalizeEvent(array $message, array $device, array $raw): array
    {
        $payload = isset($message['payload']) && is_array($message['payload']) ? $message['payload'] : [];
        $key = $this->scalarOrNull($payload['key'] ?? null);
        $eventType = $key !== null ? $this->resolveNcsEventType((string)$key) : null;
        if ($eventType === null) {
            return ['raw' => $raw];
        }

        $eventData = array_filter([
            'pagerId' => $this->scalarOrNull($payload['id'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $event = [
            'schemaVersion' => 1,
            'type' => $eventType,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->device($device),
            'data' => $eventData,
        ];

        return [
            'raw' => $raw,
            'event' => $event,
        ];
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private function rawPayload(Topic $topic, array $message, array $device): array
    {
        return [
            'schemaVersion' => 1,
            'direction' => 'uplink',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => array_filter([
                'id' => (string)$device['imei'],
                'supplier' => (string)$device['supplier'],
                'model' => (string)$device['model'],
                'commercialName' => (string)($device['commercialName'] ?? ''),
            ], static fn (mixed $value): bool => $value !== ''),
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
        if ((string)($device['commercialName'] ?? '') !== '') {
            $payload['commercialName'] = (string)$device['commercialName'];
        }

        return $payload;
    }

    private function resolveNcsEventType(string $key): ?string
    {
        return match ($key) {
            '8' => 'help_call',
            '0', '1', '2' => 'reset',
            default => null,
        };
    }

    private function scalarOrNull(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }
}
