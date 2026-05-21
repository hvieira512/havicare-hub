<?php

namespace App\Domain;

final class FeaturePayloadFormatter
{
    private static ?array $contracts = null;

    public static function format(string $feature, array $event): array
    {
        $nativePayload = self::nativePayload($event);
        $nativeType = (string)($event['nativeType'] ?? $event['type'] ?? '');
        $normalized = self::normalizedPayload($feature, $event, $nativePayload, $nativeType);

        $dataFields = self::contractFields($feature, 'data');
        $extraFields = self::contractFields($feature, 'extra');

        $data = [];
        $consumed = [];

        foreach ($dataFields as $field => $type) {
            $value = self::extractFieldValue($feature, $field, $normalized, $nativePayload);
            if ($value === null) {
                continue;
            }
            $data[$field] = self::castValue($value, (string)$type);
            $consumed[$field] = true;
            foreach (self::fieldAliases($feature, $field) as $alias) {
                $consumed[$alias] = true;
            }
        }

        // Fallback for status-oriented features when no canonical payload exists.
        if ($data === [] && array_key_exists('status', $dataFields)) {
            $data['status'] = 'ok';
        }

        $extra = [];
        foreach ($extraFields as $field => $type) {
            $value = self::extractExtraFieldValue($feature, $field, $normalized, $nativePayload);
            if ($value === null) {
                continue;
            }
            $extra[$field] = self::castValue($value, (string)$type);
            $consumed[$field] = true;
        }

        foreach ($normalized as $key => $value) {
            if (!is_string($key) || isset($consumed[$key])) {
                continue;
            }
            $extra[$key] = $value;
        }

        // Preserve native payload keys that were not mapped into canonical fields.
        foreach ($nativePayload as $key => $value) {
            if (!is_string($key) || isset($consumed[$key]) || array_key_exists($key, $extra)) {
                continue;
            }
            $extra[$key] = $value;
        }

        return [
            'imei' => (string)($event['imei'] ?? ''),
            'feature' => $feature,
            'timestamp' => self::isoTimestamp($event['receivedAt'] ?? $event['timestamp'] ?? null),
            'data' => $data,
            'extra' => $extra,
        ];
    }

    private static function normalizedPayload(string $feature, array $event, array $nativePayload, string $nativeType): array
    {
        if (isset($event['featureNormalizedData']) && is_array($event['featureNormalizedData'])) {
            return $event['featureNormalizedData'];
        }

        $existing = $event['generalizedData'] ?? null;
        if (is_array($existing) && $existing !== []) {
            return $existing;
        }

        $normalized = EventNormalizer::normalize($feature, $nativeType, $nativePayload);
        return is_array($normalized) ? $normalized : [];
    }

    private static function nativePayload(array $event): array
    {
        $payload = $event['nativeData'] ?? $event['nativePayload'] ?? $event['data'] ?? [];
        return is_array($payload) ? $payload : [];
    }

