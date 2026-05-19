<?php

namespace App\Domain;

final class EventNormalizer
{
    public static function normalize(?string $feature, ?string $nativeType, array $payload): array
    {
        $normalized = match ($feature) {
            'heart_rate', 'heartbeat' => self::normalizeHeartRate($payload),
            'blood_pressure' => self::normalizeBloodPressure($payload),
            'blood_oxygen' => self::normalizeBloodOxygen($payload),
            'temperature' => self::normalizeTemperature($payload),
            'location' => self::normalizeLocation($payload),
            'battery' => self::normalizeBattery($payload),
            'activity' => self::normalizeActivity($payload),
            'respiration', 'rr_interval' => self::normalizeRespiration($payload),
            'sleep' => self::normalizeSleep($payload),
            default => [],
        };

        if ($normalized !== []) {
            return $normalized;
        }

        if (is_string($nativeType) && preg_match('/^AP(HT|HP)$/', $nativeType) === 1) {
            $bp = self::normalizeBloodPressure($payload);
            $spo2 = self::normalizeBloodOxygen($payload);
            return array_merge($bp, $spo2);
        }

        return self::normalizeGeneric($payload);
    }

    private static function normalizeHeartRate(array $payload): array
    {
        $value = self::firstScalar($payload, ['heartRate', 'hr', 'bpm', 'value', 'date', 'data']);
        return $value === null ? [] : ['heartRateBpm' => (int)$value];
    }

    private static function normalizeBloodPressure(array $payload): array
    {
        $compound = self::firstScalar($payload, ['date', 'data', 'value']);
        if (is_string($compound)) {
            $parts = self::splitNumericParts($compound);
            if (count($parts) >= 2) {
                return array_filter([
                    'systolicMmHg' => isset($parts[0]) ? (int)$parts[0] : null,
                    'diastolicMmHg' => isset($parts[1]) ? (int)$parts[1] : null,
                    'pulseBpm' => isset($parts[2]) ? (int)$parts[2] : null,
                ], static fn($value) => $value !== null);
            }
        }

        return array_filter([
            'systolicMmHg' => self::toNullableInt($payload['systolic'] ?? $payload['sbp'] ?? null),
            'diastolicMmHg' => self::toNullableInt($payload['diastolic'] ?? $payload['dbp'] ?? null),
            'pulseBpm' => self::toNullableInt($payload['pulse'] ?? $payload['heartRate'] ?? $payload['hr'] ?? null),
        ], static fn($value) => $value !== null);
    }

    private static function normalizeBloodOxygen(array $payload): array
    {
        $value = self::firstScalar($payload, ['spo2', 'oxygen', 'bloodOxygen', 'value', 'date', 'data']);
        return $value === null ? [] : ['spo2Percent' => (int)$value];
    }

    private static function normalizeTemperature(array $payload): array
    {
        $compound = self::firstScalar($payload, ['date', 'data']);
        if (is_string($compound)) {
            $parts = self::splitNumericParts($compound);
            if ($parts !== []) {
                return ['bodyTemperatureC' => (float)$parts[0]];
            }
        }

        $value = self::firstScalar($payload, ['bodyTemperature', 'temperature', 'temp', 'value']);
        return $value === null ? [] : ['bodyTemperatureC' => (float)$value];
    }

    private static function normalizeLocation(array $payload): array
    {
        $gps = is_array($payload['gps'] ?? null) ? $payload['gps'] : [];
        $lat = $payload['lat'] ?? $gps['lat'] ?? $payload['latitude'] ?? null;
        $lon = $payload['lng'] ?? $payload['lon'] ?? $gps['lon'] ?? $payload['longitude'] ?? null;

        $base = array_filter([
            'latitude' => self::toNullableFloat($lat),
            'longitude' => self::toNullableFloat($lon),
            'altitudeMeters' => self::toNullableInt($gps['height'] ?? $payload['altitude'] ?? null),
            'satelliteCount' => self::toNullableInt($gps['satelliteNum'] ?? $payload['satellites'] ?? null),
        ], static fn($value) => $value !== null);

        return array_merge($base, self::normalizeVivistarLocation($payload));
    }

    private static function normalizeBattery(array $payload): array
    {
        $value = self::firstScalar($payload, ['battery', 'batteryLevel', 'power', 'electricity', 'value']);
        return array_filter([
            'batteryPercent' => $value === null ? null : (int)$value,
            'chargingState' => isset($payload['batteryState']) ? (int)$payload['batteryState'] : null,
            'batteryType' => isset($payload['batteryType']) ? (int)$payload['batteryType'] : null,
        ], static fn($v) => $v !== null);
    }

    private static function normalizeActivity(array $payload): array
    {
        return array_filter([
            'steps' => self::toNullableInt($payload['steps'] ?? null),
            'distanceMeters' => self::toNullableFloat($payload['distance'] ?? null),
            'caloriesKcal' => self::toNullableFloat($payload['calories'] ?? $payload['kcal'] ?? null),
        ], static fn($value) => $value !== null);
    }

    private static function normalizeRespiration(array $payload): array
    {
        $value = self::firstScalar($payload, ['rr', 'respiration', 'breathRate', 'value', 'date', 'data']);
        return $value === null ? [] : ['respirationPerMin' => (int)$value];
    }

    private static function normalizeSleep(array $payload): array
    {
        return array_filter([
            'durationMinutes' => self::toNullableInt($payload['duration'] ?? $payload['minutes'] ?? null),
            'deepMinutes' => self::toNullableInt($payload['deep'] ?? null),
            'lightMinutes' => self::toNullableInt($payload['light'] ?? null),
            'awakeMinutes' => self::toNullableInt($payload['awake'] ?? null),
        ], static fn($value) => $value !== null);
    }

