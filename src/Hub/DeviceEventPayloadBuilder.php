<?php

namespace Hub;

final class DeviceEventPayloadBuilder
{
    public static function decoded(DeviceSession $session, array $decodedEvent): array
    {
        $feature = (string)$decodedEvent['feature'];

        $device = ['id' => $session->imei];
        if ($session->supplier !== '') {
            $device['supplier'] = $session->supplier;
        }
        if ($session->model !== '') {
            $device['model'] = $session->model;
        }

        $payload = [
            'schemaVersion' => 2,
            'type' => $feature,
            'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'device' => $device,
            'data' => $decodedEvent['value'],
            'source' => [
                'protocol' => $session->protocol,
                'nativeType' => (string)$decodedEvent['nativeType'],
            ],
        ];

        $extra = $decodedEvent['extra'] ?? [];
        if (is_array($extra) && $extra !== []) {
            $payload['extra'] = $extra;
        }

        return $payload;
    }
}
