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
            'blood_sugar' => self::scalar($payload, 'value', ['bloodSugar', 'blood_sugar', 'bs', 'value']),
            'temperature' => self::temperature($payload),
            'battery' => self::battery($payload),
            'activity' => self::activity($payload),
            'heartbeat' => self::heartbeat($payload),
            'location' => self::location($payload),
            'alarm' => self::alarm($payload),
            'device_config' => self::deviceConfig($payload),
            default => [],
        };
    }

    private static function heartRate(array $payload): array
    {
        $value = self::first($payload, ['heartRate', 'heart_rate', 'hr', 'bpm', 'pulse', 'value']);
        return $value === null ? [] : ['bpm' => (int)$value];
    }

    private static function bloodPressure(array $payload): array
    {
        return array_filter([
            'systolicMmHg' => self::int($payload['systolic'] ?? $payload['systolicMmHg'] ?? $payload['sbp'] ?? null),
            'diastolicMmHg' => self::int($payload['diastolic'] ?? $payload['diastolicMmHg'] ?? $payload['dbp'] ?? null),
            'pulseBpm' => self::int($payload['pulse'] ?? $payload['pulseBpm'] ?? $payload['heartRate'] ?? $payload['hr'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function bloodOxygen(array $payload): array
    {
        $value = self::first($payload, ['spo2', 'spo2Percent', 'oxygen', 'bloodOxygen', 'bo', 'value']);
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
        $value = self::first($payload, ['bodyTemperature', 'temperature', 'bodyCelsius', 'temp', 'value']);
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
        return array_filter([
            'source' => $payload['source'] ?? null,
            'lat' => self::float($payload['lat'] ?? $payload['latitude'] ?? null),
            'lon' => self::float($payload['lon'] ?? $payload['lng'] ?? $payload['longitude'] ?? null),
            'gpsValid' => isset($payload['gpsValid']) ? (bool)$payload['gpsValid'] : null,
            'speedKmh' => self::float($payload['speed'] ?? $payload['speedKmh'] ?? null),
            'heading' => self::float($payload['direction'] ?? $payload['heading'] ?? null),
            'altitudeMeters' => self::float($payload['altitude'] ?? $payload['altitudeMeters'] ?? null),
            'satelliteCount' => self::int($payload['satellites'] ?? $payload['satelliteCount'] ?? null),
            'gsmSignal' => self::int($payload['gsmSignal'] ?? null),
            'mcc' => isset($payload['mcc']) ? (string)$payload['mcc'] : null,
            'mnc' => isset($payload['mnc']) ? (string)$payload['mnc'] : null,
            'lac' => isset($payload['lac']) ? (string)$payload['lac'] : null,
            'cellId' => isset($payload['cellId']) ? (string)$payload['cellId'] : null,
            'accuracyMeters' => self::float($payload['accuracy'] ?? $payload['accuracyMeters'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private static function alarm(array $payload): array
    {
        return array_filter([
            'code' => isset($payload['alarmCode']) ? (string)$payload['alarmCode'] : null,
            'sos' => isset($payload['sos']) ? (bool)$payload['sos'] : null,
            'lowBattery' => isset($payload['lowBattery']) ? (bool)$payload['lowBattery'] : null,
            'fall' => isset($payload['fall']) ? (bool)$payload['fall'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function deviceConfig(array $payload): array
    {
        return ['status' => 'ok'];
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
}
