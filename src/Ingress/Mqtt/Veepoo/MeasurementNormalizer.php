<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\Support\Values;

/**
 * Traduz uma medição ao vivo da pulseira, pedida por comando, para o tipo e os campos do hub.
 */
final class MeasurementNormalizer
{
    /** O pedido do ECG, que também nomeia a onda: ela chega por `ecg_wave` sem tipo do SDK. */
    public const ECG_OPERATION = 'measure.ecg.start';

    /** Intervalo válido documentado pelo fabricante; fora dele o firmware devolve sentinelas. */
    private const HEART_RATE_MIN = 30;
    private const HEART_RATE_MAX = 250;

    /**
     * O tipo e os campos do hub, ou `null` sem leitura: enquanto mede, o firmware repete a
     * trama com o valor a zero.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: array<string, float|int>}|null
     */
    public static function forSdkType(int $sdkType, array $payload): ?array
    {
        return match ($sdkType) {
            // O fabricante documenta o intervalo válido e manda filtrar o resto: fora dele o
            // firmware devolve sentinelas -- `0` enquanto procura, `1` quando desiste.
            51 => self::withinRange($payload['heartRate'] ?? null, self::HEART_RATE_MIN, self::HEART_RATE_MAX) === null
                ? null
                : ['heart_rate', ['bpm' => (int)$payload['heartRate']]],
            31 => self::withinRange($payload['bloodOxygen'] ?? null, 1, 100) === null
                ? null
                : ['blood_oxygen', ['spo2Percent' => (int)$payload['bloodOxygen']]],
            // A pulseira reporta em mmol/L e o hub publica em mg/dL, tal como no histórico.
            22 => ($glucose = self::positive($payload['bloodGlucose'] ?? null)) === null
                ? null
                : ['blood_sugar', ['glucoseMgDl' => round($glucose * DailyBlockNormalizer::MMOL_PER_L_TO_MG_PER_DL, 1)]],
            6 => ($body = self::positive($payload['bodyTemperature'] ?? null)) === null
                ? null
                : ['temperature', Values::withoutNulls([
                    'bodyCelsius' => round($body, 1),
                    'surfaceCelsius' => ($skin = self::positive($payload['bodySurfaceTemperature'] ?? null)) === null
                        ? null
                        : round($skin, 1),
                ])],
            58 => self::withinRange($payload['pressure'] ?? null, 1, 100) === null
                ? null
                : ['stress', ['score' => (int)$payload['pressure']]],
            18, 28 => self::bloodPressureReading($payload),
            32 => self::bodyComposition($payload),
            9 => self::dailyActivity($payload),
            17 => self::findDeviceState($payload),
            default => null,
        };
    }

    /**
     * O resumo de um ECG pelas tramas de estado, que este firmware não traz o relatório final.
     * Mediana e não média, contra os artefactos; `--` é «sem leitura».
     *
     * @param list<array<string, mixed>> $status
     * @return array<string, int>
     */
    public static function ecgSummary(array $status): array
    {
        // Intervalos plausíveis para um adulto: um QTc acima de 600 ms é erro do algoritmo.
        $fields = [
            'HR2PerMinute' => ['heartRateBpm', self::HEART_RATE_MIN, self::HEART_RATE_MAX, 1],
            'Hrv' => ['hrvMilliseconds', 1, 500, 1],
            'QTC' => ['qtcMilliseconds', 200, 600, 1],
            // Em dezenas de milissegundos, como nos blocos diários.
            'RR1PerSecond' => ['rrIntervalMilliseconds', 24, 200, 10],
        ];

        $out = [];
        foreach ($fields as $source => [$name, $min, $max, $scale]) {
            $values = [];
            foreach ($status as $frame) {
                $value = self::withinRange($frame[$source] ?? null, $min, $max);
                if ($value !== null) {
                    $values[] = (int)$value;
                }
            }

            if ($values !== []) {
                $out[$name] = self::median($values) * $scale;
            }
        }

        return $out;
    }

    /** @param list<int> $values */
    private static function median(array $values): int
    {
        sort($values);

        return $values[intdiv(count($values), 2)];
    }

