<?php

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Domain\DiaperSensitivity;

final class MonitNormalizer
{
    /**
     * Banda do indice de humidade por estado: [minimo, maximo].
     *
     * As fronteiras nao sao arbitrarias, decorrem dos limiares configurados. Ver
     * buildMoistureIndex.
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

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $device
     * @param array{pollutionRange: int, pollutionValue: int} $sensitivity limiares em vigor para este sensor
     * @return array{telemetry: array<string, array<string, mixed>>, condition: string}
     */
    public function normalize(array $decoded, array $device, string $gatewayId, array $sensitivity): array
    {
        // Obrigatorio e sem valor por omissao de proposito: um chamador que se esqueca
        // de ligar o lookup falha na analise estatica em vez de cair em silencio no
        // preset normal e passar a decidir alarmes com limiares que ninguem escolheu.
        $pollutionValue = $sensitivity['pollutionValue'];
        $pollutionRange = $sensitivity['pollutionRange'];
        $cleanMaxDelta = DiaperSensitivity::cleanMaxDelta($pollutionValue);

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
                    // Os limiares viajam com a leitura pela mesma razao que o
                    // `alertIndex` viaja com o indice: quem mostra "3 de 4 canais
                    // afectados" precisa do 4, e com os limiares configuraveis por
                    // sensor ja nao os pode escrever em hardcode.
                    'requiredChannelCount' => $pollutionRange,
                    'wetDelta' => $pollutionValue,
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
                    'index' => $this->buildMoistureIndex($decoded['normalized'], $condition, $pollutionValue),
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
     * Vive aqui porque e daqui que sai o `condition`: a regra e este ficheiro, os limiares vem
     * da configuracao do sensor, e o vector completo dos 10 canais tambem esta aqui. Calculado
     * a jusante -- na app, a partir do `maximumDelta` e do `affectedChannelCount` -- ficava com
     * as regras duplicadas num segundo repositorio E com menos informacao do que existe aqui.
     *
     * O `alertIndex` viaja no payload pela mesma razao: sem ele, quem desenha a marca de alerta
     * no ecra tinha de escrever o 40 em hardcode, que e outra copia do limiar dos canais.
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
     * Em `clean` a saturacao cai dentro da banda por aritmetica, para QUALQUER valor de molhado
     * configurado, e e exactamente para isso que serve a divisao por 4 no `cleanMaxDelta`:
     *
     *   clean -> todos os deltas <= cleanMaxDelta - 1 = intdiv(valor, 4) <= valor / 4
     *         -> cada termo = min(delta / valor, 1) <= 0.25
     *         -> media <= 0.25  ->  indice <= 25                          ✔ tecto da banda
     *
     * Em `change_required` a aritmetica da `media >= range / 10`, o que com o range 4 do preset
     * normal aterra nos 40 e com um range menor daria menos -- e ai e o `clamp` no fim que o
     * sobe ao piso da banda. Nao e um remendo: o que interessa e o invariante que o ecra ve, e
     * esse mantem-se por construcao em qualquer configuracao alcancavel:
     *
     *   condition == 'clean'           <=> indice <= 25
     *   condition == 'change_required' <=> indice >= alertIndex
     *
     * O custo, dito com clareza em vez de escondido: longe do preset normal o indice comprime-se
     * nos extremos, e varias leituras distintas encostam ao 25 ou ao 40. Perde-se resolucao, nao
     * correcao.
     *
     * Em `attention` a saturacao NAO cai na banda, e e por isso que essa e a unica reescalada.
     * Ali a saturacao vai de quase 0 ate (valor-1)/valor (todos os canais um ponto abaixo de
     * molhado), portanto cortar em 39 empilhava metade do dia no mesmo valor -- e uma fralda em
     * atencao passa la a maior parte do tempo, que e justamente quando o numero tem de se mexer.
     * Reescalada, a leitura real de dez canais a rondar o 6 da 32 em vez de bater no tecto.
     *
     * @param list<int> $deltas delta por canal, ja normalizado contra a linha de base
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