    private static function extractFieldValue(string $feature, string $field, array $normalized, array $nativePayload): mixed
    {
        if ($feature === 'messaging' && $field === 'kind' && isset($normalized['kind'])) {
            return $normalized['kind'];
        }

        if ($feature === 'ecg' && $field === 'sampleCount') {
            if (isset($normalized['values']) && is_array($normalized['values'])) {
                return count($normalized['values']);
            }

            if (isset($nativePayload['waveform']) && is_array($nativePayload['waveform'])) {
                return count($nativePayload['waveform']);
            }

            $series = $nativePayload['date'] ?? $nativePayload['ecg'] ?? null;
            if (is_string($series) && trim($series) !== '') {
                $parts = preg_split('/[;,]+/', trim($series)) ?: [];
                $parts = array_values(array_filter(array_map('trim', $parts), static fn(string $v): bool => $v !== ''));
                return count($parts);
            }

            if (is_string($nativePayload['fileBase64'] ?? null) && trim((string)$nativePayload['fileBase64']) !== '') {
                return 1;
            }
        }

        if ($feature === 'ppg' && $field === 'sampleCount') {
            if (isset($normalized['sampleCount']) && is_numeric((string)$normalized['sampleCount'])) {
                return (int)$normalized['sampleCount'];
            }

            if (isset($normalized['values']) && is_array($normalized['values'])) {
                return count($normalized['values']);
            }

            $series = $nativePayload['date'] ?? $nativePayload['data'] ?? null;
            if (is_string($series) && trim($series) !== '') {
                $parts = preg_split('/[;,]+/', trim($series)) ?: [];
                $parts = array_values(array_filter(array_map('trim', $parts), static fn(string $v): bool => $v !== ''));
                return count($parts);
            }
        }

        if ($feature === 'rr_interval' && $field === 'intervalMs') {
            if (isset($normalized['intervalMs']) && is_numeric((string)$normalized['intervalMs'])) {
                return (int)$normalized['intervalMs'];
            }

            $raw = $nativePayload['data'] ?? $nativePayload['date'] ?? null;
            if (is_string($raw) && trim($raw) !== '') {
                $pairs = preg_split('/;/', trim($raw)) ?: [];
                foreach ($pairs as $pair) {
                    $bits = array_map('trim', explode(',', $pair));
                    if (count($bits) < 2 || !is_numeric($bits[1])) {
                        continue;
                    }
                    return (int)$bits[1];
                }
            }
        }

        if ($feature === 'sleep' && $field === 'totalMinutes') {
            if (isset($nativePayload['upDayStr']) && !isset($nativePayload['value']) && !isset($nativePayload['dateTime'])) {
                return 0;
            }
        }

        if (array_key_exists($field, $normalized)) {
            return $normalized[$field];
        }

        foreach (self::fieldAliases($feature, $field) as $alias) {
            if (array_key_exists($alias, $normalized)) {
                return $normalized[$alias];
            }
            if (array_key_exists($alias, $nativePayload)) {
                return $nativePayload[$alias];
            }
        }

        if (array_key_exists($field, $nativePayload)) {
            return $nativePayload[$field];
        }

        return null;
    }

    private static function extractExtraFieldValue(string $feature, string $field, array $normalized, array $nativePayload): mixed
    {
        if (array_key_exists($field, $normalized)) {
            return $normalized[$field];
        }

        foreach (self::fieldAliases($feature, $field) as $alias) {
            if (array_key_exists($alias, $normalized)) {
                return $normalized[$alias];
            }
            if (array_key_exists($alias, $nativePayload)) {
                return $nativePayload[$alias];
            }
        }

        // Feature-specific enrichments.
        if ($feature === 'heart_rate' && $field === 'coMeasured') {
            $co = array_merge(
                EventNormalizer::normalize('blood_pressure', '', $nativePayload),
                EventNormalizer::normalize('blood_oxygen', '', $nativePayload),
                EventNormalizer::normalize('blood_sugar', '', $nativePayload)
            );
            return $co !== [] ? $co : null;
        }

        if ($feature === 'battery' && $field === 'charging') {
            if (isset($normalized['chargingState'])) {
                return ((int)$normalized['chargingState']) === 1;
            }
            if (isset($nativePayload['batteryState'])) {
                return ((int)$nativePayload['batteryState']) === 1;
            }
        }

        if ($feature === 'temperature' && $field === 'batteryPercentFromPayload' && isset($normalized['battery'])) {
            return $normalized['battery'];
        }

        return null;
    }

