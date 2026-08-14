<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\MonitNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * O indice de humidade, publicado na sua propria capacidade `diaper_moisture_level`.
 *
 * O que estes testes protegem nao e a formula em si -- e um numero de apresentacao e pode ser
 * afinado -- mas as duas propriedades de que a app depende:
 *
 *   1. O indice NUNCA contradiz o estado. Uma fralda que precisa de muda nao pode mostrar um
 *      numero menor do que uma que esta seca, porque as duas coisas aparecem lado a lado no
 *      mesmo ecra.
 *   2. As fronteiras das bandas caem nos limiares que decidem o estado, em particular os 40
 *      nos 4 canais molhados. E o valor que desenha a marca de alerta no ecra.
 */
final class MonitMoistureIndexTest extends TestCase
{
    private const DEVICE = [
        'imei' => 'eec5000202f9',
        'supplier' => 'MONIT',
        'model' => 'MECS-PRO',
        'commercialName' => 'MONIT MECS-PRO',
    ];

    private const BASELINE = 10;

    /**
     * @param list<int> $deltas delta por canal; preenchido com zeros ate aos 10 canais
     * @return array<string, mixed>
     */
    private function decoded(array $deltas): array
    {
        $deltas = array_slice(array_pad($deltas, 10, 0), 0, 10);
        $baseline = array_fill(0, 10, self::BASELINE);

        return [
            'mac' => 'eec5000202f9',
            'batteryPercent' => 92,
            'baseline' => $baseline,
            'raw' => array_map(static fn(int $delta): int => self::BASELINE + $delta, $deltas),
            'normalized' => $deltas,
            'raw20' => str_repeat('00', 20),
            'rssiDbm' => -58,
        ];
    }

    /** @param list<int> $deltas @return array<string, array<string, mixed>> */
    private function telemetry(array $deltas): array
    {
        $result = (new MonitNormalizer())->normalize($this->decoded($deltas), self::DEVICE, 'c5e390f30bce');

        return array_map(static fn(array $message): array => $message['data'], $result['telemetry']);
    }

    /** O nivel, na sua propria capacidade. @param list<int> $deltas @return array<string, mixed> */
    private function level(array $deltas): array
    {
        return $this->telemetry($deltas)['diaper_moisture_level'];
    }

    /** Os canais crus, na capacidade do fornecedor. @param list<int> $deltas @return array<string, mixed> */
    private function moisture(array $deltas): array
    {
        return $this->telemetry($deltas)['diaper_moisture'];
    }

    public function testADrySensorReadsZero(): void
    {
        self::assertSame(0, $this->level([])['index']);
        self::assertSame(0, $this->moisture([])['maximumDelta']);
    }

    public function testEveryChannelWetReadsOneHundred(): void
    {
        self::assertSame(100, $this->level(array_fill(0, 10, 12))['index']);
    }

    public function testTheFourthWetChannelLandsExactlyOnTheAlertIndex(): void
    {
        // Quatro canais molhados e o limiar de muda. O indice tem de cair na fronteira da
        // banda, nao um ponto acima nem abaixo, senao a marca de alerta no ecra fica ao lado
        // da mudanca de cor do badge.
        $level = $this->level([12, 12, 12, 12]);

        self::assertSame(40, $level['index']);
        self::assertSame(40, $level['alertIndex']);
        self::assertSame(4, $this->moisture([12, 12, 12, 12])['affectedChannelCount']);
    }

    public function testTheAlertIndexTravelsInThePayload(): void
    {
        // Sem isto a app tinha de escrever o 40 em hardcode, que e uma segunda copia do
        // limiar dos 4 canais -- exactamente o que este calculo veio evitar.
        self::assertSame(40, $this->level([])['alertIndex']);
    }

    public function testACleanSensorNeverReachesTheAlertIndex(): void
    {
        // O pior caso de seco: todos os canais no limite, um passo abaixo de attention.
        $index = $this->level(array_fill(0, 10, 3))['index'];

        self::assertSame(25, $index);
        self::assertLessThan($this->level([])['alertIndex'], $index);
    }

    public function testAnAttentionSensorNeverReachesTheAlertIndex(): void
    {
        // LEITURA REAL do sensor eec5000202f9, apanhada a 2026-08-14T10:27:16Z. Dez canais a
        // rondar o 6, nenhum a chegar aos 12, portanto `attention` -- e a media da 0.43, acima
        // dos 0.40 do limiar. Antes de a banda fechar nos 39 isto dava exactamente 40, em cima
        // da marca de alerta, com o badge ambar ao lado. Foi este payload que apanhou o erro,
        // que nenhum dos testes sinteticos apanhava.
        $level = $this->level([1, 2, 5, 6, 7, 6, 6, 6, 6, 7]);

        self::assertSame(32, $level['index']);
        self::assertSame(0, $this->moisture([1, 2, 5, 6, 7, 6, 6, 6, 6, 7])['affectedChannelCount']);
        self::assertLessThan($level['alertIndex'], $level['index']);
    }

