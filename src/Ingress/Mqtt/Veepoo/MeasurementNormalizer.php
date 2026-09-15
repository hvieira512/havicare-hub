<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

/**
 * Traduz uma medição ao vivo da pulseira para o tipo e os campos do hub.
 *
 * Saiu da `Bridge` porque é uma tabela de tradução e mais nada: o tipo do SDK diz qual é a
 * grandeza, e cada grandeza diz o que conta como leitura válida. Não conhece MQTT, nem Redis,
 * nem gateway -- e por isso testa-se directamente, sem levantar um subscritor.
 *
 * A `DailyBlockNormalizer` faz o mesmo trabalho para o histórico que a pulseira reproduz; esta
 * faz o das medições pedidas por comando.
 */
final class MeasurementNormalizer
{
    /**
     * O pedido do ECG, que a onda também precisa de nomear: ela chega por `ecg_wave` e não
     * traz tipo do SDK nenhum, mas quem a mandou fazer é sempre este.
     */
    public const ECG_OPERATION = 'measure.ecg.start';

    /** Intervalo válido documentado pelo fabricante; fora dele o firmware devolve sentinelas. */
    private const HEART_RATE_MIN = 30;
    private const HEART_RATE_MAX = 250;

    /**
     * Traduz uma medição ao vivo para o tipo e os campos do hub, ou `null` se não houver
     * leitura que publicar.
     *
     * O tipo do SDK é que diz qual é a grandeza -- 51 frequência cardíaca, 31 oxigénio, 22
     * glicemia, 6 temperatura, 58 stress, 18 e 28 tensão. Enquanto a medição decorre o
     * firmware repete a mesma trama com o valor a zero, e por isso cada grandeza tem de dizer
     * o que é uma leitura válida: publicar o zero dava uma saturação de 0% a meio de uma
     * medição que estava a correr bem.
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
                : ['temperature', array_filter([
                    'bodyCelsius' => round($body, 1),
                    'surfaceCelsius' => ($skin = self::positive($payload['bodySurfaceTemperature'] ?? null)) === null
                        ? null
                        : round($skin, 1),
                ], static fn(mixed $v): bool => $v !== null)],
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
     * O pedido que mandou fazer esta medição, pelo tipo do SDK.
     *
     * É a tabela do `forSdkType` vista do outro lado, e vive ao lado dela pela mesma razão: o
     * tipo do SDK é o único identificador que a resposta traz, e sem ele uma medição que falha
     * não sabe dizer qual dos pedidos em fila é que morreu com ela.
     *
     * Só as medições. Os totais do dia e a procura da pulseira respondem sempre, e por isso
     * nunca precisam de ser encerradas por falha.
     */
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
     * O estado de quem manda a pulseira vibrar.
     *
     * `timeout` é ela a desistir sozinha ao fim de cerca de um minuto, e é a única maneira de
     * saber que parou sem ninguém lhe ter pedido.
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
     * O acumulado do dia, contado pela própria pulseira.
     *
     * É o mesmo que `activity` significa nos relógios -- o contador de passos, distância e
     * calorias desde a meia-noite -- e por isso leva o mesmo nome. O que se andou em cada
     * cinco minutos é outra grandeza e sai dos blocos como `steps`.
     *
     * As calorias vêm em décimas: 146 são as 14,6 kcal que a app mostra no ecrã principal.
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

        return ['activity', array_filter([
            'steps' => $steps,
            'distanceMeters' => is_int($payload['distance'] ?? null) ? $payload['distance'] : null,
            'caloriesKcal' => is_int($payload['calorie'] ?? null) ? round($payload['calorie'] / 10, 1) : null,
        ], static fn(mixed $v): bool => $v !== null)];
    }

    /**
     * Composição corporal, medida pelos elétrodos do ECG.
     *
     * Os nomes do fabricante não distinguem percentagem de quilos -- `muscleRate` e
     * `muscleMass` são a mesma palavra com sufixos que não dizem a unidade. Os do hub dizem.
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

    /** @param array<string, mixed> $payload @return array{0: string, 1: array<string, int>}|null */
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
