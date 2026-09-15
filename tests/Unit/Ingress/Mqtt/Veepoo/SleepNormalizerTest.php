<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Ingress\Mqtt\Veepoo\SleepNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * O registo de sono preciso, que a pulseira guarda três dias e reproduz quando lhe pedem.
 *
 * São duas coisas na mesma trama e saem como duas capacidades: o `sleep` é a noite — quando
 * começou, quando acabou, e o que aconteceu pelo meio —, e o `sleep_quality` são as
 * pontuações que o firmware calcula sobre ela. Juntá-las num tipo só obrigava quem integra a
 * distinguir uma medição de um juízo sobre ela.
 *
 * Os significados vêm da documentação do fabricante (secção 9.4 do `VeepooUniAppSDK`), e não
 * dos nomes dos campos: `nightScore` é a pontuação das idas à casa de banho, `insomniaCount`
 * é o número de despertares, e `sleepQuality` é 0-4 onde a app do fabricante mostra 1-5.
 */
final class SleepNormalizerTest extends TestCase
{
    private const DEVICE = ['id' => '9f69c4866e6c', 'supplier' => 'Wonlex', 'model' => 'MF91'];
    private const GATEWAY = 'bef341903987';

    /** 2026-09-15 12:00:00Z, o instante a que o gateway leu o registo. */
    private const NOW = 1789473600;

    /**
     * A noite inteira: começo, fim, duração e o que se passou em cada troço.
     *
     * A curva é o que dá os troços. Sem ela ficava um total sem forma — nove horas de sono
     * não dizem se foram nove horas seguidas ou nove despertares.
     */
    public function testTheNightBecomesOneSleepRecord(): void
    {
        $out = self::sleep(self::night());
        self::assertNotNull($out, 'a noite tem de produzir um registo de sono');

        self::assertSame('sleep', $out['type']);
        self::assertSame('2026-09-14T23:10:00Z', gmdate('Y-m-d\TH:i:s\Z', (int)($out['data']['startTime'] / 1000)));
        self::assertSame('2026-09-15T07:30:00Z', gmdate('Y-m-d\TH:i:s\Z', (int)($out['data']['endTime'] / 1000)));
        self::assertTrue($out['data']['timingValid']);
        self::assertSame(480, $out['data']['totalDurationMinutes']);
    }

    /**
     * Os valores da curva são os do fabricante: 0 sono profundo, 1 leve, 2 REM, 3 insónia,
     * 4 acordado. Os nomes do hub são os que os relógios já usam para o mesmo.
     */
    public function testTheCurveBecomesSegmentsWithTheContractTypes(): void
    {
        $out = self::sleep(self::night(curve: '00112234'));
        $types = array_column($out['data']['segments'], 'type');

        self::assertSame(['deep_sleep', 'light_sleep', 'rem', 'insomnia', 'awake'], $types);
    }

    /** Troços seguidos do mesmo tipo são um troço só, com a duração somada. */
    public function testConsecutiveSlotsOfTheSameTypeAreOneSegment(): void
    {
        $out = self::sleep(self::night(curve: '000011'));
        $segments = $out['data']['segments'];

        self::assertCount(2, $segments);
        self::assertSame('deep_sleep', $segments[0]['type']);
        self::assertSame(4, $segments[0]['slots'] ?? 4);
        self::assertSame($segments[0]['endTime'], $segments[1]['startTime'], 'não há buracos entre troços');
        self::assertSame($out['data']['startTime'], $segments[0]['startTime']);
        self::assertSame($out['data']['endTime'], $segments[1]['endTime']);
    }

    /**
     * Sem curva, a noite continua a valer: o firmware dá os totais de cada fase à parte, e
     * eles chegam para saber quanto se dormiu de cada maneira, mesmo sem saber quando.
     */
    public function testWithoutACurveTheTotalsStillBecomeSegments(): void
    {
        $out = self::sleep(self::night(curve: null));
        $segments = $out['data']['segments'];

        self::assertSame(
            [['type' => 'deep_sleep', 'durationMinutes' => 120],
             ['type' => 'light_sleep', 'durationMinutes' => 330],
             ['type' => 'awake', 'durationMinutes' => 30]],
            $segments,
        );
    }

    /**
     * Instantes que não fazem sentido são removidos, e o registo diz que não são de confiar.
     *
     * É a mesma regra dos relógios: uma noite com durações certas e instantes errados ainda
     * serve para contar horas de sono; instantes errados apresentados como certos não servem
     * para nada.
     */
    public function testImpossibleInstantsAreDroppedAndFlagged(): void
    {
        $out = self::sleep(self::night(fallAsleep: '25-09-14-23', exitSleep: '25-09-15-07'));

        self::assertFalse($out['data']['timingValid']);
        self::assertArrayNotHasKey('startTime', $out['data']);
        self::assertArrayNotHasKey('endTime', $out['data']);
        self::assertSame(480, $out['data']['totalDurationMinutes'], 'as durações continuam de pé');
    }

