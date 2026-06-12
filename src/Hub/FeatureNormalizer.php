<?php

namespace App\Hub;

final class FeatureNormalizer
{
    public static function normalize(string $feature, array $payload): array
    {
        return match ($feature) {
            'heart_rate' => self::heartRate($payload),
            'blood_pressure' => self::bloodPressure($payload),
            'blood_oxygen' => self::bloodOxygen($payload),
            'blood_sugar' => self::scalar($payload, 'value', ['bloodSugar', 'blood_sugar', 'bs', 'value', 'data']),
            'temperature' => self::temperature($payload),
            'battery' => self::battery($payload),
            'activity' => self::activity($payload),
            'heartbeat' => self::heartbeat($payload),
            'location' => self::location($payload),
            'alarm' => self::alarm($payload),
            'device_config' => self::deviceConfig($payload),
            'weather' => self::weather($payload),
            default => [],
        };
    }

    private static function heartRate(array $payload): array
    {
        $value = self::first($payload, ['heartRate', 'heart_rate', 'hr', 'bpm', 'pulse', 'value', 'data', 'date']);
        return $value === null ? [] : ['bpm' => (int)$value];
    }

    private static function bloodPressure(array $payload): array
    {
        $rawData = $payload['data'] ?? $payload['date'] ?? null;
        if (is_string($rawData) && str_contains($rawData, '/')) {
            $parts = preg_split('/[\/,\-]+/', $rawData);
            $payload['systolic'] = $parts[0] ?? null;
            $payload['diastolic'] = $parts[1] ?? null;
            $payload['pulse'] = $parts[2] ?? $payload['pulse'] ?? null;
        }

        return array_filter([
            'systolicMmHg' => self::int($payload['systolic'] ?? $payload['systolicMmHg'] ?? $payload['sbp'] ?? null),
            'diastolicMmHg' => self::int($payload['diastolic'] ?? $payload['diastolicMmHg'] ?? $payload['dbp'] ?? null),
            'pulseBpm' => self::int($payload['pulse'] ?? $payload['pulseBpm'] ?? $payload['heartRate'] ?? $payload['hr'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function bloodOxygen(array $payload): array
    {
        $value = self::first($payload, ['spo2', 'spo2Percent', 'oxygen', 'bloodOxygen', 'bo', 'value', 'data', 'date']);
        return $value === null ? [] : ['spo2Percent' => (int)$value];
    }

    private static function scalar(array $payload, string $field, array $keys): array
    {
        $value = self::first($payload, $keys);
        if ($value === null || !is_numeric((string)$value)) {
            return [];
        }

        return [$field => str_contains((string)$value, '.') ? (float)$value : (int)$value];
    }

    private static function temperature(array $payload): array
    {
        $value = self::first($payload, ['bodyTemperature', 'temperature', 'bodyCelsius', 'temp', 'value', 'data', 'date']);
        return $value === null ? [] : ['bodyCelsius' => (float)$value];
    }

    private static function battery(array $payload): array
    {
        $value = self::first($payload, ['batteryPercent', 'battery', 'batteryLevel', 'power', 'value']);
        return array_filter([
            'percent' => $value === null ? null : (int)$value,
            'charging' => isset($payload['charging']) ? (bool)$payload['charging'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function activity(array $payload): array
    {
        return array_filter([
            'steps' => self::int($payload['steps'] ?? $payload['step'] ?? null),
            'distanceMeters' => self::float($payload['distanceMeters'] ?? null),
            'caloriesKcal' => self::float($payload['caloriesKcal'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function heartbeat(array $payload): array
    {
        return ['status' => 'ok'];
    }

    private static function location(array $payload): array
    {
        $gps = isset($payload['gps']) && is_array($payload['gps']) ? $payload['gps'] : [];
        $baseStations = isset($payload['baseStation']) && is_array($payload['baseStation']) ? $payload['baseStation'] : [];
        $wifiAccessPoints = isset($payload['wifi']) && is_array($payload['wifi']) ? $payload['wifi'] : [];
        $firstBaseStation = $baseStations[0] ?? [];

        $location = array_filter([
            'source' => $payload['source'] ?? ($gps !== [] ? 'gps' : ($baseStations !== [] || $wifiAccessPoints !== [] ? 'cell' : null)),
            'lat' => self::float($payload['lat'] ?? $payload['latitude'] ?? $gps['lat'] ?? $gps['latitude'] ?? null),
            'lon' => self::float($payload['lon'] ?? $payload['lng'] ?? $payload['longitude'] ?? $gps['lon'] ?? $gps['lng'] ?? $gps['longitude'] ?? null),
            'gpsValid' => isset($payload['gpsValid']) ? (bool)$payload['gpsValid'] : (isset($gps['Type']) ? ((int)$gps['Type'] === 0) : null),
            'speedKmh' => self::float($payload['speed'] ?? $payload['speedKmh'] ?? $gps['speed'] ?? null),
            'heading' => self::float($payload['direction'] ?? $payload['heading'] ?? $gps['direction'] ?? null),
            'altitudeMeters' => self::float($payload['altitude'] ?? $payload['altitudeMeters'] ?? $gps['height'] ?? null),
            'satelliteCount' => self::int($payload['satellites'] ?? $payload['satelliteCount'] ?? $gps['satelliteNum'] ?? null),
            'gsmSignal' => self::int($payload['gsmSignal'] ?? $gps['GSM'] ?? $firstBaseStation['rxlev'] ?? null),
            'mcc' => self::stringOrNull($payload['mcc'] ?? $gps['mcc'] ?? $firstBaseStation['mcc'] ?? null),
            'mnc' => self::stringOrNull($payload['mnc'] ?? $gps['mnc'] ?? $firstBaseStation['mnc'] ?? null),
            'lac' => self::stringOrNull($payload['lac'] ?? $gps['lac'] ?? $firstBaseStation['lac'] ?? null),
            'cellId' => self::stringOrNull($payload['cellId'] ?? $gps['cellId'] ?? $gps['ci'] ?? $firstBaseStation['ci'] ?? null),
            'accuracyMeters' => self::float($payload['accuracy'] ?? $payload['accuracyMeters'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if ($location === [] && $baseStations === [] && $wifiAccessPoints === []) {
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

    private static function alarm(array $payload): array
    {
        return array_filter([
            'code' => isset($payload['alarmCode']) ? (string)$payload['alarmCode'] : null,
            'sos' => isset($payload['sos']) ? (bool)$payload['sos'] : null,
            'lowBattery' => isset($payload['lowBattery']) ? (bool)$payload['lowBattery'] : null,
            'fall' => isset($payload['fall']) ? (bool)$payload['fall'] : null,
            'wearingNotice' => isset($payload['wearingNotice']) ? (bool)$payload['wearingNotice'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function deviceConfig(array $payload): array
    {
        return ['status' => 'ok'];
    }

    private static function weather(array $payload): array
    {
        return ['status' => 'requested'];
    }

    private static function first(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== '') {
                return $payload[$key];
            }
        }

        return null;
    }

    private static function int(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric((string)$value) ? null : (int)$value;
    }

    private static function float(mixed $value): ?float
    {
        return $value === null || $value === '' || !is_numeric((string)$value) ? null : (float)$value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string)$value;
    }
}
