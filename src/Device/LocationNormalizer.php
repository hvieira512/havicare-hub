<?php

declare(strict_types=1);

namespace Hub\Device;

/**
 * A capacidade `location`: GPS, células e pontos WiFi reduzidos à mesma forma.
 */
final class LocationNormalizer
{
    public static function location(array $payload): array
    {
        $gps = isset($payload['gps']) && is_array($payload['gps']) ? $payload['gps'] : [];
        $radioType = self::normalizeRadioType(
            $payload['radioType']
                ?? $payload['networkType']
                ?? match ((string)($payload['baseStationType'] ?? '')) {
                    '0' => 'lte',
                    '1' => 'cdma',
                    default => null,
                }
        );
        $mcc = FeatureNormalizer::stringOrNull($payload['mcc'] ?? $gps['mcc'] ?? null);
        $mnc = FeatureNormalizer::stringOrNull($payload['mnc'] ?? $gps['mnc'] ?? null);
        $baseStations = self::normalizeBaseStations(
            isset($payload['baseStations']) && is_array($payload['baseStations'])
                ? $payload['baseStations']
                : (isset($payload['baseStation']) && is_array($payload['baseStation']) ? $payload['baseStation'] : []),
            $mcc,
            $mnc,
            $radioType,
        );
        $wifiAccessPoints = self::normalizeWifiAccessPoints(
            isset($payload['wifiAccessPoints']) && is_array($payload['wifiAccessPoints'])
                ? $payload['wifiAccessPoints']
                : (
                    isset($payload['wifi']) && is_array($payload['wifi'])
                        ? $payload['wifi']
                        : (isset($payload['Wifi']) && is_array($payload['Wifi']) ? $payload['Wifi'] : [])
                )
        );
        $firstBaseStation = $baseStations[0] ?? [];
        $lat = FeatureNormalizer::float($payload['lat'] ?? $payload['latitude'] ?? $gps['lat'] ?? $gps['latitude'] ?? null);
        $lon = FeatureNormalizer::float($payload['lon'] ?? $payload['lng'] ?? $payload['longitude'] ?? $gps['lon'] ?? $gps['lng'] ?? $gps['longitude'] ?? null);
        $gpsValid = isset($payload['gpsValid']) ? (bool)$payload['gpsValid'] : null;
        $satelliteCount = FeatureNormalizer::int($payload['satellites'] ?? $payload['satelliteCount'] ?? $gps['satelliteNum'] ?? null);
        if ($gpsValid !== true && $lat === 0.0 && $lon === 0.0) {
            $lat = null;
            $lon = null;
        }
        $hasCoordinates = $lat !== null && $lon !== null;

        $location = array_filter([
            'source' => self::normalizeLocationSource($payload, $gps, $gpsValid, $baseStations, $wifiAccessPoints, $lat, $lon, $satelliteCount),
            'hasCoordinates' => $hasCoordinates,
            'lat' => $lat,
            'lon' => $lon,
            'gpsValid' => $gpsValid,
            'radioType' => $radioType,
            'coordinateSystem' => self::normalizeCoordinateSystem($payload['coordinateSystem'] ?? $gps['Type'] ?? null),
            'reportKind' => self::normalizeReportKind($payload['reportKind'] ?? null),
            'speedKmh' => FeatureNormalizer::float($payload['speed'] ?? $payload['speedKmh'] ?? $gps['speed'] ?? null),
            'heading' => FeatureNormalizer::float($payload['direction'] ?? $payload['heading'] ?? $gps['direction'] ?? null),
            'altitudeMeters' => FeatureNormalizer::float($payload['altitude'] ?? $payload['altitudeMeters'] ?? $gps['height'] ?? null),
            'satelliteCount' => $satelliteCount,
            'gsmSignal' => FeatureNormalizer::int($payload['gsmSignal'] ?? $gps['GSM'] ?? $firstBaseStation['rxlev'] ?? $firstBaseStation['gsmSignal'] ?? null),
            'mcc' => $mcc ?? FeatureNormalizer::stringOrNull($firstBaseStation['mcc'] ?? null),
            'mnc' => $mnc ?? FeatureNormalizer::stringOrNull($firstBaseStation['mnc'] ?? null),
            'lac' => FeatureNormalizer::stringOrNull($payload['lac'] ?? $gps['lac'] ?? $firstBaseStation['lac'] ?? null),
            'cellId' => FeatureNormalizer::stringOrNull($payload['cellId'] ?? $gps['cellId'] ?? $gps['ci'] ?? $firstBaseStation['ci'] ?? $firstBaseStation['cellId'] ?? null),
            'accuracyMeters' => FeatureNormalizer::float($payload['accuracy'] ?? $payload['accuracyMeters'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $meaningfulLocation = array_diff_key($location, ['hasCoordinates' => true]);
        if ($meaningfulLocation === [] && $baseStations === [] && $wifiAccessPoints === []) {
            return [];
        }

        if ($baseStations !== []) {
            $location['baseStations'] = $baseStations;
        }
        if ($wifiAccessPoints !== []) {
            $location['wifiAccessPoints'] = $wifiAccessPoints;
        }

        return $location;
    }

    private static function normalizeLocationSource(
        array $payload,
        array $gps,
        ?bool $gpsValid,
        array $baseStations,
        array $wifiAccessPoints,
        ?float $lat,
        ?float $lon,
        ?int $satelliteCount,
    ): ?string {
        if ($gpsValid === true) {
            return 'gps';
        }

        $hasBaseStations = $baseStations !== [];
        $hasWifi = $wifiAccessPoints !== [];
        $explicit = strtolower(trim((string)($payload['source'] ?? '')));

        $normalized = match ($explicit) {
            'gps', 'gnss' => 'gps',
            'cell', 'lbs', 'gsm', 'basestation', 'base_station' => self::nonGpsLocationSource($hasBaseStations, $hasWifi),
            'wifi' => $hasBaseStations ? 'cell_wifi' : 'wifi',
            'cell_wifi', 'wifi_cell', 'lbs_wifi', 'wifi_lbs', 'vivistar-ap02' => self::nonGpsLocationSource($hasBaseStations, $hasWifi),
            default => null,
        };

        if ($normalized !== null) {
            return $normalized;
        }

        if ($gps !== [] && ($lat !== null || $lon !== null || ($satelliteCount !== null && $satelliteCount > 0))) {
            return 'gps';
        }

        if ($hasBaseStations || $hasWifi) {
            return self::nonGpsLocationSource($hasBaseStations, $hasWifi);
        }

        if ($lat !== null || $lon !== null || ($satelliteCount !== null && $satelliteCount > 0)) {
            return 'gps';
        }

        return null;
    }

    private static function nonGpsLocationSource(bool $hasBaseStations, bool $hasWifi): ?string
    {
        if ($hasBaseStations && $hasWifi) {
            return 'cell_wifi';
        }
        if ($hasWifi) {
            return 'wifi';
        }
        if ($hasBaseStations) {
            return 'cell';
        }

        return 'cell';
    }

    /**
     * @param array<int, mixed> $stations
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeBaseStations(
        array $stations,
        ?string $fallbackMcc = null,
        ?string $fallbackMnc = null,
        ?string $fallbackRadioType = null,
    ): array {
        $normalized = [];
        foreach ($stations as $station) {
            if (!is_array($station)) {
                continue;
            }

            $entry = array_filter([
                'mcc' => FeatureNormalizer::stringOrNull($station['mcc'] ?? $fallbackMcc),
                'mnc' => FeatureNormalizer::stringOrNull($station['mnc'] ?? $fallbackMnc),
                'lac' => FeatureNormalizer::stringOrNull($station['lac'] ?? null),
                'cellId' => FeatureNormalizer::stringOrNull($station['cellId'] ?? $station['ci'] ?? null),
                'gsmSignal' => FeatureNormalizer::int($station['gsmSignal'] ?? $station['rxlev'] ?? null),
                'radioType' => self::normalizeRadioType($station['radioType'] ?? $station['networkType'] ?? $fallbackRadioType),
                'signalStrengthDbm' => self::normalizeDbm(
                    $station['signalStrengthDbm'] ?? $station['signalDbm'] ?? $station['rssiDbm'] ?? null,
                    $station['gsmSignal'] ?? $station['rxlev'] ?? null,
                ),
                'sid' => FeatureNormalizer::int($station['sid'] ?? $station['systemId'] ?? null),
                'nid' => FeatureNormalizer::int($station['nid'] ?? $station['networkId'] ?? null),
                'bid' => FeatureNormalizer::int($station['bid'] ?? $station['baseStationId'] ?? null),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            if ($entry !== []) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $points
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeWifiAccessPoints(array $points): array
    {
        $normalized = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }

            $entry = array_filter([
                'ssid' => FeatureNormalizer::stringOrNull($point['ssid'] ?? $point['label'] ?? null),
                'mac' => self::normalizeMac($point['mac'] ?? $point['bssid'] ?? null),
                'signal' => FeatureNormalizer::int($point['signal'] ?? $point['rssi'] ?? $point['gsmSignal'] ?? null),
                'signalStrengthDbm' => self::normalizeDbm(
                    $point['signalStrengthDbm'] ?? $point['signalDbm'] ?? null,
                    $point['signal'] ?? $point['rssi'] ?? null,
                ),
                'channel' => FeatureNormalizer::int($point['channel'] ?? null),
                'frequencyMhz' => FeatureNormalizer::int($point['frequencyMhz'] ?? $point['frequency'] ?? null),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            if ($entry !== []) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    private static function normalizeRadioType(mixed $value): ?string
    {
        return match (strtolower(trim((string)$value))) {
            'gsm', '2g', 'gprs', 'edge' => 'gsm',
            'wcdma', 'umts', '3g', 'hspa', 'hspa+' => 'wcdma',
            'lte', '4g', 'cat-m', 'catm' => 'lte',
            'cdma' => 'cdma',
            'nr', '5g' => 'nr',
            default => null,
        };
    }

    private static function normalizeCoordinateSystem(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (strtolower(trim((string)$value))) {
            '0', 'global', 'gps', 'wgs84', 'wgs-84' => 'wgs84',
            '1', 'gaode', 'amap', 'gcj02', 'gcj-02' => 'gcj02',
            '2', 'baidu', 'bd09', 'bd-09' => 'bd09',
            '3', 'google' => 'google',
            '4', 'tencent' => 'tencent',
            default => null,
        };
    }

    private static function normalizeReportKind(mixed $value): ?string
    {
        return match (strtolower(trim((string)$value))) {
            'periodic', 'requested', 'alarm', 'replay' => strtolower(trim((string)$value)),
            default => null,
        };
    }

    private static function normalizeDbm(mixed $explicit, mixed $legacy): ?int
    {
        $value = FeatureNormalizer::int($explicit);
        if ($value !== null) {
            return $value < 0 ? $value : null;
        }

        $value = FeatureNormalizer::int($legacy);
        return $value !== null && $value < 0 ? $value : null;
    }

    private static function normalizeMac(mixed $value): ?string
    {
        $hex = strtolower((string)preg_replace('/[^0-9a-f]/i', '', trim((string)$value)));
        if (strlen($hex) !== 12) {
            return FeatureNormalizer::stringOrNull($value);
        }

        return implode(':', str_split($hex, 2));
    }
}
