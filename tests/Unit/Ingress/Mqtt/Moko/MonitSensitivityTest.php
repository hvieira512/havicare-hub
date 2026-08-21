<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Domain\DiaperSensitivity;
use Hub\Ingress\Mqtt\Moko\MonitNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * A sensibilidade por sensor: os dois limiares que a app da MONIT expoe.
 *
 * O `MonitMoistureIndexTest` cobre o preset normal em detalhe e nenhuma das suas asseroes
 * mudou quando isto passou a ser configuravel. O que este ficheiro protege e o que sai da
 * configuracao: que o preset normal reproduz os limiares antigos, que os invariantes do
 * ecra sobrevivem a QUALQUER configuracao alcancavel, e que uma configuracao mais sensivel
 * nunca produz um estado menos grave para a mesma leitura.
 */
final class MonitSensitivityTest extends TestCase
{
    private const DEVICE = [
        'imei' => 'eec5000202f9',
        'supplier' => 'MONIT',
        'model' => 'MECS-PRO',
        'commercialName' => 'MONIT MECS-PRO',
    ];

    private const BASELINE = 10;

    /** Gravidade crescente, para comparar estados entre configuracoes. */
    private const SEVERITY = ['clean' => 0, 'attention' => 1, 'change_required' => 2];

    /**
     * Vectores de canais que cobrem as fronteiras: seco, saturado, um canal quente, e a
     * leitura real capturada do sensor em producao.
     *
     * @var list<list<int>>
     */
    private const VECTORS = [
        [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [63, 63, 63, 63, 63, 63, 63, 63, 63, 63],
        [25, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [12, 12, 12, 0, 0, 0, 0, 0, 0, 0],
        [12, 12, 12, 12, 0, 0, 0, 0, 0, 0],
        [1, 3, 5, 7, 8, 7, 7, 7, 7, 8],
    ];

    /** @param list<int> $deltas @return array<string, mixed> */
    private function decoded(array $deltas): array
    {
        $deltas = array_slice(array_pad($deltas, 10, 0), 0, 10);

        return [
            'mac' => 'eec5000202f9',
            'batteryPercent' => 92,
            'baseline' => array_fill(0, 10, self::BASELINE),
            'raw' => array_map(static fn(int $delta): int => self::BASELINE + $delta, $deltas),
            'normalized' => $deltas,
            'raw20' => str_repeat('00', 20),
            'rssiDbm' => -58,
        ];
    }

    /**
     * @param list<int> $deltas
     * @return array{condition: string, index: int, alertIndex: int, required: int, wet: int}
     */
    private function read(array $deltas, int $pollutionRange, int $pollutionValue): array
    {
        $result = (new MonitNormalizer())->normalize(
            $this->decoded($deltas),
            self::DEVICE,
            'c5e390f30bce',
            ['pollutionRange' => $pollutionRange, 'pollutionValue' => $pollutionValue],
        );
        $level = $result['telemetry']['diaper_moisture_level']['data'];
        $moisture = $result['telemetry']['diaper_moisture']['data'];

        return [
            'condition' => $result['condition'],
            'index' => (int)$level['index'],
            'alertIndex' => (int)$level['alertIndex'],
            'required' => (int)$moisture['requiredChannelCount'],
            'wet' => (int)$moisture['wetDelta'],
        ];
    }

    public function testTheNormalPresetIsWhatTheHubHadHardcoded(): void
    {
        // O 4 e o 12 estavam em constantes no normalizador, e sao exactamente o preset
        // "Normal Diaper Alerts" da app da MONIT. O terceiro limiar, o que separa `clean` de
        // `attention`, era um 4 absoluto -- e a formula derivada tem de o reproduzir, senao
        // todos os sensores em producao mudavam de comportamento em silencio.
        self::assertSame(['pollutionRange' => 4, 'pollutionValue' => 12], DiaperSensitivity::normal());
        self::assertSame(4, DiaperSensitivity::cleanMaxDelta(12));
    }

    public function testTheCleanThresholdFollowsTheWetThreshold(): void
    {
        // Proporcional e nao absoluto: com o valor de molhado a 25, um delta de 4 nao pode
        // tirar a fralda de `clean` faltando-lhe 21 para contar como molhada.
        self::assertSame(2, DiaperSensitivity::cleanMaxDelta(5));
        self::assertSame(2, DiaperSensitivity::cleanMaxDelta(7));
        self::assertSame(4, DiaperSensitivity::cleanMaxDelta(15));
        self::assertSame(7, DiaperSensitivity::cleanMaxDelta(25));
    }

    public function testTheSameReadingChangesConditionAcrossPresets(): void
    {
        // A razao de ser da feature. Tres canais a 13 acima da linha de base: no preset
        // normal e `attention`, e com "mais alertas" passa a exigir muda. A leitura fisica
        // e identica; o que muda e a regra.
        $deltas = [13, 13, 13, 0, 0, 0, 0, 0, 0, 0];

        self::assertSame('change_required', $this->read($deltas, 3, 7)['condition']);
        self::assertSame('attention', $this->read($deltas, 4, 12)['condition']);
        self::assertSame('attention', $this->read($deltas, 7, 15)['condition']);
    }

    public function testTheThresholdsTravelWithTheReading(): void
    {
        // Quem mostra "3 de 4 canais afectados" precisa do 4, e com os limiares
        // configuraveis por sensor ja nao os pode escrever em hardcode.
        $reading = $this->read([12, 12, 12, 0, 0, 0, 0, 0, 0, 0], 3, 7);

        self::assertSame(3, $reading['required']);
        self::assertSame(7, $reading['wet']);
    }

    public function testTheScreenInvariantsHoldForEverySetting(): void
    {
        // A propriedade que importa, varrida sobre todas as configuracoes alcancaveis pela
        // API. O numero e o badge aparecem lado a lado no mesmo ecra, e nunca se podem
        // contradizer -- foi para isso que as bandas do indice existem, e e isto que garante
        // que continuam a servir depois de os limiares deixarem de ser constantes.
        [$rangeMin, $rangeMax] = DiaperSensitivity::RANGE_BOUNDS;
        [$valueMin, $valueMax] = DiaperSensitivity::VALUE_BOUNDS;
        $checked = 0;

        for ($range = $rangeMin; $range <= $rangeMax; $range++) {
            for ($value = $valueMin; $value <= $valueMax; $value++) {
                foreach (self::VECTORS as $deltas) {
                    $reading = $this->read($deltas, $range, $value);
                    $where = "range={$range} value={$value} deltas=" . implode(',', $deltas);

                    self::assertGreaterThanOrEqual(0, $reading['index'], $where);
                    self::assertLessThanOrEqual(100, $reading['index'], $where);

                    if ($reading['condition'] === 'clean') {
                        self::assertLessThanOrEqual(25, $reading['index'], $where);
                    }
                    if ($reading['condition'] === 'change_required') {
                        self::assertGreaterThanOrEqual($reading['alertIndex'], $reading['index'], $where);
                    }
                    if ($reading['condition'] === 'attention') {
                        self::assertGreaterThanOrEqual(25, $reading['index'], $where);
                        self::assertLessThan($reading['alertIndex'], $reading['index'], $where);
                    }
                    $checked++;
                }
            }
        }

        self::assertSame(
            ($rangeMax - $rangeMin + 1) * ($valueMax - $valueMin + 1) * count(self::VECTORS),
            $checked
        );
    }

    public function testALowerThresholdIsNeverLessSevere(): void
    {
        // Monotonia, que e o que faz os presets significarem o que dizem. Baixar o numero de
        // canais exigidos, ou baixar o delta que conta como molhado, so pode manter ou agravar
        // o estado da mesma leitura. Se algum dia isto se invertesse, "mais alertas" passava a
        // dar menos alertas do que "normal" para alguma leitura -- e ninguem descobria olhando
        // para um caso de cada vez.
        [$rangeMin, $rangeMax] = DiaperSensitivity::RANGE_BOUNDS;
        [$valueMin, $valueMax] = DiaperSensitivity::VALUE_BOUNDS;

        foreach (self::VECTORS as $deltas) {
            for ($range = $rangeMin; $range <= $rangeMax; $range++) {
                for ($value = $valueMin; $value <= $valueMax; $value++) {
                    $here = self::SEVERITY[$this->read($deltas, $range, $value)['condition']];
                    $where = "range={$range} value={$value} deltas=" . implode(',', $deltas);

                    if ($range > $rangeMin) {
                        $looser = self::SEVERITY[$this->read($deltas, $range - 1, $value)['condition']];
                        self::assertGreaterThanOrEqual($here, $looser, "range menor menos grave: {$where}");
                    }
                    if ($value > $valueMin) {
                        $looser = self::SEVERITY[$this->read($deltas, $range, $value - 1)['condition']];
                        self::assertGreaterThanOrEqual($here, $looser, "value menor menos grave: {$where}");
                    }
                }
            }
        }
    }

    public function testTheProfileNameIsDerivedFromTheValues(): void
    {
        self::assertSame('high', DiaperSensitivity::profile(3, 7));
        self::assertSame('normal', DiaperSensitivity::profile(4, 12));
        self::assertSame('low', DiaperSensitivity::profile(7, 15));
        self::assertSame('custom', DiaperSensitivity::profile(5, 9));
    }

    public function testValidationRejectsValuesOutsideTheVendorRanges(): void
    {
        self::assertNull(DiaperSensitivity::validate(4, 12));
        self::assertNull(DiaperSensitivity::validate(2, 5));
        self::assertNull(DiaperSensitivity::validate(10, 25));
        self::assertNotNull(DiaperSensitivity::validate(1, 12));
        self::assertNotNull(DiaperSensitivity::validate(11, 12));
        self::assertNotNull(DiaperSensitivity::validate(4, 4));
        self::assertNotNull(DiaperSensitivity::validate(4, 26));
    }

    public function testTheGradesMatchTheVendorApp(): void
    {
        self::assertSame('sensitive', DiaperSensitivity::grade(DiaperSensitivity::RANGE_GRADES, 3));
        self::assertSame('normal', DiaperSensitivity::grade(DiaperSensitivity::RANGE_GRADES, 4));
        self::assertSame('insensitive', DiaperSensitivity::grade(DiaperSensitivity::RANGE_GRADES, 7));
        self::assertSame('sensitive', DiaperSensitivity::grade(DiaperSensitivity::VALUE_GRADES, 7));
        self::assertSame('normal', DiaperSensitivity::grade(DiaperSensitivity::VALUE_GRADES, 12));
        self::assertSame('insensitive', DiaperSensitivity::grade(DiaperSensitivity::VALUE_GRADES, 17));
    }
}