    private static function fieldAliases(string $feature, string $field): array
    {
        $map = [
            'heart_rate' => [
                'bpm' => ['heartRateBpm', 'heartRate', 'hr', 'bpm', 'value', 'date'],
                'seriesBpm' => ['values'],
                'seriesMeasuredAt' => ['dataTime'],
                'measurementMode' => ['testType'],
                'wearPosition' => ['WearPosition', 'wearPosition'],
                'collectionId' => ['collectionLogo'],
                'sampleFrequencyHz' => ['Frequency', 'frequency'],
                'packetState' => ['dataStatus', 'block'],
            ],
            'blood_pressure' => [
                'systolicMmHg' => ['systolicMmHg', 'systolic', 'sbp'],
                'diastolicMmHg' => ['diastolicMmHg', 'diastolic', 'dbp'],
                'pulseBpm' => ['pulseBpm', 'pulse', 'heartRateBpm', 'heartRate'],
            ],
            'blood_oxygen' => [
                'spo2Percent' => ['spo2Percent', 'spo2', 'oxygen'],
            ],
            'blood_fat' => [
                'totalCholesterol' => ['totalCholesterol', 'cholesterol'],
                'hdl' => ['hdl'],
                'ldl' => ['ldl'],
                'triglycerides' => ['triglycerides'],
            ],
            'temperature' => [
                'bodyCelsius' => ['bodyTemperatureC', 'temperature', 'bodyTemperature'],
            ],
            'location' => [
                'lat' => ['latitude', 'lat'],
                'lon' => ['longitude', 'lon', 'lng'],
                'altitudeM' => ['altitudeMeters', 'altitude', 'height'],
                'satelliteCount' => ['satelliteCount', 'satelliteNum', 'satellites'],
                'speedKmh' => ['speedKmh', 'speed'],
                'heading' => ['heading', 'direction'],
                'wifiAccessPoints' => ['wifiAccessPoints', 'wifi'],
                'baseStations' => ['baseStations', 'baseStation'],
                'coordinateSystem' => ['coordinateSystem', 'Type'],
            ],
            'battery' => [
                'percent' => ['batteryPercent', 'battery', 'batteryLevel', 'power', 'electricity'],
                'batteryType' => ['batteryType'],
                'lowPower' => ['lowPower'],
                'derivedFromHeartbeat' => ['derivedFromHeartbeat'],
            ],
            'activity' => [
                'steps' => ['steps', 'step'],
                'distanceKm' => ['distanceKm', 'mileage'],
                'caloriesKcal' => ['caloriesKcal', 'kcal', 'consumed', 'calories'],
            ],
            'respiration' => [
                'rpm' => ['respirationPerMin', 'respiration', 'rr'],
            ],
            'sleep' => [
                'totalMinutes' => ['durationMinutes', 'duration'],
                'deepMinutes' => ['deepMinutes', 'deep'],
                'lightMinutes' => ['lightMinutes', 'light'],
                'remMinutes' => ['remMinutes', 'rem'],
                'awakeMinutes' => ['awakeMinutes', 'awake'],
                'segments' => ['segments', 'dateTime'],
                'cycleStart' => ['cycleStart', 'startTime'],
                'cycleEnd' => ['cycleEnd', 'endTime'],
                'isAccumulative' => ['isAccumulative', 'IsAccumulative'],
            ],
            'heartbeat' => [
                'steps' => ['steps'],
                'rollFrequency' => ['rollFrequency', 'rollsFrequency'],
                'batteryPercent' => ['battery', 'batteryPercent', 'batteryLevel'],
                'satelliteCount' => ['satelliteCount', 'satellites'],
                'gsmSignal' => ['gsmSignal', 'gsm'],
                'fortificationState' => ['fortificationState', 'fortification'],
                'workMode' => ['workMode', 'workingMode'],
            ],
            'ecg' => [
                'sampleCount' => ['sampleCount', 'length'],
                'fileId' => ['fileId', 'collectionLogo'],
                'waveform' => ['waveform', 'values', 'date'],
                'fileBase64' => ['fileBase64', 'ecg', 'audio'],
                'sampleFrequencyHz' => ['sampleFrequencyHz', 'frequency', 'samplingRate'],
                'packetState' => ['packetState', 'dataStatus', 'block'],
            ],
            'ppg' => [
                'sampleCount' => ['sampleCount', 'values', 'date'],
                'waveform' => ['waveform', 'values', 'date'],
                'sampleFrequencyHz' => ['sampleFrequencyHz', 'frequency', 'Frequency'],
                'collectionId' => ['collectionId', 'collectionLogo'],
            ],
            'rr_interval' => [
                'intervalMs' => ['intervalMs', 'value'],
                'series' => ['series', 'values', 'data'],
                'sampleFrequencyHz' => ['sampleFrequencyHz', 'frequency', 'Frequency'],
                'collectionId' => ['collectionId', 'collectionLogo'],
            ],
            'hrv' => [
                'value' => ['value', 'date', 'data'],
                'sampleFrequencyHz' => ['sampleFrequencyHz', 'frequency', 'Frequency'],
                'series' => ['series', 'values'],
                'collectionId' => ['collectionId', 'collectionLogo'],
            ],
            'messaging' => [
                'kind' => ['kind'],
                'sender' => ['sender', 'from'],
                'phone' => ['phone', 'mobile'],
                'text' => ['text', 'content', 'msgContent'],
                'audio' => ['audio', 'bytes'],
                'callLog' => ['callLog'],
                'videoCall' => ['videoCall'],
                'socialSync' => ['socialSync'],
            ],
            'custom' => [
                'type' => ['type', 'vendor'],
                'payload' => ['payload'],
            ],
            'sensors' => [
                'kind' => ['kind'],
                'sensorType' => ['sensorType'],
                'value' => ['value', 'date', 'data'],
                'values' => ['values', 'dataList'],
                'dataTime' => ['dataTime'],
            ],
        ];

        return $map[$feature][$field] ?? [$field];
    }

