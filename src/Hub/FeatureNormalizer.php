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
            'breath_rate' => self::scalar($payload, 'breathsPerMinute', ['breathRate', 'breathe', 'respiratoryRate', 'value', 'data', 'date']),
            'temperature' => self::temperature($payload),
            'battery' => self::battery($payload),
            'activity' => self::activity($payload),
            'sleep' => self::sleep($payload),
            'ecg' => self::waveform($payload, 'samples'),
            'hrv' => self::scalar($payload, 'milliseconds', ['hrv', 'value', 'data', 'date']),
            'ppg' => self::waveform($payload, 'samples'),
            'rr_interval' => self::rrIntervals($payload),
            'device_state' => self::deviceState($payload),
            'heartbeat' => self::heartbeat($payload),
            'location' => self::location($payload),
            'alarm' => self::alarm($payload),
            'device_config' => self::deviceConfig($payload),
            'weather' => self::weather($payload),
            'firmware_version' => self::firmwareVersion($payload),
            'device_status' => self::deviceStatus($payload),
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
        if ($value === null || !is_numeric((string)$value)) {
            return [];
        }

        return ['glucoseMgDl' => str_contains((string)$value, '.') ? (float)$value : (int)$value];
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
        if ($value === null) {
            return [];
        }
        if (is_string($value) && str_contains($value, '/')) {
            $parts = explode('/', $value);
            return array_filter([
                'bodyCelsius' => self::float($parts[0] ?? null),
                'surfaceCelsius' => self::float($parts[1] ?? null),
                'environmentCelsius' => self::float($parts[2] ?? null),
            ], static fn (mixed $field): bool => $field !== null);
        }

        return is_numeric((string)$value) ? ['bodyCelsius' => (float)$value] : [];
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
            'distanceKm' => self::float($payload['mileage'] ?? null),
            'caloriesKcal' => self::float($payload['caloriesKcal'] ?? $payload['kcal'] ?? $payload['calories'] ?? $payload['consumed'] ?? null),
            'exerciseSeconds' => self::int($payload['exerciseSeconds'] ?? $payload['exerciseTime'] ?? null),
            'standMinutes' => self::int($payload['standMinutes'] ?? $payload['standTime'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function sleep(array $payload): array
    {
        $segments = [];
        $totalDurationMinutes = 0.0;
        $hasDuration = false;
        $timingValid = self::validSleepRange(
            $payload['startTime'] ?? null,
            $payload['endTime'] ?? null,
        );
        $rawSegments = $payload['dateTime'] ?? $payload['dataList'] ?? $payload['segments'] ?? [];
        if (is_array($rawSegments)) {
            foreach ($rawSegments as $segment) {
                if (!is_array($segment)) {
                    continue;
                }

                $duration = self::number($segment['durationMinutes'] ?? $segment['duration'] ?? null);
                $segmentStart = self::validEpochMilliseconds($segment['startTime'] ?? null);
                $segmentEnd = self::validEpochMilliseconds($segment['endTime'] ?? $segment['end time'] ?? null);
                $segmentTimingValid = $segmentStart !== null
                    && $segmentEnd !== null
                    && $segmentEnd >= $segmentStart;
                if ($segmentTimingValid && $duration !== null) {
                    $boundaryDuration = ($segmentEnd - $segmentStart) / 60000;
                    $segmentTimingValid = abs($boundaryDuration - (float)$duration) <= 1.0;
                }
                if ($segmentTimingValid) {
                    $outerStart = self::validEpochMilliseconds($payload['startTime'] ?? null);
                    $outerEnd = self::validEpochMilliseconds($payload['endTime'] ?? null);
                    if ($outerStart !== null && $outerEnd !== null) {
                        $segmentTimingValid = $segmentStart >= $outerStart && $segmentEnd <= $outerEnd;
                    }
                }
                $timingValid = $timingValid && $segmentTimingValid;

                $normalized = array_filter([
                    'startTime' => $segmentTimingValid ? $segmentStart : null,
                    'endTime' => $segmentTimingValid ? $segmentEnd : null,
                    'durationMinutes' => $duration,
                    'type' => self::normalizeSleepType($segment['sleepType'] ?? $segment['sleeptype'] ?? $segment['type'] ?? null),
                ], static fn (mixed $value): bool => $value !== null);
                if ($normalized !== []) {
                    $segments[] = $normalized;
                }
                if ($duration !== null && $duration >= 0) {
                    $totalDurationMinutes += (float)$duration;
                    $hasDuration = true;
                }
            }
        }

        $startTime = $timingValid ? self::validEpochMilliseconds($payload['startTime'] ?? null) : null;
        $endTime = $timingValid ? self::validEpochMilliseconds($payload['endTime'] ?? null) : null;
        $isAccumulative = self::boolLike(self::first($payload, ['isAccumulative', 'IsAccumulative']));

        return array_filter([
            'startTime' => $startTime,
            'endTime' => $endTime,
            'isAccumulative' => $isAccumulative,
            'totalDurationMinutes' => $hasDuration ? self::number($totalDurationMinutes) : null,
            'timingValid' => $timingValid,
            'segments' => $segments !== [] ? $segments : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function validSleepRange(mixed $start, mixed $end): bool
    {
        $start = self::validEpochMilliseconds($start);
        $end = self::validEpochMilliseconds($end);

        return $start !== null && $end !== null && $end >= $start;
    }

    private static function validEpochMilliseconds(mixed $value): ?int
    {
        $timestamp = self::int($value);
        if ($timestamp === null || $timestamp < 946684800000 || $timestamp > 4102444800000) {
            return null;
        }

        return $timestamp;
    }

    private static function normalizeSleepType(mixed $value): ?string
    {
        $raw = strtolower(trim((string)$value));
        $key = str_replace(['_', '-', ' '], '', $raw);

        return match ($key) {
            'deepsleep', 'deep' => 'deep_sleep',
            'lightsleep', 'light' => 'light_sleep',
            'rem' => 'rem',
            'sober', 'awake', 'wake', 'waking' => 'awake',
            default => $raw !== '' ? $raw : null,
        };
    }

    private static function waveform(array $payload, string $field): array
    {
        $raw = self::first($payload, ['data', 'date']);
        $samples = [];
        if (is_string($raw)) {
            foreach (preg_split('/\s*,\s*/', trim($raw)) ?: [] as $sample) {
                if (is_numeric($sample)) {
                    $samples[] = str_contains($sample, '.') ? (float)$sample : (int)$sample;
                }
            }
        }

        return array_filter([
            $field => $samples !== [] ? $samples : null,
            'frequencyHz' => self::int($payload['frequency'] ?? $payload['Frequency'] ?? null),
            'collectionId' => self::stringOrNull($payload['collectionLogo'] ?? null),
            'startedAt' => self::int($payload['dataStartTime'] ?? $payload['Data start time'] ?? null),
            'packetStatus' => self::int($payload['dataStatus'] ?? $payload['Data Status'] ?? null),
            'block' => self::int($payload['block'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function rrIntervals(array $payload): array
    {
        $raw = (string)($payload['data'] ?? $payload['date'] ?? '');
        $intervals = [];
        foreach (explode(';', $raw) as $entry) {
            $parts = array_map('trim', explode(',', $entry));
            if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                continue;
            }
            $intervals[] = ['timestamp' => (int)$parts[0], 'milliseconds' => (int)$parts[1]];
        }

        return array_filter([
            'intervals' => $intervals !== [] ? $intervals : null,
            'frequencyHz' => self::int($payload['frequency'] ?? $payload['Frequency'] ?? null),
            'collectionId' => self::stringOrNull($payload['collectionLogo'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function deviceState(array $payload): array
    {
        return array_filter([
            'state' => self::stringOrNull($payload['state'] ?? null),
            'resetStatus' => self::int($payload['status'] ?? null),
            'reason' => self::stringOrNull($payload['reason'] ?? null),
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
        $radioType = self::normalizeRadioType(
            $payload['radioType']
                ?? $payload['networkType']
                ?? match ((string)($payload['baseStationType'] ?? '')) {
                    '0' => 'lte',
                    '1' => 'cdma',
                    default => null,
                }
        );
        $mcc = self::stringOrNull($payload['mcc'] ?? $gps['mcc'] ?? null);
        $mnc = self::stringOrNull($payload['mnc'] ?? $gps['mnc'] ?? null);
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
        $lat = self::float($payload['lat'] ?? $payload['latitude'] ?? $gps['lat'] ?? $gps['latitude'] ?? null);
        $lon = self::float($payload['lon'] ?? $payload['lng'] ?? $payload['longitude'] ?? $gps['lon'] ?? $gps['lng'] ?? $gps['longitude'] ?? null);
        $gpsValid = isset($payload['gpsValid']) ? (bool)$payload['gpsValid'] : null;
        $satelliteCount = self::int($payload['satellites'] ?? $payload['satelliteCount'] ?? $gps['satelliteNum'] ?? null);
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
            'speedKmh' => self::float($payload['speed'] ?? $payload['speedKmh'] ?? $gps['speed'] ?? null),
            'heading' => self::float($payload['direction'] ?? $payload['heading'] ?? $gps['direction'] ?? null),
            'altitudeMeters' => self::float($payload['altitude'] ?? $payload['altitudeMeters'] ?? $gps['height'] ?? null),
            'satelliteCount' => $satelliteCount,
            'gsmSignal' => self::int($payload['gsmSignal'] ?? $gps['GSM'] ?? $firstBaseStation['rxlev'] ?? $firstBaseStation['gsmSignal'] ?? null),
            'mcc' => $mcc ?? self::stringOrNull($firstBaseStation['mcc'] ?? null),
            'mnc' => $mnc ?? self::stringOrNull($firstBaseStation['mnc'] ?? null),
            'lac' => self::stringOrNull($payload['lac'] ?? $gps['lac'] ?? $firstBaseStation['lac'] ?? null),
            'cellId' => self::stringOrNull($payload['cellId'] ?? $gps['cellId'] ?? $gps['ci'] ?? $firstBaseStation['ci'] ?? $firstBaseStation['cellId'] ?? null),
            'accuracyMeters' => self::float($payload['accuracy'] ?? $payload['accuracyMeters'] ?? null),
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

    private static function alarm(array $payload): array
    {
        $sos = isset($payload['sos']) ? (bool)$payload['sos'] : null;
        $lowBattery = isset($payload['lowBattery']) ? (bool)$payload['lowBattery'] : null;
        $fall = isset($payload['fall']) ? (bool)$payload['fall'] : null;
        $wearingNotice = isset($payload['wearingNotice'])
            ? (bool)$payload['wearingNotice']
            : (isset($payload['removeAlarm']) ? (bool)$payload['removeAlarm'] : null);

        return array_filter([
            'code' => self::normalizeAlarmCode($sos, $lowBattery, $fall, $wearingNotice),
            'sos' => $sos,
            'lowBattery' => $lowBattery,
            'fall' => $fall,
            'wearingNotice' => $wearingNotice,
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

    private static function firmwareVersion(array $payload): array
    {
        return array_filter([
            'version' => self::stringOrNull($payload['firmware'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function deviceStatus(array $payload): array
    {
        return array_filter([
            'deviceTime' => self::stringOrNull($payload['deviceTime'] ?? null),
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

        return count($weather) > 1 ? $weather : [];
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

    private static function number(mixed $value): int|float|null
    {
        $number = self::float($value);
        if ($number === null) {
            return null;
        }

        return floor($number) === $number ? (int)$number : $number;
    }

    private static function boolLike(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric((string)$value)) {
            return (float)$value !== 0.0;
        }

        return match (strtolower(trim((string)$value))) {
            'true', 'yes', 'on', 'enabled' => true,
            'false', 'no', 'off', 'disabled' => false,
            default => null,
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string)$value;
    }

    private static function normalizeAlarmCode(?bool $sos, ?bool $lowBattery, ?bool $fall, ?bool $wearingNotice): ?string
    {
        $flags = array_filter([
            'sos' => $sos === true,
            'low_battery' => $lowBattery === true,
            'fall' => $fall === true,
            'wearing_notice' => $wearingNotice === true,
        ]);

        return count($flags) === 1 ? array_key_first($flags) : null;
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
    ): array
    {
        $normalized = [];
        foreach ($stations as $station) {
            if (!is_array($station)) {
                continue;
            }

            $entry = array_filter([
                'mcc' => self::stringOrNull($station['mcc'] ?? $fallbackMcc),
                'mnc' => self::stringOrNull($station['mnc'] ?? $fallbackMnc),
                'lac' => self::stringOrNull($station['lac'] ?? null),
                'cellId' => self::stringOrNull($station['cellId'] ?? $station['ci'] ?? null),
                'gsmSignal' => self::int($station['gsmSignal'] ?? $station['rxlev'] ?? null),
                'radioType' => self::normalizeRadioType($station['radioType'] ?? $station['networkType'] ?? $fallbackRadioType),
                'signalStrengthDbm' => self::normalizeDbm(
                    $station['signalStrengthDbm'] ?? $station['signalDbm'] ?? $station['rssiDbm'] ?? null,
                    $station['gsmSignal'] ?? $station['rxlev'] ?? null,
                ),
                'sid' => self::int($station['sid'] ?? $station['systemId'] ?? null),
                'nid' => self::int($station['nid'] ?? $station['networkId'] ?? null),
                'bid' => self::int($station['bid'] ?? $station['baseStationId'] ?? null),
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
                'ssid' => self::stringOrNull($point['ssid'] ?? $point['label'] ?? null),
                'mac' => self::normalizeMac($point['mac'] ?? $point['bssid'] ?? null),
                'signal' => self::int($point['signal'] ?? $point['rssi'] ?? $point['gsmSignal'] ?? null),
                'signalStrengthDbm' => self::normalizeDbm(
                    $point['signalStrengthDbm'] ?? $point['signalDbm'] ?? null,
                    $point['signal'] ?? $point['rssi'] ?? null,
                ),
                'channel' => self::int($point['channel'] ?? null),
                'frequencyMhz' => self::int($point['frequencyMhz'] ?? $point['frequency'] ?? null),
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
        $value = self::int($explicit);
        if ($value !== null) {
            return $value < 0 ? $value : null;
        }

        $value = self::int($legacy);
        return $value !== null && $value < 0 ? $value : null;
    }

    private static function normalizeMac(mixed $value): ?string
    {
        $hex = strtolower((string)preg_replace('/[^0-9a-f]/i', '', trim((string)$value)));
        if (strlen($hex) !== 12) {
            return self::stringOrNull($value);
        }

        return implode(':', str_split($hex, 2));
    }
}
