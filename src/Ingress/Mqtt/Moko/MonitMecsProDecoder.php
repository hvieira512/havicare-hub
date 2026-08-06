<?php

namespace Hub\Ingress\Mqtt\Moko;

final class MonitMecsProDecoder
{
    /**
     * @param array<string, mixed> $observation
     * @return array<string, mixed>|null
     */
    public function decode(array $observation): ?array
    {
        $mac = Topic::normalizeMac((string)($observation['mac'] ?? ''));
        $advHex = strtolower(trim((string)($observation['adv_data'] ?? '')));
        if ($mac === null || !str_starts_with($mac, 'eec500') || $advHex === '' || preg_match('/^[0-9a-f]+$/', $advHex) !== 1 || strlen($advHex) % 2 !== 0) {
            return null;
        }

        $bytes = array_values(unpack('C*', hex2bin($advHex) ?: ''));
        $manufacturer = $this->adStructure($bytes, 0xff);
        if ($manufacturer === null || count($manufacturer) < 25) {
            return null;
        }
        if (array_slice($manufacturer, 0, 4) !== [0x59, 0x00, 0x02, 0x15]) {
            return null;
        }

        $raw20 = array_slice($manufacturer, 4, 20);
        if (count($raw20) !== 20 || strtolower(sprintf('%02x%02x%02x', ...array_slice($raw20, 17, 3))) !== substr($mac, 6)) {
            return null;
        }

        $bits = '';
        foreach ($raw20 as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $read = static fn(int $offset, int $length): int => bindec(substr($bits, $offset, $length));
        $baseline = [];
        $raw = [];
        for ($index = 0; $index < 10; $index++) {
            $baseline[] = $read(16 + ($index * 6), 6);
            $raw[] = $read(76 + ($index * 6), 6);
        }
        $normalized = array_map(static fn(int $value, int $base): int => max($value - $base, 0), $raw, $baseline);

        return [
            'mac' => $mac,
            'packetType' => $read(0, 3),
            'batteryPercent' => $read(3, 7),
            'alarmType' => $read(10, 1),
            'txStrength' => $read(11, 2),
            'eventStatus' => $read(13, 3),
            'baseline' => $baseline,
            'raw' => $raw,
            'normalized' => $normalized,
            'raw20' => implode('', array_map(static fn(int $byte): string => sprintf('%02x', $byte), $raw20)),
            'rssiDbm' => is_numeric($observation['rssi'] ?? null) ? (int)$observation['rssi'] : null,
        ];
    }

    /** @param list<int> $bytes @return list<int>|null */
    private function adStructure(array $bytes, int $wantedType): ?array
    {
        for ($offset = 0, $count = count($bytes); $offset < $count;) {
            $length = $bytes[$offset] ?? 0;
            if ($length === 0 || $offset + $length >= $count + 1) {
                break;
            }
            $type = $bytes[$offset + 1] ?? -1;
            if ($type === $wantedType) {
                return array_slice($bytes, $offset + 2, $length - 1);
            }
            $offset += $length + 1;
        }
        return null;
    }
}