    private static function castValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => is_numeric($value) ? (int)$value : $value,
            'number' => is_numeric($value) ? (float)$value : $value,
            'boolean' => is_bool($value) ? $value : in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true),
            'string' => is_scalar($value) ? (string)$value : $value,
            'string[]' => self::toArrayOfType($value, 'string'),
            'number[]' => self::toArrayOfType($value, 'number'),
            'object[]' => is_array($value) ? array_values($value) : [],
            'object' => is_array($value) ? $value : [],
            default => $value,
        };
    }

    private static function toArrayOfType(mixed $value, string $scalarType): array
    {
        if (is_string($value)) {
            $parts = preg_split('/[;,]+/', $value) ?: [];
            $value = array_values(array_filter(array_map('trim', $parts), static fn(string $v): bool => $v !== ''));
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if ($scalarType === 'number') {
                if (is_numeric($item)) {
                    $out[] = $item + 0;
                }
                continue;
            }
            if (is_scalar($item)) {
                $out[] = (string)$item;
            }
        }

        return $out;
    }

    private static function isoTimestamp(mixed $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        $value = (int)$timestamp;
        if ($value <= 0) {
            return null;
        }

        if ($value > 1000000000000) {
            $seconds = (int)floor($value / 1000);
            $millis = $value % 1000;
            return gmdate('Y-m-d\\TH:i:s', $seconds) . sprintf('.%03dZ', $millis);
        }

        return gmdate('Y-m-d\\TH:i:s\\Z', $value);
    }

    private static function contractFields(string $feature, string $group): array
    {
        $contracts = self::contracts();
        $featureDef = $contracts['features'][$feature] ?? null;

        if (!is_array($featureDef)) {
            return [];
        }

        $fields = $featureDef[$group] ?? [];
        return is_array($fields) ? $fields : [];
    }

    private static function contracts(): array
    {
        if (self::$contracts !== null) {
            return self::$contracts;
        }

        $path = __DIR__ . '/../../config/feature_contracts.json';
        if (!file_exists($path)) {
            self::$contracts = [];
            return self::$contracts;
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        self::$contracts = is_array($decoded) ? $decoded : [];
        return self::$contracts;
    }
}
