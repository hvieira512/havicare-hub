<?php

namespace App\Hub;

final class DeviceEventPayloadBuilder
{
    public static function decoded(DeviceSession $session, array $decodedEvent): array
    {
        $payload = RawPayload::event(
            $session->imei,
            $session->supplier,
            $session->model,
            'device.data.received'
        );

        $payload['data'] = [
            'feature' => (string)$decodedEvent['feature'],
            'nativeType' => (string)$decodedEvent['nativeType'],
            'value' => $decodedEvent['value'],
        ];

        $extra = $decodedEvent['extra'] ?? [];
        if (is_array($extra) && $extra !== []) {
            $payload['extra'] = $extra;
        }

        return $payload;
    }
}
