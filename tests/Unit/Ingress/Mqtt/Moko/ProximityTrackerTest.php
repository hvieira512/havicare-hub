<?php

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\ProximityTracker;
use PHPUnit\Framework\TestCase;

/**
 * The window statistics a client thresholds on. The series used here are real
 * readings captured from bracelet fb:d8:7c:59:ba:8b relayed by MKGW4
 * c5:e3:90:f3:0b:ce while the bracelet lay motionless on a desk.
 */
final class ProximityTrackerTest extends TestCase
{
    /** Real readings: 15 dB of spread from a device that never moved. */
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
        // Sorted: -78 -77 -77 -69 -69 -69 -69 -66 -66 -66. Even count takes the
        // lower middle, so the value is one the radio actually saw.
        self::assertSame(-69, $stats['rssiMedianDbm']);
    }

    public function testAStrongSampleMovesTheMaximumButNotTheMedian(): void
    {
        // The property a door alarm depends on: a brisk walk past a gateway is
        // one or two readings, far too few to move a median.
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
        // The same bracelet heard by two gateways reads differently on each, and
        // one door's window must never be fed by another's.
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
        // Silence stays silent: the client is told once, not every tick.
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
