<?php

namespace Hub;

final class FeatureNormalizer
{
    public static function normalize(string $feature, array $payload): array
    {
        return match ($feature) {
            'heart_rate' => self::heartRate($payload),
            'blood_pressure' => self::bloodPressure($payload),
            'blood_oxygen' => self::bloodOxygen($payload),
            'blood_sugar' => self::bloodSugar($payload),
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

    private static function bloodSugar(array $payload): array
    {
        $value = self::first($payload, ['bloodSugar', 'blood_sugar', 'glucoseMgDl', 'bs', 'value', 'data', 'date']);
        return $value === null ? [] : ['glucoseMgDl' => (int)$value];
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
            'chargingState' => self::int($payload['chargingState'] ?? $payload['batteryState'] ?? null),
            'batteryType' => self::int($payload['batteryType'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function activity(array $payload): array
    {
        return array_filter([
            'steps' => self::int($payload['steps'] ?? $payload['step'] ?? null),
            'distanceMeters' => self::float($payload['distanceMeters'] ?? $payload['distance'] ?? null),
            'caloriesKcal' => self::float($payload['caloriesKcal'] ?? $payload['kcal'] ?? $payload['calories'] ?? null),
            'exerciseSeconds' => self::int($payload['exerciseSeconds'] ?? $payload['exerciseTime'] ?? null),
            'standMinutes' => self::int($payload['standMinutes'] ?? $payload['standTime'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function heartbeat(array $payload): array
    {
        return array_filter([
            'status' => 'ok',
            'steps' => self::int($payload['steps'] ?? $payload['step'] ?? null),
            'gsmSignal' => self::int($payload['gsmSignal'] ?? null),
            'satelliteCount' => self::int($payload['satellites'] ?? $payload['satelliteCount'] ?? null),
            'batteryPercent' => self::int($payload['batteryPercent'] ?? $payload['battery'] ?? $payload['batteryLevel'] ?? null),
            'chargingState' => self::int($payload['chargingState'] ?? $payload['batteryState'] ?? null),
            'batteryType' => self::int($payload['batteryType'] ?? null),
            'rollFrequency' => self::int($payload['rollFrequency'] ?? $payload['rollsFrequency'] ?? null),
            'remainingSpace' => self::int($payload['remainingSpace'] ?? null),
            'fortificationState' => self::int($payload['fortificationState'] ?? $payload['fortification'] ?? null),
            'workMode' => self::int($payload['workMode'] ?? $payload['workingMode'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function location(array $payload): array
    {
        $gps = isset($payload['gps']) && is_array($payload['gps']) ? $payload['gps'] : [];
        $baseStations = isset($payload['baseStations']) && is_array($payload['baseStations'])
            ? $payload['baseStations']
            : (isset($payload['baseStation']) && is_array($payload['baseStation']) ? $payload['baseStation'] : []);
        $wifiAccessPoints = isset($payload['wifi']) && is_array($payload['wifi']) ? $payload['wifi'] : [];
        $firstBaseStation = $baseStations[0] ?? [];
        $lat = self::float($payload['lat'] ?? $payload['latitude'] ?? $gps['lat'] ?? $gps['latitude'] ?? null);
        $lon = self::float($payload['lon'] ?? $payload['lng'] ?? $payload['longitude'] ?? $gps['lon'] ?? $gps['lng'] ?? $gps['longitude'] ?? null);
        $gpsValid = isset($payload['gpsValid']) ? (bool)$payload['gpsValid'] : null;
        $satelliteCount = self::int($payload['satellites'] ?? $payload['satelliteCount'] ?? $gps['satelliteNum'] ?? null);

        $location = array_filter([
            'source' => self::normalizeLocationSource($payload, $gps, $gpsValid, $baseStations, $wifiAccessPoints, $lat, $lon, $satelliteCount),
            'lat' => $lat,
            'lon' => $lon,
            'gpsValid' => $gpsValid,
            'speedKmh' => self::float($payload['speed'] ?? $payload['speedKmh'] ?? $gps['speed'] ?? null),
            'heading' => self::float($payload['direction'] ?? $payload['heading'] ?? $gps['direction'] ?? null),
            'altitudeMeters' => self::float($payload['altitude'] ?? $payload['altitudeMeters'] ?? $gps['height'] ?? null),
            'satelliteCount' => $satelliteCount,
            'gsmSignal' => self::int($payload['gsmSignal'] ?? $gps['GSM'] ?? $firstBaseStation['rxlev'] ?? $firstBaseStation['gsmSignal'] ?? null),
            'mcc' => self::stringOrNull($payload['mcc'] ?? $gps['mcc'] ?? $firstBaseStation['mcc'] ?? null),
            'mnc' => self::stringOrNull($payload['mnc'] ?? $gps['mnc'] ?? $firstBaseStation['mnc'] ?? null),
            'lac' => self::stringOrNull($payload['lac'] ?? $gps['lac'] ?? $firstBaseStation['lac'] ?? null),
            'cellId' => self::stringOrNull($payload['cellId'] ?? $gps['cellId'] ?? $gps['ci'] ?? $firstBaseStation['ci'] ?? $firstBaseStation['cellId'] ?? null),
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
        $configs = isset($payload['configs']) && is_array($payload['configs']) ? $payload['configs'] : null;
        $ack = $payload['configAck'] ?? null;

        return array_filter([
            'status' => 'ok',
            'ack' => is_scalar($ack) && $ack !== '' ? (string)$ack : null,
            'settings' => $configs !== [] ? $configs : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function weather(array $payload): array
    {
        $weather = array_filter([
            'status' => 'ok',
            'summary' => self::stringOrNull($payload['summary'] ?? $payload['weather'] ?? null),
            'weatherType' => self::int($payload['weatherType'] ?? null),
            'reportedAt' => self::stringOrNull($payload['reportedAt'] ?? $payload['reporttime'] ?? $payload['reportTime'] ?? null),
            'temperatureCelsius' => self::float($payload['temperatureCelsius'] ?? $payload['temperature'] ?? $payload['temp'] ?? null),
            'lowCelsius' => self::float($payload['lowCelsius'] ?? $payload['lowTemp'] ?? $payload['lowTemperature'] ?? null),
            'highCelsius' => self::float($payload['highCelsius'] ?? $payload['highTemp'] ?? $payload['highTemperature'] ?? null),
            'humidityPercent' => self::int($payload['humidityPercent'] ?? $payload['humidity'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);

        return count($weather) > 1 ? $weather : ['status' => 'ok'];
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
