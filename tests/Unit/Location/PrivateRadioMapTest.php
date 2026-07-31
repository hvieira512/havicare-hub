<?php

declare(strict_types=1);

namespace Tests\Unit\Location;

use Hub\Location\ArrayPrivateRadioMapStore;
use Hub\Location\BeaconDbRequestBuilder;
use Hub\Location\PrivateRadioMap;
use PHPUnit\Framework\TestCase;

final class PrivateRadioMapTest extends TestCase
{
    public function testLearnsWifiFromTrustedGpsAndResolvesLaterNonGpsEvidence(): void
    {
        $map = $this->map();

        self::assertSame(3, $map->learnFromTelemetry($this->gpsTelemetry()));
        $resolved = $map->resolveTelemetry($this->nonGpsTelemetry());

        self::assertIsArray($resolved);
        self::assertTrue($resolved['hasCoordinates']);
        self::assertSame(41.706841, $resolved['lat']);
        self::assertSame(-8.793279, $resolved['lon']);
        self::assertSame(30.0, $resolved['accuracyMeters']);
    }

    public function testRequiresTrustedGpsAccuracyOrEnoughSatellitesToLearn(): void
    {
        $map = $this->map();
        $inaccurate = $this->gpsTelemetry();
        $inaccurate['data']['accuracyMeters'] = 180;
        self::assertSame(0, $map->learnFromTelemetry($inaccurate));

        $withoutEvidence = $this->gpsTelemetry();
        unset($withoutEvidence['data']['accuracyMeters']);
        $withoutEvidence['data']['satelliteCount'] = 2;
        self::assertSame(0, $map->learnFromTelemetry($withoutEvidence));

        $satelliteBacked = $this->gpsTelemetry();
        unset($satelliteBacked['data']['accuracyMeters']);
        $satelliteBacked['data']['satelliteCount'] = 6;
        self::assertSame(3, $map->learnFromTelemetry($satelliteBacked));
        self::assertSame(50.0, $map->resolveTelemetry($this->nonGpsTelemetry())['accuracyMeters']);
    }

    public function testRejectsSingleKnownAccessPoint(): void
    {
        $map = $this->map();
        $map->seed(['10:11:12:13:14:15', '20:21:22:23:24:25'], 41.7, -8.8, 25);
        $telemetry = $this->nonGpsTelemetry();
        $telemetry['data']['wifiAccessPoints'] = [
            ['mac' => '10:11:12:13:14:15', 'signalStrengthDbm' => -40],
            ['mac' => '40:41:42:43:44:45', 'signalStrengthDbm' => -45],
        ];

        self::assertNull($map->resolveTelemetry($telemetry));
    }

    public function testConflictingLearnedObservationQuarantinesAccessPoints(): void
    {
        $map = $this->map();
        self::assertSame(3, $map->learnFromTelemetry($this->gpsTelemetry()));
        $moved = $this->gpsTelemetry();
        $moved['data']['lat'] = 41.716841;
        self::assertSame(0, $map->learnFromTelemetry($moved));

        self::assertNull($map->resolveTelemetry($this->nonGpsTelemetry()));
    }

    public function testManualSeedsRemainAuthoritativeAgainstGpsConflicts(): void
    {
        $map = $this->map();
        self::assertSame(3, $map->seed(
            ['10:11:12:13:14:15', '20:21:22:23:24:25', '30:31:32:33:34:35'],
            41.706841,
            -8.793279,
            20,
        ));
        $moved = $this->gpsTelemetry();
        $moved['data']['lat'] = 42.0;
        self::assertSame(0, $map->learnFromTelemetry($moved));

        $resolved = $map->resolveTelemetry($this->nonGpsTelemetry());
        self::assertSame(41.706841, $resolved['lat']);
        self::assertSame(-8.793279, $resolved['lon']);
        self::assertSame(25.0, $resolved['accuracyMeters']);
    }

    private function map(): PrivateRadioMap
    {
        return new PrivateRadioMap(
            new ArrayPrivateRadioMapStore(),
            new BeaconDbRequestBuilder(),
            'test-private-hmac-key',
        );
    }

    private function gpsTelemetry(): array
    {
        $telemetry = $this->nonGpsTelemetry();
        $telemetry['data']['source'] = 'gps';
        $telemetry['data']['gpsValid'] = true;
        $telemetry['data']['hasCoordinates'] = true;
        $telemetry['data']['lat'] = 41.706841;
        $telemetry['data']['lon'] = -8.793279;
        $telemetry['data']['accuracyMeters'] = 30;
        $telemetry['data']['satelliteCount'] = 7;
        return $telemetry;
    }

    private function nonGpsTelemetry(): array
    {
        return [
            'schemaVersion' => 2,
            'type' => 'location',
            'device' => ['id' => 'test-watch'],
            'data' => [
                'source' => 'cell_wifi',
                'gpsValid' => false,
                'hasCoordinates' => false,
                'wifiAccessPoints' => [
                    ['mac' => '10:11:12:13:14:15', 'signalStrengthDbm' => -40],
                    ['mac' => '20:21:22:23:24:25', 'signalStrengthDbm' => -45],
                    ['mac' => '30:31:32:33:34:35', 'signalStrengthDbm' => -50],
                ],
            ],
        ];
    }
}
