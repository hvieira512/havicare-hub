<?php

namespace Hub\Ingress\Mqtt\Moko;

final class Mkgw4MessageDecoder implements MessageDecoder
{
    private const SUPPORTED_MESSAGES = ['3004', '3089', '30a0', '30b2'];

    /** @return array<string, mixed>|null */
    public function decode(string $payload): ?array
    {
        $binary = $this->binaryPayload($payload);
        if ($binary === null || strlen($binary) < 11 || ord($binary[0]) !== 0xef) {
            return null;
        }

        $messageId = strtolower(bin2hex(substr($binary, 1, 2)));
        $gatewayMac = Topic::normalizeMac(bin2hex(substr($binary, 3, 6)));
        $declaredLength = unpack('n', substr($binary, 9, 2))[1] ?? -1;
        if ($gatewayMac === null || !in_array($messageId, self::SUPPORTED_MESSAGES, true) || $declaredLength < 0 || strlen($binary) !== 11 + $declaredLength) {
            return null;
        }

        $tlvs = $this->tlvs(substr($binary, 11));
        if ($tlvs === null) {
            return null;
        }

        $data = match ($messageId) {
            '3004' => $this->deviceStatus($tlvs),
            '3089' => $this->gpsData($tlvs),
            '30a0', '30b2' => $this->scanDevices($tlvs),
        };

        return [
            'messageId' => ctype_digit($messageId) ? (int)$messageId : $messageId,
            'gatewayMac' => $gatewayMac,
            'data' => $data,
            'protocol' => 'moko-mkgw4',
            'encoding' => 'binary',
            'declaredLength' => $declaredLength,
        ];
    }

    private function binaryPayload(string $payload): ?string
    {
        if ($payload !== '' && ord($payload[0]) === 0xef) {
            return $payload;
        }
        $hex = preg_replace('/\s+/', '', $payload);
        if (!is_string($hex) || $hex === '' || strlen($hex) % 2 !== 0 || preg_match('/^[0-9a-f]+$/i', $hex) !== 1) {
            return null;
        }
        $binary = hex2bin($hex);
        return $binary === false ? null : $binary;
    }

    /** @return list<array{tag: int, value: string}>|null */
    private function tlvs(string $payload): ?array
    {
        $items = [];
        for ($offset = 0, $length = strlen($payload); $offset < $length;) {
            if ($offset + 3 > $length) {
                return null;
            }
            $tag = ord($payload[$offset]);
            $valueLength = unpack('n', substr($payload, $offset + 1, 2))[1] ?? -1;
            $offset += 3;
            if ($valueLength < 0 || $offset + $valueLength > $length) {
                return null;
            }
            $items[] = ['tag' => $tag, 'value' => substr($payload, $offset, $valueLength)];
            $offset += $valueLength;
        }
        return $items;
    }

    /** @param list<array{tag: int, value: string}> $tlvs @return array<string, mixed> */
    private function deviceStatus(array $tlvs): array
    {
        $data = [];
        foreach ($tlvs as $tlv) {
            $value = $tlv['value'];
            match ($tlv['tag']) {
                0 => $data['timestamp'] = $this->unsigned($value),
                1 => $data['network_type'] = $value,
                2 => $data['csq'] = $this->unsigned($value),
                3 => $data['battery_voltage_mv'] = $this->unsigned($value),
                4 => $data['acceleration'] = strlen($value) === 6 ? [
                    'xMg' => $this->signed(substr($value, 0, 2)),
                    'yMg' => $this->signed(substr($value, 2, 2)),
                    'zMg' => $this->signed(substr($value, 4, 2)),
                ] : bin2hex($value),
                5 => $data['accelerometer_status'] = $this->unsigned($value),
                6 => $data['imei'] = $value,
                7 => $data['heartbeat_index'] = $this->unsigned($value),
                default => null,
            };
        }
        return $data;
    }

