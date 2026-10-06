<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\Support\Values;

/**
 * Bloco diário Veepoo: cinco minutos, uma leitura por minuto e grandeza, datado pelo primeiro. «Não
 * medido» é 0xFF num byte, ou 0 onde um ser vivo nunca dá zero; o sono fica de fora, por decifrar.
 */
final class DailyBlockNormalizer
{
    private const NO_DATA = 255;

    /** 1 mmol/L de glicemia equivale a 18,016 mg/dL; público para a medição ao vivo converter igual. */
    public const MMOL_PER_L_TO_MG_PER_DL = 18.016;

    /** De quantos em quantos segundos o bloco guarda um intervalo R-R: cinquenta em trezentos. */
    private const RR_SLOT_SECONDS = 6;

    /** Quanto tempo cobre um bloco. */
    private const BLOCK_SECONDS = 300;

    /**
     * O código de uso que significa «detecção passou»: observado a zero sempre que há leitura
     * ótica, que o fabricante não publica a tabela.
     */
    private const WEAR_OK = 0;

    /** Grandezas por minuto: chave no bloco => [type do hub, campo em data]. */
    private const PER_MINUTE = [
        'pulseReat' => ['heart_rate', 'bpm'],
        'heartReat' => ['heart_rate', 'bpm'],
        'respirationRate' => ['breath_rate', 'breathsPerMinute'],
        'HRVData' => ['hrv', 'milliseconds'],
    ];

    /**
     * @param array<string, mixed> $block   um elemento de `payload` do gateway
     * @param array<string, mixed> $device  identidade já resolvida pelo hub
     * @return list<array<string, mixed>>   envelopes de telemetria, prontos a publicar
     */
    public function normalize(array $block, array $device, string $gatewayId, int $tzOffsetMinutes = 0): array
    {
        $start = self::parseDate((string)($block['date'] ?? ''), $tzOffsetMinutes);
        if ($start === null) {
            return [];
        }

        $at = static fn(int $offsetMinutes): string => gmdate('Y-m-d\TH:i:s\Z', $start + ($offsetMinutes * 60));
        $envelope = static fn(string $type, int $offsetMinutes, array $data): array => [
            'type' => $type,
            'occurredAt' => $at($offsetMinutes),
            'device' => $device,
            'source' => [
                'protocol' => 'veepoo-ble',
                'nativeType' => 'daily_block',
                'gatewayId' => $gatewayId,
            ],
            'data' => $data,
        ];

        $out = [];
        foreach (self::PER_MINUTE as $key => [$type, $field]) {
            foreach (self::readings($block[$key] ?? null) as $minute => $value) {
                $out[] = $envelope($type, $minute, [$field => $value]);
            }
        }

        $pressure = $this->bloodPressure($block['bloodPressure'] ?? null);
        if ($pressure !== null) {
            $out[] = $envelope('blood_pressure', 0, $pressure);
        }

        $intervals = $this->rrIntervals($block['rr50'] ?? null, $start);
        if ($intervals !== []) {
            $out[] = $envelope('rr_interval', 0, ['intervals' => $intervals]);
        }

        // Do objeto do oxigénio, só as leituras por minuto têm tipo no hub.
        $oxygen = $block['bloodOxygen'] ?? null;
        if (is_array($oxygen)) {
            foreach (self::readings($oxygen['oxygens'] ?? null) as $minute => $value) {
                $out[] = $envelope('blood_oxygen', $minute, ['spo2Percent' => $value]);
            }
        }

        // Apneia e carga cardíaca são contagens acumuladas do bloco, com o carimbo do bloco.
        if (is_array($oxygen)) {
            $counters = [
                'sleep_apnea' => ['apneaResults' => 'episodes', 'hypoxiaTimes' => 'hypoxiaSeconds'],
                'cardiac_load' => ['cardiacLoads' => 'value'],
            ];
            foreach ($counters as $type => $fields) {
                $data = [];
                foreach ($fields as $source_ => $target) {
                    $sum = self::sum($oxygen[$source_] ?? null);
                    if ($sum !== null) {
                        $data[$target] = $sum;
                    }
                }
                if ($data !== []) {
                    $out[] = $envelope($type, 0, $data);
                }
            }
        }

        // O stress vem inteiro e o MET com uma casa decimal implícita: 9 são 0,9 MET.
        $scored = [
            'stress' => ['pressure', 'score', 1],
            'met' => ['meiTuo', 'value', 10],
        ];
        foreach ($scored as $type => [$key, $field, $divisor]) {
            foreach (self::readings($block[$key] ?? null) as $minute => $value) {
                $out[] = $envelope($type, $minute, [
                    $field => $divisor === 1 ? $value : round($value / $divisor, 1),
                ]);
            }
        }

        $lipids = $this->bloodLipids($block['bloodLiquid'] ?? null);
        foreach ($lipids as $type => $data) {
            $out[] = $envelope($type, 0, $data);
        }

        $glucose = $this->bloodGlucose($block['bloodGlucose'] ?? null);
        if ($glucose !== null) {
            $out[] = $envelope('blood_sugar', 0, $glucose);
        }

        $temperature = $this->temperature($block['bodyTemperature'] ?? null);
        if ($temperature !== null) {
            $out[] = $envelope('temperature', 0, $temperature);
        }

        $steps = $this->steps($block['step'] ?? null);
        if ($steps !== null) {
            $out[] = $envelope('steps', 0, $steps);
        }

        $wear = $this->wearState($block['step'] ?? null);
        if ($wear !== null) {
            $out[] = $envelope('wear_state', 0, $wear);
        }

        return $out;
    }