    /** E sem instantes não há como datar os troços: ficam as durações de cada fase. */
    public function testWithoutInstantsTheSegmentsFallBackToTotals(): void
    {
        $out = self::sleep(self::night(fallAsleep: '25-09-14-23', exitSleep: '25-09-15-07'));

        self::assertSame(
            ['deep_sleep', 'light_sleep', 'awake'],
            array_column($out['data']['segments'], 'type'),
        );
        self::assertArrayNotHasKey('startTime', $out['data']['segments'][0]);
    }

    /**
     * As pontuações saem à parte, e com os nomes do que medem.
     *
     * `nightScore` é 起夜得分 — a pontuação das idas à casa de banho — e não a pontuação da
     * noite, que é o que o nome do fabricante faz parecer. `sleepQuality` vem 0-4 e a app
     * mostra 1-5 estrelas: o nome do hub leva a escala para ninguém ter de a adivinhar.
     */
    public function testTheScoresBecomeTheirOwnCapability(): void
    {
        $out = self::ofType(SleepNormalizer::normalize(self::night(), self::DEVICE, self::GATEWAY, self::NOW), 'sleep_quality');

        self::assertSame([
            'qualityStars' => 4,
            'deepSleepScore' => 82,
            'efficiencyScore' => 75,
            'fallAsleepScore' => 90,
            'durationScore' => 70,
            'nightWakingScore' => 88,
            'insomniaScore' => 65,
            'awakeningCount' => 2,
            'firstDeepSleepMinutes' => 22,
            'nightAwakeMinutes' => 18,
            'returnToDeepSleepMeanMinutes' => 6,
        ], $out['data']);
    }

    /** Uma trama sem nada dentro não é uma noite de zero horas: não é noite nenhuma. */
    public function testAnEmptyRecordProducesNothing(): void
    {
        self::assertSame([], SleepNormalizer::normalize([], self::DEVICE, self::GATEWAY, self::NOW));
    }

    /**
     * O envelope diz de onde veio, como o dos blocos.
     *
     * `nativeType` é `precise_sleep` e não `sleep`: é por esse nome que se vai à secção 9.4
     * da documentação do fabricante, e distingue-o do sono que os blocos de cinco minutos
     * codificam e que continua por decifrar.
     */
    public function testTheEnvelopeNamesTheManufacturerRecord(): void
    {
        $out = self::sleep(self::night());

        self::assertSame('veepoo-ble', $out['source']['protocol']);
        self::assertSame('precise_sleep', $out['source']['nativeType']);
        self::assertSame(self::GATEWAY, $out['source']['gatewayId']);
        self::assertSame(self::DEVICE, $out['device']);
    }

    /**
     * Uma noite que atravessa a passagem de ano continua a ser a noite anterior.
     *
     * O firmware datou o registo com mês e dia e mais nada. Tomar sempre o ano corrente
     * punha o sono de 31 de dezembro onze meses no futuro, em janeiro.
     */
    public function testANightBeforeNewYearIsNotDatedInTheFuture(): void
    {
        // 2027-01-01 09:00:00Z a ler o sono da noite de 31 de dezembro.
        $out = self::ofType(
            SleepNormalizer::normalize(
                self::night(fallAsleep: '12-31-23-10', exitSleep: '01-01-07-30'),
                self::DEVICE,
                self::GATEWAY,
                1798794000,
            ),
            'sleep',
        );

        self::assertSame('2026-12-31T23:10:00Z', gmdate('Y-m-d\TH:i:s\Z', (int)($out['data']['startTime'] / 1000)));
        self::assertSame('2027-01-01T07:30:00Z', gmdate('Y-m-d\TH:i:s\Z', (int)($out['data']['endTime'] / 1000)));
    }

    /**
     * Uma noite verdadeira, com os nomes do fabricante.
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function night(
        string $fallAsleep = '09-14-23-10',
        string $exitSleep = '09-15-07-30',
        ?string $curve = '00112234',
    ): array {
        return array_filter([
            'fallAsleepTime' => $fallAsleep,
            'exitSleepTime' => $exitSleep,
            'nightScore' => 88,
            'deepSleepScore' => 82,
            'sleepEfficiencyScore' => 75,
            'fallAsleepEfficiencyScore' => 90,
            'sleepTimeScore' => 70,
            'sleepQuality' => 3,
            'deepSleepTime' => 120,
            'lightSleepTime' => 330,
            'otherSleepTime' => 30,
            'sleepTotalTime' => 480,
            'firstDeepSleepTime' => 22,
            'nightTotalTime' => 18,
            'nightDeepSleepMeanValue' => 6,
            'insomniaScore' => 65,
            'insomniaCount' => 2,
            'sleepCurve' => $curve,
        ], static fn(mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private static function sleep(array $content): array
    {
        return self::ofType(
            SleepNormalizer::normalize($content, self::DEVICE, self::GATEWAY, self::NOW),
            'sleep',
        );
    }

    /**
     * @param list<array<string, mixed>> $out
     * @return array<string, mixed>
     */
    private static function ofType(array $out, string $type): array
    {
        foreach ($out as $telemetry) {
            if (($telemetry['type'] ?? '') === $type) {
                return $telemetry;
            }
        }

        self::fail("não saiu nenhuma telemetria do tipo {$type}");
    }
}