    /** @param list<array{tag: int, value: string}> $tlvs @return array<string, mixed> */
    private function gpsData(array $tlvs): array
    {
        $fixModes = ['off', 'periodic', 'motion'];
        $fixResults = ['gps_success', 'lbs_success', 'interrupted', 'gps_port_busy', 'gps_timeout', 'gps_fix_timeout', 'pdop_limited', 'lbs_failed'];
        $motionModes = ['stationary', 'movement_started', 'moving', 'movement_ended'];
        $data = [];
        foreach ($tlvs as $tlv) {
            $value = $tlv['value'];
            $number = $this->unsigned($value);
            match ($tlv['tag']) {
                0 => $data['timestamp'] = $number,
                1 => $data['fix_mode'] = $fixModes[$number] ?? $number,
                2 => $data['fix_result'] = $fixResults[$number] ?? $number,
                3 => $data += strlen($value) === 8 ? [
                    'longitude' => $this->signed(substr($value, 0, 4)) * 0.0000001,
                    'latitude' => $this->signed(substr($value, 4, 4)) * 0.0000001,
                ] : [],
                4 => $data += strlen($value) >= 3 ? [
                    'tac_lac' => $this->unsigned(substr($value, 0, 2)),
                    'cell_id' => $this->unsigned(substr($value, 2)),
                ] : [],
                5 => $data['motion_mode'] = $motionModes[$number] ?? $number,
                6 => $data['operator'] = $value,
                7 => $data['hdop'] = $number * 0.1,
                8 => $data['position_package_index'] = $number,
                default => null,
            };
        }
        return $data;
    }

    /** @param list<array{tag: int, value: string}> $tlvs @return list<array<string, mixed>> */
    private function scanDevices(array $tlvs): array
    {
        $devices = [];
        $device = [];
        foreach ($tlvs as $tlv) {
            if ($tlv['tag'] === 0) {
                if ($device !== []) {
                    $devices[] = $device;
                }
                $typeCode = $this->unsigned($tlv['value']);
                $device = ['type_code' => $typeCode, 'type' => $this->scanType($typeCode)];
                continue;
            }
            if ($device === []) {
                continue;
            }
            $value = $tlv['value'];
            match ($tlv['tag']) {
                1 => $device['mac'] = bin2hex($value),
                2 => $device['connectable'] = $this->unsigned($value),
                3 => $device += strlen($value) >= 4 ? [
                    'timestamp' => $this->unsigned(substr($value, 0, 4)),
                    'timezone' => strlen($value) >= 5 ? $this->signed(substr($value, 4, 1)) / 2 : null,
                ] : [],
                4 => $device['rssi'] = $this->signed($value),
                5 => $device['adv_data'] = bin2hex($value),
                6 => $device['rsp_data'] = bin2hex($value),
                0xa0 => $device['max_rssi'] = $this->signed($value),
                0xa1 => $device['min_rssi'] = $this->signed($value),
                0xa2 => $device['avg_rssi'] = $this->signed($value),
                0xa3 => $device['scan_count'] = $this->unsigned($value),
                default => $tlv['tag'] >= 0x0a && $tlv['tag'] < 0xa0
                    ? $device['data_block_' . ($tlv['tag'] - 9)] = bin2hex($value)
                    : null,
            };
        }
        if ($device !== []) {
            $devices[] = $device;
        }
        return $devices;
    }

    private function scanType(int $typeCode): string
    {
        return ['ibeacon', 'eddystone-uid', 'eddystone-url', 'eddystone-tlm', 'bxp-devinfo', 'bxp-acc', 'bxp-th', 'bxp-button', 'bxp-tag', 'pir', 'other', 'tof', 'bxp-s', 'nano-beacon'][$typeCode] ?? 'unknown';
    }

    private function unsigned(string $bytes): int
    {
        $value = 0;
        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $value = ($value << 8) | $byte;
        }
        return $value;
    }

    private function signed(string $bytes): int
    {
        $value = $this->unsigned($bytes);
        $bits = strlen($bytes) * 8;
        if ($bits > 0 && ($value & (1 << ($bits - 1))) !== 0) {
            $value -= 1 << $bits;
        }
        return $value;
    }
}
