<?php

namespace Hub\Ingress\Mqtt\Moko;

final class GatewayNormalizer
{
    /** @param array<string, mixed> $message @param array<string, mixed> $device @return list<array<string, mixed>> */
    public function telemetry(array $message, array $device): array
    {
        if (($message['messageId'] ?? null) !== 3004 || !is_array($message['data'] ?? null)) {
            return [];
        }
        $data = $message['data'];
        $interface = match ((int)($data['net_interface'] ?? -1)) {
            0 => 'ethernet',
            1 => 'wifi',
            2 => 'ethernet_wifi',
            default => null,
        };
        $connectivity = array_filter([
            'interface' => $interface,
            'signalStrengthDbm' => is_numeric($data['wifi_rssi'] ?? null) ? (int)$data['wifi_rssi'] : null,
        ], static fn(mixed $value): bool => $value !== null);
        if ($connectivity === []) {
            return [];
        }

        return [[
            'schemaVersion' => 2,
            'type' => 'connectivity',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => array_filter([
                'id' => (string)$device['imei'],
                'supplier' => (string)$device['supplier'],
                'model' => (string)$device['model'],
                'commercialName' => (string)($device['commercialName'] ?? ''),
            ], static fn(string $value): bool => $value !== ''),
            'data' => $connectivity,
            'source' => ['protocol' => 'moko-mkgw3', 'nativeType' => '3004'],
        ]];
    }
}
