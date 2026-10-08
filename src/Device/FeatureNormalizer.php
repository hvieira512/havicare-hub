<?php

declare(strict_types=1);

namespace Hub\Device;

use Hub\Support\Values;

final class FeatureNormalizer
{
    /** O `batteryType` com que a Wonlex manda o relatório de pouca energia. */
    public const WONLEX_LOW_POWER_REPORT = 3;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
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
            'location' => LocationNormalizer::location($payload),
            'device_config' => self::deviceConfig($payload),
            'firmware_version' => self::firmwareVersion($payload),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function heartRate(array $payload): array
    {
        $value = self::first($payload, ['heartRate', 'heart_rate', 'hr', 'bpm', 'pulse', 'value', 'data', 'date']);
        return $value === null ? [] : ['bpm' => (int)$value];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function bloodPressure(array $payload): array
    {
        $rawData = $payload['data'] ?? $payload['date'] ?? null;
        if (is_string($rawData) && str_contains($rawData, '/')) {
            $parts = preg_split('/[\/,\-]+/', $rawData);
            $payload['systolic'] = $parts[0] ?? null;
            $payload['diastolic'] = $parts[1] ?? null;
            $payload['pulse'] = $parts[2] ?? $payload['pulse'] ?? null;
        }

        return Values::withoutNulls([
            'systolicMmHg' => self::int($payload['systolic'] ?? $payload['systolicMmHg'] ?? $payload['sbp'] ?? null),
            'diastolicMmHg' => self::int($payload['diastolic'] ?? $payload['diastolicMmHg'] ?? $payload['dbp'] ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function bloodOxygen(array $payload): array
    {
        $value = self::first($payload, ['spo2', 'spo2Percent', 'oxygen', 'bloodOxygen', 'bo', 'value', 'data', 'date']);
        return $value === null ? [] : ['spo2Percent' => (int)$value];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function bloodSugar(array $payload): array
    {
        $value = self::first($payload, ['bloodSugar', 'blood_sugar', 'glucoseMgDl', 'bs', 'value', 'data', 'date']);
        if ($value === null || !is_numeric((string)$value)) {
            return [];
        }

        return ['glucoseMgDl' => str_contains((string)$value, '.') ? (float)$value : (int)$value];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function scalar(array $payload, string $field, array $keys): array
    {
        $value = self::first($payload, $keys);
        if ($value === null || !is_numeric((string)$value)) {
            return [];
        }

        return [$field => str_contains((string)$value, '.') ? (float)$value : (int)$value];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function temperature(array $payload): array
    {
        $value = self::first($payload, ['bodyTemperature', 'temperature', 'bodyCelsius', 'temp', 'value', 'data', 'date']);
        if ($value === null) {
            return [];
        }
        if (is_string($value) && str_contains($value, '/')) {
            $parts = explode('/', $value);
            return Values::withoutNulls([
                'bodyCelsius' => self::float($parts[0] ?? null),
                'surfaceCelsius' => self::float($parts[1] ?? null),
                'environmentCelsius' => self::float($parts[2] ?? null),
            ]);
        }

        return is_numeric((string)$value) ? ['bodyCelsius' => (float)$value] : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function battery(array $payload): array
    {
        $value = self::first($payload, ['batteryPercent', 'battery', 'batteryLevel', 'power', 'value']);
        return Values::withoutNulls([
            'percent' => $value === null ? null : (int)$value,
            'chargingState' => self::int($payload['chargingState'] ?? $payload['batteryState'] ?? null),
            'batteryType' => self::int($payload['batteryType'] ?? null),
            'lowBattery' => self::lowBattery($payload),
        ]);
    }

    /**
     * A bandeira comum de bateria fraca: a condição do 4P Touch, ou o relatório de pouca energia
     * da Wonlex. Sem nenhum dos dois, não se sabe.
     *
     * @param array<string, mixed> $payload
     */
    private static function lowBattery(array $payload): ?bool
    {
        if (is_bool($payload['batteryLow'] ?? null)) {
            return $payload['batteryLow'];
        }

        return self::int($payload['batteryType'] ?? null) === self::WONLEX_LOW_POWER_REPORT ? true : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function activity(array $payload): array
    {
        return Values::withoutNulls([
            'steps' => self::int($payload['steps'] ?? $payload['step'] ?? null),
            'distanceMeters' => self::float($payload['distanceMeters'] ?? $payload['distance'] ?? null),
            'distanceKm' => self::float($payload['mileage'] ?? null),
            'caloriesKcal' => self::float($payload['caloriesKcal'] ?? $payload['kcal'] ?? $payload['calories'] ?? $payload['consumed'] ?? null),
            'exerciseSeconds' => self::int($payload['exerciseSeconds'] ?? $payload['exerciseTime'] ?? null),
            'standMinutes' => self::int($payload['standMinutes'] ?? $payload['standTime'] ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
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

                $normalized = Values::withoutNulls([
                    'startTime' => $segmentTimingValid ? self::instantFromMilliseconds($segmentStart) : null,
                    'endTime' => $segmentTimingValid ? self::instantFromMilliseconds($segmentEnd) : null,
                    'durationMinutes' => $duration,
                    'type' => self::normalizeSleepType($segment['sleepType'] ?? $segment['sleeptype'] ?? $segment['type'] ?? null),
                ]);
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

        return Values::withoutNulls([
            'startTime' => self::instantFromMilliseconds($startTime),
            'endTime' => self::instantFromMilliseconds($endTime),
            'isAccumulative' => $isAccumulative,
            'totalDurationMinutes' => $hasDuration ? self::number($totalDurationMinutes) : null,
            'timingValid' => $timingValid,
            'segments' => $segments !== [] ? $segments : null,
        ]);
    }

    private static function validSleepRange(mixed $start, mixed $end): bool
    {
        $start = self::validEpochMilliseconds($start);
        $end = self::validEpochMilliseconds($end);

        return $start !== null && $end !== null && $end >= $start;
    }

    /** O instante como o contrato o mostra, ISO-8601 em UTC; a conta interna fica em milissegundos. */
    private static function instantFromMilliseconds(?int $milliseconds): ?string
    {
        return $milliseconds === null ? null : gmdate('Y-m-d\TH:i:s\Z', intdiv($milliseconds, 1000));
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
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

        return Values::withoutNulls([
            $field => $samples !== [] ? $samples : null,
            'frequencyHz' => self::int($payload['frequency'] ?? $payload['Frequency'] ?? null),
            'collectionId' => self::stringOrNull($payload['collectionLogo'] ?? null),
            'startedAt' => self::int($payload['dataStartTime'] ?? $payload['Data start time'] ?? null),
            'packetStatus' => self::int($payload['dataStatus'] ?? $payload['Data Status'] ?? null),
            'block' => self::int($payload['block'] ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
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

        return Values::withoutNulls([
            'intervals' => $intervals !== [] ? $intervals : null,
            'frequencyHz' => self::int($payload['frequency'] ?? $payload['Frequency'] ?? null),
            'collectionId' => self::stringOrNull($payload['collectionLogo'] ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function deviceState(array $payload): array
    {
        return Values::withoutNulls([
            'state' => self::stringOrNull($payload['state'] ?? null),
            'resetStatus' => self::int($payload['status'] ?? null),
            'reason' => self::stringOrNull($payload['reason'] ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function heartbeat(array $payload): array
    {
        return Values::withoutNulls([
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
        ]);
    }

    /**
     * Os alarmes ativos, cada um já com o tipo do que aconteceu: cada bit da máscara do 4P Touch
     * vira um evento, e máscara a zero devolve lista vazia.
     *
     * @param array<string, mixed> $payload
     * @return list<array{feature: string, value: array<string, mixed>}>
     */
    public static function alarms(array $payload): array
    {
        $active = [
            'sos' => ['help_call', []],
            'lowBattery' => ['low_battery', []],
            'fall' => ['fall', ['confirmed' => true]],
            'wearingNotice' => ['device_removed', []],
            'removeAlarm' => ['device_removed', []],
            'outFenceAlarm' => ['zone_exit', ['zone' => 'geofence']],
            'inFenceAlarm' => ['zone_entry', ['zone' => 'geofence']],
            // O 4P não diz se é alta ou baixa, nem traz o valor.
            'abnormalHeartRateAlarm' => ['heart_rate_abnormal', []],
        ];

        $alarms = [];
        foreach ($active as $flag => [$feature, $value]) {
            if (!empty($payload[$flag])) {
                $alarms[$feature . json_encode($value)] = ['feature' => $feature, 'value' => $value];
            }
        }

        return array_values($alarms);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function deviceConfig(array $payload): array
    {
        $configs = isset($payload['configs']) && is_array($payload['configs']) ? $payload['configs'] : null;
        $ack = $payload['configAck'] ?? null;
        $normalizedAck = is_scalar($ack) && $ack !== '' ? (string)$ack : null;

        return Values::withoutNulls([
            'status' => $normalizedAck === '0' ? 'failed' : 'ok',
            'ack' => $normalizedAck,
            'settings' => $configs !== [] ? $configs : null,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function firmwareVersion(array $payload): array
    {
        return Values::withoutNulls([
            'version' => self::stringOrNull($payload['firmware'] ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private static function first(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== '') {
                return $payload[$key];
            }
        }

        return null;
    }

    /** Público porque o `LocationNormalizer` lê os mesmos campos soltos. */
    public static function int(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric((string)$value) ? null : (int)$value;
    }

    /** Público porque o `LocationNormalizer` lê os mesmos campos soltos. */
    public static function float(mixed $value): ?float
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

    /** Público porque o `LocationNormalizer` lê os mesmos campos soltos. */
    public static function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string)$value;
    }
}
