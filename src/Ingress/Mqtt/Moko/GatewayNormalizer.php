<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Support\Values;

final class GatewayNormalizer
{
    /** @param array<string, mixed> $message @param array<string, mixed> $device @return list<array<string, mixed>> */
    public function telemetry(array $message, array $device): array
    {
        if (!is_array($message['data'] ?? null)) {
            return [];
        }
        $data = $message['data'];
        $messageId = (string)($message['messageId'] ?? '');
        $protocol = (string)($message['protocol'] ?? 'moko-mkgw3');
        $common = [
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => array_filter([
                'id' => (string)$device['imei'],
                'supplier' => (string)$device['supplier'],
                'model' => (string)$device['model'],
                'commercialName' => (string)($device['commercialName'] ?? ''),
            ], static fn(string $value): bool => $value !== ''),
            'source' => ['protocol' => $protocol, 'nativeType' => $messageId],
        ];

        if ($messageId === '3089') {
            if (!is_numeric($data['latitude'] ?? null) || !is_numeric($data['longitude'] ?? null)) {
                return [];
            }
            return [[
                'type' => 'location',
                'data' => Values::withoutNulls([
                    'source' => 'gps',
                    'gpsValid' => ($data['fix_result'] ?? null) === 'gps_success',
                    'hasCoordinates' => true,
                    'lat' => (float)$data['latitude'],
                    'lon' => (float)$data['longitude'],
                    'hdop' => is_numeric($data['hdop'] ?? null) ? (float)$data['hdop'] : null,
                ]),
            ] + $common];
        }

        if ($messageId !== '3004') {
            return [];
        }

        if ($protocol === 'moko-mkgw4') {
            $csq = is_numeric($data['csq'] ?? null) ? (int)$data['csq'] : null;
            $connectivity = array_filter([
                'interface' => 'cellular',
                'networkType' => isset($data['network_type']) ? trim((string)$data['network_type']) : null,
                'signalQuality' => $csq,
                'signalStrengthDbm' => $csq !== null && $csq >= 0 && $csq <= 31 ? -113 + (2 * $csq) : null,
            ], static fn(mixed $value): bool => $value !== null && $value !== '');
            $telemetry = [['type' => 'connectivity', 'data' => $connectivity] + $common];
            if (is_numeric($data['battery_voltage_mv'] ?? null)) {
                $telemetry[] = [
                    'type' => 'battery',
                    'data' => ['voltageMv' => (int)$data['battery_voltage_mv']],
                ] + $common;
            }
            return $telemetry;
        }

        $interface = match ((int)($data['net_interface'] ?? -1)) {
            0 => 'ethernet',
            1 => 'wifi',
            2 => 'ethernet_wifi',
            default => null,
        };
        $connectivity = Values::withoutNulls([
            'interface' => $interface,
            'signalStrengthDbm' => is_numeric($data['wifi_rssi'] ?? null) ? (int)$data['wifi_rssi'] : null,
        ]);
        if ($connectivity === []) {
            return [];
        }

        return [[
            'type' => 'connectivity',
            'data' => $connectivity,
        ] + $common];
    }
}
