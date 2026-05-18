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

        return array_filter([
            'latitude' => self::toNullableFloat($lat),
            'longitude' => self::toNullableFloat($lon),
            'altitudeMeters' => self::toNullableInt($gps['height'] ?? $payload['altitude'] ?? null),
            'satelliteCount' => self::toNullableInt($gps['satelliteNum'] ?? $payload['satellites'] ?? null),
        ], static fn($value) => $value !== null);
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
}
