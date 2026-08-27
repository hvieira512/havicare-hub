<?php

declare(strict_types=1);

namespace Tests\Unit\Location;

use Hub\Location\ArrayPrivateRadioMapStore;
use Hub\Location\BeaconDbRequestBuilder;
use Hub\Location\LocationTelemetryEnricherContract;
use Hub\Location\PrivateRadioMap;
use Hub\Location\PrivateRadioMapTelemetryEnricher;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

final class PrivateRadioMapTelemetryEnricherTest extends TestCase
{
    public function testPrivateResolutionRunsBeforePublicFallback(): void
    {
        $map = new PrivateRadioMap(new ArrayPrivateRadioMapStore(), new BeaconDbRequestBuilder(), 'key');
        $map->seed(['10:11:12:13:14:15', '20:21:22:23:24:25'], 41.7, -8.8, 30);
        $fallback = new class implements LocationTelemetryEnricherContract {
            public int $calls = 0;
            public function enrich(array $telemetry): PromiseInterface
            {
                $this->calls++;
                return resolve($telemetry);
            }
        };
        $enricher = new PrivateRadioMapTelemetryEnricher($map, $fallback);
        $result = null;
        $enricher->enrich($this->telemetry())->then(static function (array $value) use (&$result): void {
            $result = $value;
        });

        self::assertSame(0, $fallback->calls);
        self::assertTrue($result['data']['hasCoordinates']);
        self::assertSame(41.7, $result['data']['lat']);
        self::assertSame(-8.8, $result['data']['lon']);
    }

    public function testUnknownEvidenceFallsBackToPublicProvider(): void
    {
        $map = new PrivateRadioMap(new ArrayPrivateRadioMapStore(), new BeaconDbRequestBuilder(), 'key');
        $fallback = new class implements LocationTelemetryEnricherContract {
            public int $calls = 0;
            public function enrich(array $telemetry): PromiseInterface
            {
                $this->calls++;
                $telemetry['data']['hasCoordinates'] = true;
                $telemetry['data']['lat'] = 40.0;
                $telemetry['data']['lon'] = -7.0;
                return resolve($telemetry);
            }
        };
        $result = null;
        (new PrivateRadioMapTelemetryEnricher($map, $fallback))->enrich($this->telemetry())->then(
            static function (array $value) use (&$result): void {
                $result = $value;
            }
        );

        self::assertSame(1, $fallback->calls);
        self::assertSame(40.0, $result['data']['lat']);
    }

    public function testGpsIsLearnedButPublishedWithoutBeingReplaced(): void
    {
        $map = new PrivateRadioMap(new ArrayPrivateRadioMapStore(), new BeaconDbRequestBuilder(), 'key');
        $fallback = new class implements LocationTelemetryEnricherContract {
            public int $calls = 0;
            public function enrich(array $telemetry): PromiseInterface
            {
                $this->calls++;
                return resolve($telemetry);
            }
        };
        $gps = $this->telemetry();
        $gps['data'] = array_merge($gps['data'], [
            'source' => 'gps', 'gpsValid' => true, 'hasCoordinates' => true,
            'lat' => 41.706841, 'lon' => -8.793279, 'accuracyMeters' => 20,
        ]);
        $result = null;
        (new PrivateRadioMapTelemetryEnricher($map, $fallback))->enrich($gps)->then(
            static function (array $value) use (&$result): void {
                $result = $value;
            }
        );

        self::assertSame(1, $fallback->calls);
        self::assertSame(41.706841, $result['data']['lat']);
        self::assertSame(20, $result['data']['accuracyMeters']);
        self::assertNotNull($map->resolveTelemetry($this->telemetry()));
    }

    private function telemetry(): array
    {
        return [
            'schemaVersion' => 2,
            'type' => 'location',
            'device' => ['id' => 'test-watch'],
            'data' => [
                'source' => 'wifi',
                'gpsValid' => false,
                'hasCoordinates' => false,
                'wifiAccessPoints' => [
                    ['mac' => '10:11:12:13:14:15', 'signalStrengthDbm' => -40],
                    ['mac' => '20:21:22:23:24:25', 'signalStrengthDbm' => -45],
                ],
            ],
        ];
    }
}
