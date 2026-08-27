<?php

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\ProximityTracker;
use PHPUnit\Framework\TestCase;

/**
 * As estatísticas da janela sobre as quais um cliente põe limiares. As séries usadas aqui são
 * leituras reais, capturadas da pulseira fb:d8:7c:59:ba:8b retransmitida pelo MKGW4
 * c5:e3:90:f3:0b:ce, com a pulseira imóvel sobre uma mesa.
 */
final class ProximityTrackerTest extends TestCase
{
    /** Leituras reais: 15 dB de dispersão num aparelho que nunca se mexeu. */
    private const MOTIONLESS = [-66, -77, -77, -69, -69, -69, -66, -78, -69, -66];

    public function testTheFirstReadingIsItsOwnWindow(): void
    {
        $stats = (new ProximityTracker())->record('device', 'gateway', -68, 1000.0);

        self::assertSame('measured', $stats['state']);
        self::assertSame(-68, $stats['rssiDbm']);
        self::assertSame(-68, $stats['rssiMaxDbm']);
        self::assertSame(-68, $stats['rssiMedianDbm']);
        self::assertSame(-68, $stats['rssiMinDbm']);
        self::assertSame(1, $stats['samples']);
    }

    public function testSummarisesTheWindowItKeeps(): void
    {
        $tracker = new ProximityTracker(windowSeconds: 60);
        $stats = [];
        foreach (self::MOTIONLESS as $index => $rssi) {
            $stats = $tracker->record('device', 'gateway', $rssi, 1000.0 + $index);
        }

        self::assertSame(10, $stats['samples']);
        self::assertSame(-66, $stats['rssiMaxDbm']);
        self::assertSame(-78, $stats['rssiMinDbm']);
        // Ordenado: -78 -77 -77 -69 -69 -69 -69 -66 -66 -66. Com contagem par fica a menor
        // das duas do meio, para o valor ser um que a rádio viu de facto.
        self::assertSame(-69, $stats['rssiMedianDbm']);
    }

    public function testAStrongSampleMovesTheMaximumButNotTheMedian(): void
    {
        // A propriedade de que um alarme de porta depende: passar a andar por um gateway são
        // uma ou duas leituras, longe das que uma mediana precisa para se mexer.
        $tracker = new ProximityTracker(windowSeconds: 60);
        foreach ([-77, -78, -77, -76] as $index => $rssi) {
            $tracker->record('device', 'gateway', $rssi, 1000.0 + $index);
        }

        $stats = $tracker->record('device', 'gateway', -52, 1004.0);

        self::assertSame(-52, $stats['rssiMaxDbm'], 'a single close reading must be visible');
        self::assertSame(-77, $stats['rssiMedianDbm'], 'and must not move the median');
    }

    public function testReadingsOlderThanTheWindowAreForgotten(): void
    {
        $tracker = new ProximityTracker(windowSeconds: 5);
        $tracker->record('device', 'gateway', -52, 1000.0);

        $stats = $tracker->record('device', 'gateway', -77, 1006.0);

        self::assertSame(1, $stats['samples']);
        self::assertSame(-77, $stats['rssiMaxDbm'], 'the stale close reading must be gone');
    }

    public function testTheWindowIsCappedBySampleCountAsWellAsAge(): void
    {
        $tracker = new ProximityTracker(windowSeconds: 3600, maxSamples: 3);
        foreach ([-50, -60, -70, -80] as $index => $rssi) {
            $stats = $tracker->record('device', 'gateway', $rssi, 1000.0 + $index);
        }

        self::assertSame(3, $stats['samples']);
        self::assertSame(-60, $stats['rssiMaxDbm'], 'the oldest reading must have been dropped');
    }

    public function testEachPairKeepsItsOwnWindow(): void
    {
        // A mesma pulseira ouvida por dois gateways lê diferente em cada um, e a janela de
        // uma porta não pode ser alimentada pela de outra.
        $tracker = new ProximityTracker(windowSeconds: 60);
        $tracker->record('bracelet', 'gateway-near', -52, 1000.0);
        $stats = $tracker->record('bracelet', 'gateway-far', -85, 1001.0);

        self::assertSame(1, $stats['samples']);
        self::assertSame(-85, $stats['rssiMaxDbm']);
    }

    public function testAQuietPairIsReportedOnceAndThenForgotten(): void
    {
        $tracker = new ProximityTracker(stalenessSeconds: 30);
        $tracker->record('bracelet', 'gateway', -70, 1000.0);

        self::assertSame([], $tracker->takeStale(1020.0), 'still within the staleness window');
        self::assertSame(
            [['deviceKey' => 'bracelet', 'gatewayKey' => 'gateway']],
            $tracker->takeStale(1031.0),
        );
        // O silêncio fica silencioso: diz-se ao cliente uma vez, e não a cada tick.
        self::assertSame([], $tracker->takeStale(9999.0));
    }

    public function testAPairHeardAgainStartsAFreshWindow(): void
    {
        $tracker = new ProximityTracker(windowSeconds: 60, stalenessSeconds: 30);
        $tracker->record('bracelet', 'gateway', -52, 1000.0);
        $tracker->takeStale(1031.0);

        $stats = $tracker->record('bracelet', 'gateway', -80, 2000.0);

        self::assertSame(1, $stats['samples']);
        self::assertSame(-80, $stats['rssiMaxDbm']);
    }
}