    /** O pedido que mandou fazer esta medição, pelo tipo do SDK, o único identificador da resposta. */
    public static function operationForSdkType(int $sdkType): ?string
    {
        return match ($sdkType) {
            51 => 'measure.heartRate.start',
            31 => 'measure.oxygen.start',
            22 => 'measure.bloodGlucose.start',
            6 => 'measure.temperature.start',
            58 => 'measure.stress.start',
            18, 28 => 'measure.bloodPressure.start',
            32 => 'measure.bodyComposition.start',
            42 => self::ECG_OPERATION,
            default => null,
        };
    }

    /**
     * O estado da procura da pulseira; `timeout` é ela a desistir sozinha ao fim de um minuto.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: array<string, string>}|null
     */
    private static function findDeviceState(array $payload): ?array
    {
        $state = match ($payload['value'] ?? null) {
            'search' => 'searching',
            'find' => 'stopped',
            'timeout' => 'timed_out',
            default => null,
        };

        return $state === null ? null : ['find_device', ['state' => $state]];
    }

    /**
     * O acumulado do dia desde a meia-noite, como a `activity` dos relógios; as calorias vêm
     * em décimas.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: array<string, float|int>}|null
     */
    private static function dailyActivity(array $payload): ?array
    {
        $steps = $payload['step'] ?? null;
        if (!is_int($steps) || $steps < 0) {
            return null;
        }

        return ['activity', Values::withoutNulls([
            'steps' => $steps,
            'distanceMeters' => is_int($payload['distance'] ?? null) ? $payload['distance'] : null,
            'caloriesKcal' => is_int($payload['calorie'] ?? null) ? round($payload['calorie'] / 10, 1) : null,
        ])];
    }

    /**
     * Composição corporal pelos elétrodos do ECG; `muscleRate` é percentagem, `muscleMass` quilos.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: array<string, float>}|null
     */
    private static function bodyComposition(array $payload): ?array
    {
        $fields = [
            'BMI' => 'bmi',
            'bodyFatPercentage' => 'bodyFatPercent',
            'fatMass' => 'fatMassKg',
            'leanBodyMass' => 'leanMassKg',
            'muscleRate' => 'musclePercent',
            'muscleMass' => 'muscleMassKg',
            'subcutaneousFat' => 'subcutaneousFatPercent',
            'bodyMoisture' => 'bodyWaterPercent',
            'waterContent' => 'waterMassKg',
            'skeletalMuscleRate' => 'skeletalMusclePercent',
            'boneMass' => 'boneMassKg',
            'proportionOfProtein' => 'proteinPercent',
            'proteinAmount' => 'proteinMassKg',
            'basalMetabolicRate' => 'basalMetabolicRateKcal',
        ];

        $data = [];
        foreach ($fields as $source => $target) {
            $value = self::positive($payload[$source] ?? null);
            if ($value !== null) {
                $data[$target] = $value;
            }
        }

        // Enquanto mede, a trama repete-se com tudo a zero. Sem o IMC não há resultado.
        return isset($data['bmi']) ? ['body_composition', $data] : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: array<string, int>}|null
     */
    private static function bloodPressureReading(array $payload): ?array
    {
        $high = self::withinRange($payload['bloodPressureHigh'] ?? null, 1, 300);
        $low = self::withinRange($payload['bloodPressureLow'] ?? null, 1, 300);

        return $high === null || $low === null
            ? null
            : ['blood_pressure', ['systolicMmHg' => (int)$high, 'diastolicMmHg' => (int)$low]];
    }

    /** O valor, ou `null` se não for número ou cair fora do intervalo plausível. */
    private static function withinRange(mixed $value, int $min, int $max): int|float|null
    {
        return (is_int($value) || is_float($value)) && $value >= $min && $value <= $max ? $value : null;
    }

    /** O valor, ou `null` se não for um número acima de zero -- o sentinela de «sem leitura». */
    private static function positive(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        return (float)$value > 0.0 ? (float)$value : null;
    }
}