    /**
     * Descarta os sentinelas e devolve só os minutos com leitura, indexados pelo minuto.
     *
     * @return array<int, int>
     */
    private static function readings(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $out = [];
        foreach (array_values($values) as $minute => $value) {
            if (is_int($value) && $value > 0 && $value !== self::NO_DATA) {
                $out[$minute] = $value;
            }
        }

        return $out;
    }

    /**
     * Os cinquenta intervalos R-R do bloco, que o firmware conta em dezenas de milissegundos.
     * Sem instante próprio: a posição na lista não diz onde caem no bloco.
     *
     * @return list<array{milliseconds: int}>
     */
    private function rrIntervals(mixed $values, int $start): array
    {
        $out = [];
        foreach (self::readings($values) as $slot => $value) {
            $out[] = [
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $start + ($slot * self::RR_SLOT_SECONDS)),
                'milliseconds' => $value * 10,
            ];
        }

        return $out;
    }

    /**
     * Soma as leituras de um contador do bloco, sem os sentinelas; `null` sem leitura nenhuma,
     * que zero episódios é uma afirmação.
     */
    private static function sum(mixed $values): ?int
    {
        if (!is_array($values)) {
            return null;
        }

        $any = false;
        $total = 0;
        foreach ($values as $value) {
            if (is_int($value) && $value !== self::NO_DATA) {
                $any = true;
                $total += $value;
            }
        }

        return $any ? $total : null;
    }

    /**
     * Lípidos e ácido úrico, que vêm na mesma trama, separados em duas capacidades.
     *
     * @return array<string, array<string, float>>
     */
    private function bloodLipids(mixed $liquid): array
    {
        if (!is_array($liquid)) {
            return [];
        }

        $lipids = Values::withoutNulls([
            'totalCholesterolMmolPerL' => self::decimal($liquid['cholesterol'] ?? null),
            'triglyceridesMmolPerL' => self::decimal($liquid['triacylglycerol'] ?? null),
            'hdlMmolPerL' => self::decimal($liquid['highDensity'] ?? null),
            'ldlMmolPerL' => self::decimal($liquid['lowDensity'] ?? null),
        ]);

        $uric = self::decimal($liquid['uricAcidVal'] ?? null);

        $out = [];
        if ($lipids !== []) {
            $out['blood_lipids'] = $lipids;
        }
        if ($uric !== null) {
            $out['uric_acid'] = ['umolPerL' => $uric];
        }

        return $out;
    }

    /** Aceita texto ou número; zero é ausência de leitura. */
    private static function decimal(mixed $value): ?float
    {
        if (!is_string($value) && !is_float($value) && !is_int($value)) {
            return null;
        }

        $number = (float)$value;

        return $number > 0.0 ? round($number, 2) : null;
    }

    /**
     * Glicemia do bloco, de mmol/L para mg/dL, com o nível de risco 1-3 em enumeração. Vem como
     * objeto com valor e nível, ou como número solto (o MF91).
     *
     * @return array{glucoseMgDl: float, riskLevel?: string}|null
     */
    private function bloodGlucose(mixed $glucose): ?array
    {
        if (!is_array($glucose)) {
            $glucose = ['bloodGlucose' => $glucose];
        }

        $value = $glucose['bloodGlucose'] ?? null;
        if ((!is_float($value) && !is_int($value) && !is_string($value)) || (float)$value <= 0.0) {
            return null;
        }

        $risk = match ($glucose['level'] ?? null) {
            1 => 'low',
            2 => 'medium',
            3 => 'high',
            default => null,
        };

        $data = ['glucoseMgDl' => round((float)$value * self::MMOL_PER_L_TO_MG_PER_DL, 1)];

        return $risk === null ? $data : $data + ['riskLevel' => $risk];
    }

    /**
     * Temperatura corporal e de superfície do bloco, com os nomes dos relógios; vem em texto
     * decimal, e `0.0` é «não medido».
     *
     * @return array{bodyCelsius?: float, surfaceCelsius?: float}|null
     */
    private function temperature(mixed $temperature): ?array
    {
        if (!is_array($temperature)) {
            return null;
        }

        $data = Values::withoutNulls([
            'bodyCelsius' => self::celsius($temperature['bodyTemperature'] ?? null),
            'surfaceCelsius' => self::celsius($temperature['bodySurfaceTemperature'] ?? null),
        ]);

        return $data === [] ? null : $data;
    }

    /** Aceita texto ou número, e trata `0.0` como ausência de leitura. */
    private static function celsius(mixed $value): ?float
    {
        if (!is_string($value) && !is_float($value) && !is_int($value)) {
            return null;
        }

        $celsius = (float)$value;

        return $celsius > 0.0 ? round($celsius, 1) : null;
    }

    /** @return array{systolicMmHg: int, diastolicMmHg: int}|null */
    private function bloodPressure(mixed $pressure): ?array
    {
        if (!is_array($pressure)) {
            return null;
        }

        $high = $pressure['bloodPressureHigh'] ?? 0;
        $low = $pressure['bloodPressureLow'] ?? 0;
        if (!is_int($high) || !is_int($low) || $high <= 0 || $low <= 0 || $high === self::NO_DATA) {
            return null;
        }

        return ['systolicMmHg' => $high, 'diastolicMmHg' => $low];
    }

    /**
     * Passos na janela de cinco minutos, onde zero é leitura. Distância, calorias e
     * `amountOfExercise` ficam de fora: são derivados dos passos ou não têm unidade.
     *
     * @return array{count: int, periodSeconds: int}|null
     */
    private function steps(mixed $step): ?array
    {
        if (!is_array($step) || !is_int($step['stepCount'] ?? null)) {
            return null;
        }

        return ['count' => $step['stepCount'], 'periodSeconds' => self::BLOCK_SECONDS];
    }

    /**
     * Se a pulseira estava a ser usada durante o bloco; sai em cada bloco, mesmo igual ao anterior.
     *
     * @return array{state: string}|null
     */
    private function wearState(mixed $step): ?array
    {
        if (!is_array($step) || !is_int($step['wear'] ?? null)) {
            return null;
        }

        return ['state' => $step['wear'] === self::WEAR_OK ? 'worn' : 'not_worn'];
    }

    /**
     * O firmware data os blocos como `YYYY-MM-DD-HH-mm` na hora local do gateway, sem fuso; o
     * desvio chega na mensagem e é subtraído aqui.
     */
    private static function parseDate(string $date, int $tzOffsetMinutes): ?int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return null;
        }

        $local = gmmktime((int)$m[4], (int)$m[5], 0, (int)$m[2], (int)$m[3], (int)$m[1]);

        return $local === false ? null : $local - ($tzOffsetMinutes * 60);
    }
}
