<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

/**
 * Decodes MOKO W6R (BXP-B / "MK Button") advertisements relayed by a gateway.
 *
 * Layout comes from the BXP-B Series tab of "MOKO Beacon - ADV Format Summary
 * Sheet". Two service data blocks are relevant, and either may be absent:
 *
 *   FEE0  alarm frame     which press mode fired, and that mode's trigger count
 *   EA00  general info    accelerometer, temperature and battery
 *
 * Offsets are resolved by walking the advertising data structures rather than
 * indexing the packet, because the sheet's absolute offsets do not agree with
 * its own length bytes.
 */
final class W6rDecoder
{
    /** Service UUIDs as they appear on the wire (little endian). */
    private const ALARM_SERVICE = 'e0fe';
    private const INFO_SERVICE = '00ea';

    private const PRESS_MODES = [
        0x20 => 'single',
        0x21 => 'double',
        0x22 => 'long',
        0x23 => 'inactivity',
    ];

    /**
     * @param array<string, mixed> $observation a single entry of a 3070 scan report
     * @return array<string, mixed>|null null when the payload is not a W6R
     */
    public function decode(array $observation): ?array
    {
        $mac = Topic::normalizeMac((string)($observation['mac'] ?? ''));
        if ($mac === null) {
            return null;
        }

        $structures = array_merge(
            $this->adStructures((string)($observation['adv_data'] ?? '')),
            $this->adStructures((string)($observation['rsp_data'] ?? '')),
        );

        $alarm = $this->alarm($this->serviceData($structures, self::ALARM_SERVICE));
        $info = $this->info($this->serviceData($structures, self::INFO_SERVICE));
        if ($alarm === null && $info === null) {
            return null;
        }

        return array_filter([
            'mac' => $mac,
            'alarm' => $alarm,
            'info' => $info,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @param list<int>|null $data service data payload with the UUID stripped
     * @return array{pressMode: string, frameType: int, triggerCount: int, triggered: bool, deviceId: string, firmwareType: int}|null
     */
    private function alarm(?array $data): ?array
    {
        // frame type, status flag, 2-byte count, 6-byte device id, firmware type
        if ($data === null || count($data) < 11) {
            return null;
        }

        $pressMode = self::PRESS_MODES[$data[0]] ?? null;
        if ($pressMode === null) {
            return null;
        }

        return [
            'pressMode' => $pressMode,
            'frameType' => $data[0],
            'triggerCount' => ($data[2] << 8) | $data[3],
            // Bit 1 is the main button's alarm state; bit 2 is a sub button that
            // only BXP-B03-D firmware has.
            'triggered' => ($data[1] & 0x02) !== 0,
            'deviceId' => implode('', array_map(
                static fn(int $byte): string => sprintf('%02x', $byte),
                array_slice($data, 4, 6)
            )),
            'firmwareType' => $data[10],
        ];
    }

    /**
     * @param list<int>|null $data service data payload with the UUID stripped
     * @return array{accelerationMg: array{x: int, y: int, z: int}, batteryPercent?: int, batteryVoltageMv?: int}|null
     */
    private function info(?array $data): ?array
    {
        // frame type, full scale, threshold, x, y, z, temperature, ranging, battery
        if ($data === null || count($data) < 15 || $data[0] !== 0x00) {
            return null;
        }

        $battery = ($data[13] << 8) | $data[14];

        return [
            'accelerationMg' => [
                'x' => $this->signed16($data[4], $data[5]),
                'y' => $this->signed16($data[6], $data[7]),
                'z' => $this->signed16($data[8], $data[9]),
            ],
            // Above 100 the field carries millivolts instead of a percentage.
            ...($battery > 100
                ? ['batteryVoltageMv' => $battery]
                : ['batteryPercent' => $battery]),
        ];
    }

    /**
     * @param list<array{type: int, data: list<int>}> $structures
     * @return list<int>|null
     */
    private function serviceData(array $structures, string $wireUuid): ?array
    {
        foreach ($structures as $structure) {
            if ($structure['type'] !== 0x16 || count($structure['data']) < 2) {
                continue;
            }
            $uuid = sprintf('%02x%02x', $structure['data'][0], $structure['data'][1]);
            if ($uuid === $wireUuid) {
                return array_slice($structure['data'], 2);
            }
        }

        return null;
    }

    private function signed16(int $high, int $low): int
    {
        $value = ($high << 8) | $low;

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    /** @return list<array{type: int, data: list<int>}> */
    private function adStructures(string $hex): array
    {
        $hex = strtolower(trim($hex));
        if ($hex === '' || strlen($hex) % 2 !== 0 || preg_match('/^[0-9a-f]+$/', $hex) !== 1) {
            return [];
        }

        $bytes = array_values(unpack('C*', hex2bin($hex) ?: ''));
        $structures = [];
        $offset = 0;
        $count = count($bytes);
        while ($offset < $count) {
            $length = $bytes[$offset];
            if ($length === 0 || $offset + $length >= $count + 1) {
                break;
            }
            $structures[] = [
                'type' => $bytes[$offset + 1] ?? 0,
                'data' => array_values(array_slice($bytes, $offset + 2, $length - 1)),
            ];
            $offset += $length + 1;
        }

        return $structures;
    }
}
