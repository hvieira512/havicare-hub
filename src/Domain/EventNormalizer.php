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
            'blood_sugar', 'uric_acid', 'hrv' => self::normalizeScalarMetric($payload),
            'blood_fat' => self::normalizeBloodFat($payload),
            'temperature' => self::normalizeTemperature($payload),
            'location' => self::normalizeLocation($payload),
            'battery' => self::normalizeBattery($payload),
            'activity' => self::normalizeActivity($payload),
            'respiration' => self::normalizeRespiration($payload),
            'rr_interval' => self::normalizeRrInterval($payload),
            'ppg' => self::normalizePpg($payload),
            'sleep' => self::normalizeSleep($payload),
            'messaging' => self::normalizeMessaging($payload),
            'custom' => self::normalizeCustom($payload),
            'sensors' => self::normalizeSensors($payload),
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

    private static function normalizeScalarMetric(array $payload): array
    {
        $value = self::firstScalar($payload, ['value', 'date', 'data']);
        return $value === null || !is_numeric((string)$value)
            ? []
            : ['value' => ((string)$value === (string)(int)$value ? (int)$value : (float)$value)];
    }

    private static function normalizeBloodFat(array $payload): array
    {
        $compound = self::firstScalar($payload, ['date', 'data', 'value']);
        if (!is_string($compound) || trim($compound) === '') {
            return [];
        }

        $parts = self::splitNumericParts($compound);
        if ($parts === []) {
            return [];
        }

        return array_filter([
            'totalCholesterol' => isset($parts[0]) ? (float)$parts[0] : null,
            'hdl' => isset($parts[1]) ? (float)$parts[1] : null,
            'ldl' => isset($parts[2]) ? (float)$parts[2] : null,
            'triglycerides' => isset($parts[3]) ? (float)$parts[3] : null,
        ], static fn($value) => $value !== null);
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
        $baseStations = is_array($payload['baseStations'] ?? null)
            ? $payload['baseStations']
            : (is_array($payload['baseStation'] ?? null) ? $payload['baseStation'] : []);
        $wifiAps = is_array($payload['wifi'] ?? null) ? $payload['wifi'] : [];
        $lat = $payload['lat'] ?? $gps['lat'] ?? $payload['latitude'] ?? null;
        $lon = $payload['lng'] ?? $payload['lon'] ?? $gps['lon'] ?? $payload['longitude'] ?? null;
        $firstBase = (isset($baseStations[0]) && is_array($baseStations[0])) ? $baseStations[0] : [];

        $base = array_filter([
            'latitude' => self::toNullableFloat($lat),
            'longitude' => self::toNullableFloat($lon),
            'altitudeMeters' => self::toNullableInt($gps['height'] ?? $payload['altitude'] ?? null),
            'satelliteCount' => self::toNullableInt($gps['satelliteNum'] ?? $payload['satellites'] ?? null),
            'speedKmh' => self::toNullableFloat($payload['speed'] ?? null),
            'heading' => self::toNullableFloat($payload['direction'] ?? null),
            'gsmSignal' => self::toNullableInt($payload['gsmSignal'] ?? $gps['GSM'] ?? null),
            'wifiAccessPoints' => $wifiAps !== [] ? $wifiAps : null,
            'baseStations' => $baseStations !== [] ? $baseStations : null,
            'mcc' => self::toNullableInt($payload['mcc'] ?? $firstBase['mcc'] ?? null),
            'mnc' => self::toNullableInt($payload['mnc'] ?? $firstBase['mnc'] ?? null),
            'lac' => self::toNullableInt($payload['lac'] ?? $firstBase['lac'] ?? null),
            'cellId' => self::toNullableInt($payload['cellId'] ?? $firstBase['cid'] ?? $firstBase['ci'] ?? null),
            'coordinateSystem' => self::toNullableInt($gps['Type'] ?? null),
            'source' => (self::toNullableFloat($lat) !== null && self::toNullableFloat($lon) !== null)
                ? 'gps'
                : (($wifiAps !== [] || $baseStations !== []) ? 'lbs_wifi' : null),
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
        $steps = $payload['steps'] ?? $payload['step'] ?? null;
        if ($steps === null && is_array($payload['Steps'] ?? null)) {
            $steps = $payload['Steps']['stepNumber'] ?? null;
        }
        $distance = self::toNullableFloat($payload['distance'] ?? $payload['mileage'] ?? null);
        $calories = self::toNullableFloat($payload['calories'] ?? $payload['kcal'] ?? $payload['consumed'] ?? $payload['date'] ?? null);

        if ($steps === null) {
            if ($distance !== null && $distance > 0) {
                $steps = (int)round($distance * 1300);
            } elseif ($calories !== null && $calories > 0) {
                $steps = (int)round($calories * 20);
            } else {
                $exerciseSeconds = self::toNullableInt($payload['exerciseTime'] ?? null);
                if ($exerciseSeconds !== null && $exerciseSeconds > 0) {
                    $steps = (int)round($exerciseSeconds * 1.4);
                }
            }
        }

        return array_filter([
            'steps' => self::toNullableInt($steps),
            'distanceMeters' => $distance,
            'caloriesKcal' => $calories,
        ], static fn($value) => $value !== null);
    }

    private static function normalizeRespiration(array $payload): array
    {
        $value = self::firstScalar($payload, ['rr', 'respiration', 'breathRate', 'value', 'date', 'data']);
        return $value === null ? [] : ['respirationPerMin' => (int)$value];
    }

    private static function normalizeRrInterval(array $payload): array
    {
        $raw = $payload['data'] ?? $payload['date'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $pairs = preg_split('/;/', trim($raw)) ?: [];
        $series = [];
        foreach ($pairs as $pair) {
            $bits = array_map('trim', explode(',', $pair));
            if (count($bits) < 2 || !is_numeric($bits[1])) {
                continue;
            }
            $series[] = array_filter([
                'timestamp' => is_numeric($bits[0] ?? null) ? (int)$bits[0] : null,
                'intervalMs' => (int)$bits[1],
            ], static fn($v) => $v !== null);
        }

        if ($series === []) {
            return [];
        }

        return array_filter([
            'intervalMs' => $series[0]['intervalMs'] ?? null,
            'series' => $series,
            'sampleFrequencyHz' => self::toNullableInt($payload['frequency'] ?? $payload['Frequency'] ?? null),
            'collectionId' => is_scalar($payload['collectionLogo'] ?? null) ? (string)$payload['collectionLogo'] : null,
        ], static fn($value) => $value !== null && $value !== []);
    }

    private static function normalizePpg(array $payload): array
    {
        $raw = $payload['date'] ?? $payload['data'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $values = self::splitNumericParts($raw);
        if ($values === []) {
            return [];
        }

        return array_filter([
            'values' => $values,
            'sampleCount' => count($values),
            'sampleFrequencyHz' => self::toNullableInt($payload['frequency'] ?? $payload['Frequency'] ?? null),
            'collectionId' => is_scalar($payload['collectionLogo'] ?? null) ? (string)$payload['collectionLogo'] : null,
        ], static fn($value) => $value !== null && $value !== []);
    }

    private static function normalizeSleep(array $payload): array
    {
        $dateTime = is_array($payload['dateTime'] ?? null) ? $payload['dateTime'] : [];
        if ($dateTime !== []) {
            $total = 0;
            $deep = 0;
            $light = 0;
            $rem = 0;
            $awake = 0;
            $segments = [];

            foreach ($dateTime as $segment) {
                if (!is_array($segment)) {
                    continue;
                }
                $duration = self::toNullableInt($segment['duration'] ?? null) ?? 0;
                $type = strtolower((string)($segment['sleeptype'] ?? $segment['sleepType'] ?? ''));
                $total += $duration;

                if ($type === 'deepsleep') {
                    $deep += $duration;
                } elseif ($type === 'lightsleep') {
                    $light += $duration;
                } elseif ($type === 'rem') {
                    $rem += $duration;
                } elseif (in_array($type, ['sober', 'awake'], true)) {
                    $awake += $duration;
                }

                $segments[] = array_filter([
                    'startTime' => self::toNullableInt($segment['startTime'] ?? null),
                    'endTime' => self::toNullableInt($segment['endTime'] ?? $segment['end time'] ?? null),
                    'duration' => $duration,
                    'sleepType' => $type,
                ], static fn($v) => $v !== null && $v !== '');
            }

            return array_filter([
                'durationMinutes' => $total > 0 ? $total : null,
                'deepMinutes' => $deep > 0 ? $deep : null,
                'lightMinutes' => $light > 0 ? $light : null,
                'remMinutes' => $rem > 0 ? $rem : null,
                'awakeMinutes' => $awake > 0 ? $awake : null,
                'segments' => $segments,
                'cycleStart' => isset($payload['startTime']) ? (string)$payload['startTime'] : null,
                'cycleEnd' => isset($payload['endTime']) ? (string)$payload['endTime'] : null,
                'isAccumulative' => isset($payload['IsAccumulative']) ? ((int)$payload['IsAccumulative']) === 1 : null,
            ], static fn($value) => $value !== null && $value !== []);
        }

        $compound = self::firstScalar($payload, ['value']);
        if (is_string($compound) && $compound !== '') {
            $parts = self::splitNumericParts($compound);
            if (count($parts) >= 4) {
                return array_filter([
                    'durationMinutes' => (int)$parts[0],
                    'deepMinutes' => (int)$parts[1],
                    'lightMinutes' => (int)$parts[2],
                    'awakeMinutes' => (int)$parts[3],
                ], static fn($value) => $value !== null);
            }
        }

        return array_filter([
            'durationMinutes' => self::toNullableInt($payload['duration'] ?? $payload['minutes'] ?? null),
            'deepMinutes' => self::toNullableInt($payload['deep'] ?? null),
            'lightMinutes' => self::toNullableInt($payload['light'] ?? null),
            'awakeMinutes' => self::toNullableInt($payload['awake'] ?? null),
        ], static fn($value) => $value !== null);
    }

    private static function normalizeMessaging(array $payload): array
    {
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        if ($fields !== [] && !isset($payload['audio']) && !isset($payload['bytes']) && !isset($payload['sequence'])) {
            if (count($fields) >= 5) {
                $payload['recordedAt'] = $payload['recordedAt'] ?? $fields[0];
                $payload['totalPackets'] = $payload['totalPackets'] ?? $fields[1];
                $payload['sequence'] = $payload['sequence'] ?? $fields[2];
                $payload['length'] = $payload['length'] ?? $fields[3];
                $payload['audio'] = $payload['audio'] ?? $fields[4];
            }
        }

        $kind = null;
        if (isset($payload['sender']) || isset($payload['msgContent']) || isset($payload['content'])) {
            $kind = 'sms';
        } elseif (isset($payload['phone']) && (isset($payload['beginTime']) || isset($payload['endTime']) || isset($payload['callType']) || isset($payload['direction']))) {
            $kind = 'call_log';
        } elseif (isset($payload['audio']) || isset($payload['bytes']) || isset($payload['sequence'])) {
            $kind = 'audio_message';
        } elseif (isset($payload['groupId'])) {
            $kind = 'social_sync';
        } elseif (isset($payload['openid']) || isset($payload['status']) || isset($payload['request']) || isset($payload['reason']) || isset($payload['duration'])) {
            $kind = 'video_call';
        }

        return array_filter([
            'kind' => $kind,
            'sender' => is_scalar($payload['sender'] ?? null) ? (string)$payload['sender'] : null,
            'phone' => is_scalar($payload['phone'] ?? null) ? (string)$payload['phone'] : null,
            'text' => is_scalar($payload['content'] ?? ($payload['msgContent'] ?? null)) ? (string)($payload['content'] ?? $payload['msgContent']) : null,
            'audio' => (isset($payload['audio']) || isset($payload['bytes']) || isset($payload['sequence']))
                ? array_filter([
                    'recordedAt' => $payload['recordedAt'] ?? null,
                    'totalPackets' => self::toNullableInt($payload['totalPackets'] ?? null),
                    'sequence' => self::toNullableInt($payload['sequence'] ?? null),
                    'length' => self::toNullableInt($payload['length'] ?? null),
                    'audio' => $payload['audio'] ?? $payload['bytes'] ?? null,
                ], static fn($v) => $v !== null && $v !== [])
                : null,
            'callLog' => isset($payload['callType']) || isset($payload['direction']) || isset($payload['beginTime']) || isset($payload['endTime'])
                ? array_filter([
                    'name' => $payload['name'] ?? null,
                    'phone' => $payload['phone'] ?? null,
                    'beginTime' => self::toNullableInt($payload['beginTime'] ?? null),
                    'endTime' => self::toNullableInt($payload['endTime'] ?? null),
                    'duration' => self::toNullableInt($payload['duration'] ?? null),
                    'waitDuration' => self::toNullableInt($payload['waitDuration'] ?? null),
                    'callType' => self::toNullableInt($payload['callType'] ?? $payload['direction'] ?? null),
                    'isSwitchOn' => self::toNullableInt($payload['isSwitchOn'] ?? null),
                ], static fn($v) => $v !== null && $v !== [])
                : null,
            'videoCall' => (isset($payload['openid']) || isset($payload['callType']) || isset($payload['status']) || isset($payload['request']) || isset($payload['reason']))
                ? array_filter([
                    'openid' => $payload['openid'] ?? null,
                    'callType' => self::toNullableInt($payload['callType'] ?? null),
                    'status' => self::toNullableInt($payload['status'] ?? null),
                    'request' => isset($payload['request']) ? (bool)$payload['request'] : null,
                    'reason' => $payload['reason'] ?? null,
                    'duration' => self::toNullableInt($payload['duration'] ?? null),
                ], static fn($v) => $v !== null && $v !== [])
                : null,
            'socialSync' => isset($payload['groupId'])
                ? array_filter([
                    'groupId' => $payload['groupId'] ?? null,
                    'gps' => is_array($payload['gps'] ?? null) ? $payload['gps'] : null,
                ], static fn($v) => $v !== null && $v !== [])
                : null,
        ], static fn($value) => $value !== null && $value !== []);
    }

    private static function normalizeCustom(array $payload): array
    {
        return [
            'type' => is_scalar($payload['type'] ?? $payload['vendor'] ?? null)
                ? (string)($payload['type'] ?? $payload['vendor'])
                : 'custom',
            'payload' => $payload,
        ];
    }

    private static function normalizeSensors(array $payload): array
    {
        if (is_array($payload['dataList'] ?? null)) {
            return [
                'kind' => 'batch',
                'values' => $payload['dataList'],
                'sensorType' => is_scalar($payload['sensorType'] ?? null) ? (string)$payload['sensorType'] : null,
            ];
        }

        return array_filter([
            'kind' => 'single',
            'sensorType' => is_scalar($payload['sensorType'] ?? null) ? (string)$payload['sensorType'] : null,
            'value' => $payload['date'] ?? $payload['data'] ?? null,
            'dataTime' => $payload['dataTime'] ?? null,
        ], static fn($value) => $value !== null && $value !== '');
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
