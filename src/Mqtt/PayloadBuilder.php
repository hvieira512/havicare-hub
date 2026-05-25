<?php

namespace App\Mqtt;

use App\Domain\EventNormalizer;
use App\Registry\DeviceCapabilities;
use App\Registry\Whitelist;

class PayloadBuilder
{
    public static function buildTelemetryPayload(array $event, string $imei, Whitelist $whitelist): array
    {
        $streamId = (string)($event['streamId'] ?? '0-0');
        $receivedAtMs = (int)($event['receivedAt'] ?? (int)round(microtime(true) * 1000));

        $model = $whitelist->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;

        return [
            'event' => [
                'type' => 'telemetry.received',
                'id' => self::eventIdFromStreamId($streamId),
            ],
            'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z', max(0, (int)floor($receivedAtMs / 1000))),
            'device' => [
                'imei' => $imei,
                'model' => $model,
                'supplier' => $caps?->getSupplier(),
            ],
            'data' => EventNormalizer::normalize(
                ($event['feature'] ?? '') !== '' ? (string)$event['feature'] : null,
                isset($event['nativeType']) ? (string)$event['nativeType'] : null,
                is_array($event['nativePayload'] ?? null) ? $event['nativePayload'] : [],
                isset($event['protocol']) ? (string)$event['protocol'] : $caps?->getProtocol()
            ),
        ];
    }

    public static function buildStatusPayload(array $event, string $imei, Whitelist $whitelist): array
    {
        $streamId = (string)($event['streamId'] ?? '0-0');
        $timestampMs = (int)($event['timestamp'] ?? (int)round(microtime(true) * 1000));
        $state = (string)($event['state'] ?? 'unknown');

        $model = $whitelist->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;

        return [
            'event' => [
                'type' => 'device.status.changed',
                'id' => self::eventIdFromStreamId($streamId),
            ],
            'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z', max(0, (int)floor($timestampMs / 1000))),
            'device' => [
                'imei' => $imei,
                'model' => $model,
                'supplier' => $caps?->getSupplier(),
            ],
            'data' => [
                'state' => $state,
                'reason' => $event['reason'] ?? null,
            ],
        ];
    }

    public static function buildErrorPayload(array $event, string $imei, Whitelist $whitelist): array
    {
        $streamId = (string)($event['streamId'] ?? '0-0');
        $timestampMs = (int)($event['timestamp'] ?? (int)round(microtime(true) * 1000));

        $model = $whitelist->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;

        return [
            'event' => [
                'type' => 'integration.error',
                'id' => self::eventIdFromStreamId($streamId),
            ],
            'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z', max(0, (int)floor($timestampMs / 1000))),
            'device' => [
                'imei' => $imei,
                'model' => $model,
                'supplier' => $caps?->getSupplier(),
            ],
            'data' => [
                'code' => $event['code'] ?? 'unknown_error',
                'message' => $event['message'] ?? 'Unknown error',
            ],
        ];
    }

    public static function buildCommandStatePayload(array $event, string $imei, Whitelist $whitelist): array
    {
        $streamId = (string)($event['streamId'] ?? '0-0');
        $timestampMs = (int)($event['timestamp'] ?? (int)round(microtime(true) * 1000));

        $model = $whitelist->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;

        return [
            'event' => [
                'type' => 'command.state.changed',
                'id' => self::eventIdFromStreamId($streamId),
            ],
            'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z', max(0, (int)floor($timestampMs / 1000))),
            'device' => [
                'imei' => $imei,
                'model' => $model,
                'supplier' => $caps?->getSupplier(),
            ],
            'data' => [
                'state' => $event['state'] ?? null,
                'requestId' => $event['requestId'] ?? null,
            ],
        ];
    }

    public static function eventIdFromStreamId(string $streamId): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]/', '_', $streamId) ?: '0_0';
        return 'evt_' . $normalized;
    }
}
