<?php

declare(strict_types=1);

namespace Hub\Device;

final class DeviceEventPayloadBuilder
{
    public static function decoded(DeviceSession $session, array $decodedEvent): array
    {
        $feature = (string)$decodedEvent['feature'];

        $device = DeviceDescriptor::fromParts(
            $session->imei,
            $session->supplier,
            $session->model,
            $session->commercialName,
        );

        $payload = [
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