    public function testTheAttentionBandKeepsResolutionInsteadOfPilingAtTheTop(): void
    {
        // O caso extremo de atencao: todos os canais um ponto abaixo de molhado. Tem de dar o
        // topo da banda e nao mais, e tem de ser distinguivel de uma atencao moderada -- se
        // fosse cortado em vez de reescalado, os dois davam o mesmo numero.
        $quaseMolhada = $this->level(array_fill(0, 10, 11))['index'];
        $moderada = $this->level([1, 2, 5, 6, 7, 6, 6, 6, 6, 7])['index'];

        self::assertSame(39, $quaseMolhada);
        self::assertGreaterThan($moderada, $quaseMolhada);
    }

    public function testEveryBandIsOrderedAgainstTheOthers(): void
    {
        // A propriedade global: por muito humida que uma `attention` esteja, nunca lê mais do
        // que a `change_required` mais seca, e nunca menos do que a `clean` mais humida.
        $secoNoLimite = $this->level(array_fill(0, 10, 3))['index'];
        $atencaoNoLimite = $this->level(array_fill(0, 10, 11))['index'];
        $mudaMaisSeca = $this->level([12, 12, 12, 12])['index'];

        self::assertLessThan($atencaoNoLimite, $secoNoLimite);
        self::assertLessThan($mudaMaisSeca, $atencaoNoLimite);
    }

    public function testAWetterSensorNeverReadsLowerThanADrierOne(): void
    {
        // A propriedade que obriga ao clamp. Um unico canal a meio caminho da-lhe `attention`
        // com uma saturacao media baixissima (5), enquanto um seco no limite da 25. Sem o
        // limite inferior da banda o ecra mostrava "5 · Verificar" ao lado de "25 · Limpa".
        $atencaoNumCanal = $this->level([6])['index'];
        $secoNoLimite = $this->level(array_fill(0, 10, 3))['index'];

        self::assertGreaterThanOrEqual($secoNoLimite, $atencaoNumCanal);
    }

    public function testTheIndexRisesWithinTheChangeRequiredBand(): void
    {
        $quatroCanais = $this->level([12, 12, 12, 12])['index'];
        $seteCanais = $this->level(array_fill(0, 7, 12))['index'];

        self::assertSame(70, $seteCanais);
        self::assertGreaterThan($quatroCanais, $seteCanais);
    }

    public function testTheLevelIsItsOwnCapabilityAndNotAFieldOfTheVendorMessage(): void
    {
        // A razao de ser da separacao: os 10 canais capacitivos sao do MONIT MECS-PRO, o nivel
        // nao e de ninguem em particular. Um segundo medidor de fraldas publica `index` sem ter
        // `channels`, e um consumidor do nivel nao pode precisar de ler uma mensagem com forma
        // de MONIT. Se alguem voltar a meter o indice na `diaper_moisture`, isto falha.
        $telemetry = $this->telemetry([12, 12, 12, 12]);

        self::assertSame(
            ['battery', 'diaper_moisture', 'diaper_moisture_level', 'diaper_condition'],
            array_keys($telemetry)
        );
        self::assertSame(['index', 'alertIndex'], array_keys($telemetry['diaper_moisture_level']));
        self::assertArrayNotHasKey('moistureIndex', $telemetry['diaper_moisture']);
        self::assertArrayNotHasKey('index', $telemetry['diaper_moisture']);
    }

    public function testTheConditionMessageStaysFreeOfTheIndex(): void
    {
        // Deliberado, e nao um esquecimento. O hub tira a impressao digital do `data` de cada
        // capacidade e suprime 60s o que nao mudou. O `data` da `diaper_condition` e so o
        // estado, que e estavel -- por isso essa mensagem chega raramente. Meter la um numero
        // que se mexe a cada leitura punha-a a republicar-se sem parar.
        $result = (new MonitNormalizer())->normalize(
            $this->decoded([12, 12, 12, 12]),
            self::DEVICE,
            'c5e390f30bce'
        );

        self::assertSame(['state' => 'change_required'], $result['telemetry']['diaper_condition']['data']);
    }
}
