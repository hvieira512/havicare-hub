<?php

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Domain\DiaperSensitivity;

final class MonitNormalizer
{
    /**
     * Banda do índice de humidade por estado: [mínimo, máximo]. As fronteiras decorrem dos
     * limiares configurados -- ver `buildMoistureIndex`.
     *
     * O 39 do `attention` é um 39 e não um 40 de propósito: os 40 são a marca de alerta no
     * ecrã e têm de pertencer só ao `change_required`.
     *
     * @var array<string, array{int, int}>
     */
    private const MOISTURE_INDEX_BANDS = [
        'clean' => [0, 25],
        'attention' => [25, 39],
        'change_required' => [40, 100],
    ];

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $device
     * @param array{pollutionRange: int, pollutionValue: int} $sensitivity limiares em vigor para este sensor
     * @return array{telemetry: array<string, array<string, mixed>>, condition: string}
     */
    public function normalize(array $decoded, array $device, string $gatewayId, array $sensitivity): array
    {
        // Obrigatório e sem valor por omissão de propósito: um chamador que se esqueça de
        // ligar o lookup falha na análise estática, em vez de decidir alarmes com limiares
        // que ninguém escolheu.
        $pollutionValue = $sensitivity['pollutionValue'];
        $pollutionRange = $sensitivity['pollutionRange'];
        $cleanMaxDelta = DiaperSensitivity::cleanMaxDelta($pollutionValue);

        $occurredAt = gmdate('Y-m-d\TH:i:s\Z');
        $common = [
            'occurredAt' => $occurredAt,
            'device' => $this->device($device),
            'source' => array_filter([
                'protocol' => 'monit-mecs-pro-ble',
                'nativeType' => 'manufacturer_data',
                'gatewayId' => $gatewayId,
                'rssiDbm' => $decoded['rssiDbm'] ?? null,
            ], static fn(mixed $value): bool => $value !== null && $value !== ''),
        ];

        $channels = [];
        foreach ($decoded['baseline'] as $index => $baseline) {
            $channels[] = [
                'index' => $index + 1,
                'baseline' => $baseline,
                'value' => $decoded['raw'][$index],
                'delta' => $decoded['normalized'][$index],
            ];
        }
        $affected = count(array_filter($decoded['normalized'], static fn(int $value): bool => $value >= $pollutionValue));
        $condition = max($decoded['normalized']) < $cleanMaxDelta
            ? 'clean'
            : ($affected >= $pollutionRange ? 'change_required' : 'attention');

        return [
            'condition' => $condition,
            'telemetry' => [
                'battery' => ['type' => 'battery', 'data' => ['percent' => $decoded['batteryPercent']]] + $common,
                'diaper_moisture' => ['type' => 'diaper_moisture', 'data' => [
                    'channels' => $channels,
                    'affectedChannelCount' => $affected,
                    'maximumDelta' => max($decoded['normalized']),
                    // Os limiares viajam com a leitura: quem mostra "3 de 4 canais
                    // afectados" precisa do 4, e com os limiares configuráveis por
                    // sensor não os pode escrever em hardcode.
                    'requiredChannelCount' => $pollutionRange,
                    'wetDelta' => $pollutionValue,
                ]] + $common,
                // Capacidade PRÓPRIA e não um campo da `diaper_moisture`: os 10 canais
                // capacitivos são detalhe do MONIT MECS-PRO, e um segundo medidor de fraldas
                // tem seguramente "quão húmido, 0-100" mas não tem `channels`. O nível é o
                // contrato genérico; os canais são o detalhe do decoder.
                'diaper_moisture_level' => ['type' => 'diaper_moisture_level', 'data' => [
                    'index' => $this->buildMoistureIndex($decoded['normalized'], $condition, $pollutionValue),
                    'alertIndex' => self::MOISTURE_INDEX_BANDS['change_required'][0],
                ]] + $common,
                'diaper_condition' => ['type' => 'diaper_condition', 'data' => ['state' => $condition]] + $common,
            ],
        ];
    }

    /**
     * Índice 0-100 de quanta humidade o sensor está a ver, calculado aqui e não a jusante.
     *
     * NÃO É UMA PERCENTAGEM FÍSICA e não deve ser apresentada como tal. O sensor mede
     * capacitância por canal contra uma linha de base de seco; não há calibração para volume,
     * nem referência de fralda saturada, nem absorvência por marca. O que isto dá é a
     * distância entre o seco e o limiar de muda, comparável entre leituras do mesmo sensor.
     *
     * Vive aqui porque é daqui que sai o `condition`: a regra é este ficheiro, os limiares vêm
     * da configuração do sensor, e o vector completo dos 10 canais também está aqui.
     *
     * A saturação é a média dos canais, cada um cortado no limiar de molhado, e depois entra
     * na banda do estado que as regras acima decidiram -- é isso que garante os dois
     * invariantes que o ecrã vê, provados pelo `MonitMoistureIndexTest` para cada
     * configuração alcançável:
     *
     *   condition == 'clean'           <=> índice <= 25
     *   condition == 'change_required' <=> índice >= alertIndex
     *
     * O `attention` é a única banda reescalada: ali a saturação vai de quase 0 até
     * (valor-1)/valor, e cortar em 39 empilhava metade do dia no mesmo valor -- que é
     * justamente onde uma fralda passa a maior parte do tempo.
     *
     * Vai na capacidade `diaper_moisture_level`, com impressão digital própria, e por isso é
     * suprimida em separado da mensagem dos canais: chega MENOS vezes do que a humidade, e
     * ninguém pode assumir que as duas vêm juntas.
     *
     * @param list<int> $deltas delta por canal, já normalizado contra a linha de base
     */
    private function buildMoistureIndex(array $deltas, string $condition, int $pollutionValue): int
    {
        if ($deltas === []) {
            return 0;
        }

        $saturation = array_sum(array_map(
            static fn(int $delta): float => min($delta / $pollutionValue, 1.0),
            $deltas
        )) / count($deltas);

        [$floor, $ceiling] = self::MOISTURE_INDEX_BANDS[$condition];

        if ($condition === 'attention') {
            $reachable = ($pollutionValue - 1) / $pollutionValue;
            $index = $floor + (int)round(($saturation / $reachable) * ($ceiling - $floor));
        } else {
            $index = (int)round($saturation * 100);
        }

        return max($floor, min($ceiling, $index));
    }

    /** @param array<string, mixed> $device */
    private function device(array $device): array
    {
        return array_filter([
            'id' => (string)$device['imei'],
            'supplier' => (string)($device['supplier'] ?? ''),
            'model' => (string)($device['model'] ?? ''),
            'commercialName' => (string)($device['commercialName'] ?? ''),
        ], static fn(string $value): bool => $value !== '');
    }
}
