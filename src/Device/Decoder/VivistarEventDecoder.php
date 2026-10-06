<?php

declare(strict_types=1);

namespace Hub\Device\Decoder;

use Hub\Device\DeviceEventDecoder;

final class VivistarEventDecoder
{
    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public static function decode(string $nativeType, array $payload): array
    {
        return match ($nativeType) {
            'AP01' => [DeviceEventDecoder::locationEvent($nativeType, $payload)],
            'AP02' => [self::decodeAp02($payload)],
            'AP49' => [DeviceEventDecoder::event('heart_rate', $nativeType, $payload)],
            'APHT' => [
                DeviceEventDecoder::event('heart_rate', $nativeType, $payload),
                DeviceEventDecoder::event('blood_pressure', $nativeType, $payload),
            ],
            'APHP' => array_values(array_filter([
                DeviceEventDecoder::event('heart_rate', $nativeType, $payload),
                DeviceEventDecoder::event('blood_pressure', $nativeType, $payload),
                DeviceEventDecoder::event('blood_oxygen', $nativeType, $payload),
                DeviceEventDecoder::event('blood_sugar', $nativeType, $payload),
            ])),
            'AP50' => [
                DeviceEventDecoder::event('temperature', $nativeType, $payload),
                DeviceEventDecoder::event('battery', $nativeType, ['battery' => $payload['battery'] ?? null]),
            ],
            'AP10' => array_values(array_filter([
                ...DeviceEventDecoder::alarmEvents($nativeType, $payload),
                DeviceEventDecoder::locationEvent($nativeType, $payload),
                DeviceEventDecoder::event('battery', $nativeType, ['battery' => $payload['battery'] ?? null]),
            ])),
            'AP03' => [
                DeviceEventDecoder::event('heartbeat', $nativeType, $payload),
                DeviceEventDecoder::event('battery', $nativeType, ['battery' => $payload['battery'] ?? null]),
                DeviceEventDecoder::event('activity', $nativeType, ['steps' => $payload['steps'] ?? null]),
            ],
            'AP12', 'AP14', 'AP28', 'AP33', 'AP40',
            'AP76', 'AP77', 'AP84', 'AP85', 'AP86',
            'APJZ', 'AP43' => [
                DeviceEventDecoder::event('device_config', $nativeType, $payload),
            ],
            'AP16', 'AP87', 'APXL', 'APXY', 'APXT', 'APXZ' => [],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function decodeAp02(array $payload): ?array
    {
        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [];
        $baseStations = self::parseBaseStations((string)($fields[5] ?? ''));
        $wifi = self::parseWifi((string)($fields[7] ?? ''));
        $firstBase = $baseStations[0] ?? [];

        return DeviceEventDecoder::locationEvent('AP02', array_filter([
            'source' => 'vivistar-ap02',
            'gpsValid' => false,
            'mcc' => DeviceEventDecoder::stringField($fields[3] ?? null),
            'mnc' => DeviceEventDecoder::stringField($fields[4] ?? null),
            'lac' => $firstBase['lac'] ?? null,
            'cellId' => $firstBase['cellId'] ?? null,
            'gsmSignal' => $firstBase['gsmSignal'] ?? null,
            'accuracyMeters' => null,
            'baseStations' => $baseStations,
            'wifi' => $wifi,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @return array<int, array{lac?: string, cellId?: string, gsmSignal?: int}>
     */
    private static function parseBaseStations(string $field): array
    {
        $stations = [];
        foreach (explode(',', $field) as $entry) {
            $parts = array_map('trim', explode('|', $entry));
            if (count($parts) < 3) {
                continue;
            }

            $rawSignal = self::intField($parts[2] ?? null);
            $stations[] = array_filter([
                'lac' => $parts[0] !== '' ? $parts[0] : null,
                'cellId' => $parts[1] !== '' ? $parts[1] : null,
                'gsmSignal' => self::legacySignalStrength($rawSignal),
                'signalStrengthDbm' => self::signalDbm($rawSignal),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $stations;
    }

    /**
     * @return array<int, array{label?: string, mac?: string, gsmSignal?: int}>
     */
    private static function parseWifi(string $field): array
    {
        $wifi = [];
        foreach (explode('&', $field) as $entry) {
            $parts = array_map('trim', explode('|', $entry));
            if (count($parts) < 3) {
                continue;
            }

            $rawSignal = self::intField($parts[2] ?? null);
            $wifi[] = array_filter([
                'label' => $parts[0] !== '' ? $parts[0] : null,
                'mac' => $parts[1] !== '' ? $parts[1] : null,
                'gsmSignal' => self::legacySignalStrength($rawSignal),
                'signalStrengthDbm' => self::signalDbm($rawSignal),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $wifi;
    }

    private static function legacySignalStrength(?int $value): ?int
    {
        return $value === null ? null : max(0, 150 - abs($value));
    }

    private static function signalDbm(?int $value): ?int
    {
        return $value === null ? null : $value - 150;
    }

    private static function intField(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric((string)$value) ? null : (int)$value;
    }
}