    private static function normalizeGeneric(array $payload): array
    {
        $value = self::firstScalar($payload, ['value', 'data', 'date']);
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $parts = self::splitNumericParts($value);
            if (count($parts) > 1) {
                return ['values' => $parts];
            }
            if (count($parts) === 1) {
                return ['value' => $parts[0]];
            }
            return ['text' => $value];
        }

        if (is_numeric($value)) {
            return ['value' => $value + 0];
        }

        return [];
    }

    private static function firstScalar(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && (is_scalar($payload[$key]) || $payload[$key] === null)) {
                return $payload[$key];
            }
        }

        foreach ($payload as $key => $value) {
            if (is_int($key) && is_scalar($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function splitNumericParts(string $value): array
    {
        $parts = preg_split('/[\\/,:;|\\s-]+/', trim($value)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            if ($part === '' || !is_numeric($part)) {
                continue;
            }
            $out[] = str_contains($part, '.') ? (float)$part : (int)$part;
        }
        return $out;
    }

    private static function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (int)$value;
    }

    private static function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }

    private static function normalizeVivistarLocation(array $payload): array
    {
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        if ($fields === []) {
            return [];
        }

        $normalized = [];

        $rawHead = (string)($fields[0] ?? '');
        if (str_contains($rawHead, '|')) {
            $normalized['source'] = 'lbs_wifi';
        } elseif (str_starts_with($rawHead, 'zh_')) {
            $normalized['source'] = 'lbs_wifi';
        } else {
            $normalized['source'] = 'gps_lbs';
        }

        $gpsExtract = self::parseVivistarGpsTuple($rawHead);
        if ($gpsExtract !== []) {
            $normalized = array_merge($normalized, $gpsExtract);
        }

        $isAp02Style = str_starts_with(strtolower($rawHead), 'zh_');
        $mcc = $isAp02Style
            ? self::toNullableInt($fields[3] ?? null)
            : self::toNullableInt($fields[1] ?? null);
        $mnc = $isAp02Style
            ? self::toNullableInt($fields[4] ?? null)
            : self::toNullableInt($fields[2] ?? null);
        $cellComposite = $isAp02Style
            ? (string)($fields[5] ?? '')
            : (string)($fields[4] ?? '');
        if (str_contains($cellComposite, '|')) {
            $parts = explode('|', $cellComposite);
            $normalized['lac'] = self::toNullableInt($parts[0] ?? null);
            $normalized['cellId'] = self::toNullableInt($parts[1] ?? null);
            if (($parts[2] ?? '') !== '') {
                $normalized['baseStationSignal'] = self::toNullableInt($parts[2]);
            }
        } else {
            if ($isAp02Style) {
                $normalized['lac'] = self::toNullableInt($fields[5] ?? null);
                $normalized['cellId'] = self::toNullableInt($fields[6] ?? null);
            } else {
                $normalized['lac'] = self::toNullableInt($fields[3] ?? null);
                $normalized['cellId'] = self::toNullableInt($fields[4] ?? null);
            }
        }

        if ($mcc !== null) {
            $normalized['mcc'] = $mcc;
        }
        if ($mnc !== null) {
            $normalized['mnc'] = $mnc;
        }

        $wifiCount = self::toNullableInt($fields[6] ?? null);
        if ($wifiCount !== null) {
            $normalized['wifiCount'] = $wifiCount;
        }

        $wifiScan = (string)($fields[7] ?? $fields[5] ?? '');
        $wifiAps = self::parseVivistarWifiAccessPoints($wifiScan);
        if ($wifiAps !== []) {
            $normalized['wifiAccessPoints'] = $wifiAps;
            $normalized['wifiCount'] = count($wifiAps);
        }

        $baseStationCount = self::toNullableInt($fields[2] ?? null);
        if ($baseStationCount !== null) {
            $normalized['baseStationCount'] = $baseStationCount;
        }

        return array_filter(
            $normalized,
            static fn($value) => $value !== null && $value !== '' && $value !== []
        );
    }

    private static function parseVivistarGpsTuple(string $value): array
    {
        if ($value === '') {
            return [];
        }

        if (preg_match('/\d{6}[AV](\d{4}\.\d+)([NS])(\d{5}\.\d+)([EW])/', $value, $m) !== 1) {
            return [];
        }

        $lat = self::ddmmToDecimal($m[1], $m[2], true);
        $lon = self::ddmmToDecimal($m[3], $m[4], false);

        return array_filter([
            'latitude' => $lat,
            'longitude' => $lon,
        ], static fn($v) => $v !== null);
    }

    private static function ddmmToDecimal(string $value, string $hemi, bool $isLat): ?float
    {
        $degreesDigits = $isLat ? 2 : 3;
        if (strlen($value) <= $degreesDigits || !is_numeric($value)) {
            return null;
        }

        $degrees = (float)substr($value, 0, $degreesDigits);
        $minutes = (float)substr($value, $degreesDigits);
        $decimal = $degrees + ($minutes / 60.0);
        if (in_array($hemi, ['S', 'W'], true)) {
            $decimal *= -1.0;
        }

        return round($decimal, 6);
    }

    private static function parseVivistarWifiAccessPoints(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $entries = preg_split('/&/', $raw) ?: [];
        $wifi = [];
        foreach ($entries as $entry) {
            $parts = explode('|', trim($entry));
            if (count($parts) < 3) {
                continue;
            }
            $mac = trim((string)$parts[1]);
            if ($mac === '') {
                continue;
            }
            $wifi[] = array_filter([
                'mac' => strtolower($mac),
                'rssi' => self::toNullableInt($parts[2] ?? null),
            ], static fn($v) => $v !== null && $v !== '');
        }

        return $wifi;
    }
}
