<?php

namespace Hub\Ingress\Mqtt\Moko;

final class MonitNormalizer
{
    /** Um canal a este delta conta como molhado. */
    private const CHANNEL_WET_DELTA = 12;

    /** Abaixo deste delta em TODOS os canais a fralda esta seca. */
    private const CLEAN_MAX_DELTA = 4;

    /** Tantos canais molhados obrigam a muda. */
    private const CHANGE_AFFECTED_CHANNELS = 4;

    /**
     * Banda do indice de humidade por estado: [minimo, maximo].
     *
     * As fronteiras nao sao arbitrarias, decorrem das regras acima. Ver buildMoistureIndex.
     *
     * O 39 do `attention` e um 39 e nao um 40 DE PROPOSITO: os 40 sao a marca de alerta no
     * ecra e tem de pertencer so ao `change_required`. Uma leitura real de dez canais a rondar
     * o 6 -- nenhum a chegar aos 12, portanto `attention` -- dava media 0.43 e aterrava nos 40
     * em cima da marca, com o badge ambar ao lado a dizer que ainda nao era preciso mudar.
     *
     * @var array<string, array{int, int}>
     */
    private const MOISTURE_INDEX_BANDS = [
        'clean' => [0, 25],
        'attention' => [25, 39],
        'change_required' => [40, 100],
    ];

    /** @param array<string, mixed> $decoded @param array<string, mixed> $device @return array{telemetry: array<string, array<string, mixed>>, condition: string} */
    public function normalize(array $decoded, array $device, string $gatewayId): array
    {
        $occurredAt = gmdate('Y-m-d\TH:i:s\Z');
        $common = [
            'schemaVersion' => 2,
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
        $affected = count(array_filter($decoded['normalized'], static fn(int $value): bool => $value >= self::CHANNEL_WET_DELTA));
        $condition = max($decoded['normalized']) < self::CLEAN_MAX_DELTA
            ? 'clean'
            : ($affected >= self::CHANGE_AFFECTED_CHANNELS ? 'change_required' : 'attention');

        return [
            'condition' => $condition,
            'telemetry' => [
                'battery' => ['type' => 'battery', 'data' => ['percent' => $decoded['batteryPercent']]] + $common,
                'diaper_moisture' => ['type' => 'diaper_moisture', 'data' => [
                    'channels' => $channels,
                    'affectedChannelCount' => $affected,
                    'maximumDelta' => max($decoded['normalized']),
                ]] + $common,
                // Capacidade PROPRIA e nao um campo da `diaper_moisture`, que e detalhe deste
                // fornecedor: os 10 canais capacitivos sao do MONIT MECS-PRO e mais nada. Um
                // segundo medidor de fraldas -- outra marca, outra contagem de canais, ou um
                // que de uma leitura unica -- nao tem `channels` nem `maximumDelta`, mas tem
                // seguramente "quao humido, 0-100". O nivel e o contrato generico; os canais
                // sao o detalhe do decoder. Guardado dentro da mensagem do fornecedor, o
                // segundo modelo obrigava a duplicar o campo ou a que os consumidores lessem
                // uma mensagem com forma de MONIT para tirar um numero que nada tem de MONIT.
                'diaper_moisture_level' => ['type' => 'diaper_moisture_level', 'data' => [
                    'index' => $this->buildMoistureIndex($decoded['normalized'], $condition),
                    'alertIndex' => self::MOISTURE_INDEX_BANDS['change_required'][0],
                ]] + $common,
                'diaper_condition' => ['type' => 'diaper_condition', 'data' => ['state' => $condition]] + $common,
            ],
        ];
    }

    /**
     * Indice 0-100 de quanta humidade o sensor esta a ver, calculado aqui e nao a jusante.
     *
     * NAO E UMA PERCENTAGEM FISICA e nao deve ser apresentada como tal. O sensor mede
     * capacitancia por canal contra uma linha de base de seco; nao ha calibracao para volume,
     * nem referencia de fralda saturada, nem absorvencia por marca. O que isto da e a
     * distancia entre o seco e o limiar de muda, comparavel entre leituras do mesmo sensor.
     *
     * Vive aqui porque e daqui que sai o `condition`: os tres limiares estao neste ficheiro e
     * so neste, e o vector completo dos 10 canais tambem. Calculado a jusante -- na app, a
     * partir do `maximumDelta` e do `affectedChannelCount` -- ficava com os limiares
     * duplicados num segundo repositorio E com menos informacao do que existe aqui.
     *
     * O `alertIndex` viaja no payload pela mesma razao: sem ele, quem desenha a marca de alerta
     * no ecra tinha de escrever o 40 em hardcode, que e outra copia do limiar dos 4 canais.
     *
     * Vai na sua propria capacidade `diaper_moisture_level`, o que tem uma consequencia para
     * quem consome: tendo impressao digital propria, e suprimida em separado da mensagem dos
     * canais. Como o indice e um inteiro grosseiro que muitas vezes nao muda entre leituras,
     * chega MENOS vezes do que a humidade -- ninguem pode assumir que as duas vem juntas.
     *
     * COMO FUNCIONA:
     *
     * A saturacao e a media dos canais, cada um cortado no limiar de molhado -- ou seja
     * "quantos dos 10 canais valem por um canal molhado", em fraccao. Depois entra na banda do
     * estado que as regras acima decidiram, o que garante que o numero e o badge nunca se
     * contradizem no ecra.
     *
     * As bandas sao necessarias porque o estado depende de DUAS estatisticas independentes: o
     * maximo (ha algum sitio molhado?) e a contagem (quao espalhado esta?). Nenhum numero unico
     * e monotono com as duas.
     *
     * Em `clean` e em `change_required` a saturacao ja cai dentro da banda por aritmetica, e
     * `saturacao * 100` e a propria escala -- verifica-se em vez de se supor:
     *
     *   clean            -> todos os deltas <= 3, cada termo <= 3/12, media <= 0.25 -> <= 25
     *   change_required  -> >= 4 canais a 1.0, media >= 4/10                        -> >= 40
     *
     * Em `attention` NAO cai, e e por isso que essa banda e o unico caso reescalado. Ali a
     * saturacao vai de quase 0 ate 11/12 (todos os canais um ponto abaixo de molhado), portanto
     * cortar em 39 empilhava metade do dia no mesmo valor -- e uma fralda em atencao passa lá a
     * maior parte do tempo, que e justamente quando o numero tem de se mexer. Reescalada, a
     * leitura real de dez canais a rondar o 6 da 32 em vez de bater no tecto.
     *
     * @param list<int> $deltas delta por canal, ja normalizado contra a linha de base
     */
    private function buildMoistureIndex(array $deltas, string $condition): int
    {
        if ($deltas === []) {
            return 0;
        }

        $saturation = array_sum(array_map(
            static fn(int $delta): float => min($delta / self::CHANNEL_WET_DELTA, 1.0),
            $deltas
        )) / count($deltas);

        [$floor, $ceiling] = self::MOISTURE_INDEX_BANDS[$condition];

        if ($condition === 'attention') {
            $reachable = (self::CHANNEL_WET_DELTA - 1) / self::CHANNEL_WET_DELTA;
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
