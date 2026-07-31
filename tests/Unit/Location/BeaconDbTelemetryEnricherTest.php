<?php

declare(strict_types=1);

namespace Tests\Unit\Location;

use Hub\Location\BeaconDbRequestBuilder;
use Hub\Location\BeaconDbTelemetryEnricher;
use PHPUnit\Framework\TestCase;

use function React\Promise\resolve;

final class BeaconDbTelemetryEnricherTest extends TestCase
{
    public function testMergesResolvedCoordinatesInsideExistingDataContract(): void
    {
        $requests = [];
        $enricher = new BeaconDbTelemetryEnricher(
            new BeaconDbRequestBuilder(),
            function (array $request) use (&$requests) {
                $requests[] = $request;
                return resolve([
                    'httpStatus' => 200,
                    'body' => [
                        'location' => ['lat' => 41.69176, 'lng' => -8.831533],
                        'accuracy' => 300,
                    ],
                ]);
            },
        );

        $telemetry = $this->nonGpsTelemetry();
        $result = null;
        $enricher->enrich($telemetry)->then(function (array $value) use (&$result): void {
            $result = $value;
        });

        self::assertCount(1, $requests);
        self::assertIsArray($result);
        self::assertSame(2, $result['schemaVersion']);
        self::assertSame('location', $result['type']);
        self::assertArrayNotHasKey('coordinates', $result);
        self::assertSame('cell_wifi', $result['data']['source']);
        self::assertTrue($result['data']['hasCoordinates']);
        self::assertSame(41.69176, $result['data']['lat']);
        self::assertSame(-8.831533, $result['data']['lon']);
        self::assertSame(300.0, $result['data']['accuracyMeters']);
        self::assertCount(1, $result['data']['baseStations']);
        self::assertCount(2, $result['data']['wifiAccessPoints']);
    }

    public function testDoesNotResolveTelemetryThatAlreadyHasGpsCoordinates(): void
    {
        $calls = 0;
        $enricher = new BeaconDbTelemetryEnricher(
            new BeaconDbRequestBuilder(),
            function () use (&$calls) {
                $calls++;
                return resolve([]);
            },
        );
        $telemetry = $this->nonGpsTelemetry();
        $telemetry['data'] = [
            'source' => 'gps',
            'hasCoordinates' => true,
            'lat' => 41.706431,
            'lon' => -8.793129,
        ];
        $result = null;

        $enricher->enrich($telemetry)->then(function (array $value) use (&$result): void {
            $result = $value;
        });

        self::assertSame(0, $calls);
        self::assertSame($telemetry, $result);
    }

    public function testCachesRepeatedEvidence(): void
    {
        $calls = 0;
        $enricher = new BeaconDbTelemetryEnricher(
            new BeaconDbRequestBuilder(),
            function () use (&$calls) {
                $calls++;
                return resolve([
                    'httpStatus' => 200,
                    'body' => ['location' => ['lat' => 41.7, 'lng' => -8.8], 'accuracy' => 100],
                ]);
            },
            cacheTtlSeconds: 300,
        );

        $enricher->enrich($this->nonGpsTelemetry());
        $enricher->enrich($this->nonGpsTelemetry());

        self::assertSame(1, $calls);
    }

    public function testCacheIdentityIgnoresSignalChangesAndEvidenceOrder(): void
    {
        $calls = 0;
        $enricher = new BeaconDbTelemetryEnricher(
            new BeaconDbRequestBuilder(),
            function () use (&$calls) {
                $calls++;
                return resolve([
                    'httpStatus' => 200,
                    'body' => ['location' => ['lat' => 41.7, 'lng' => -8.8], 'accuracy' => 100],
                ]);
            },
        );
        $first = $this->nonGpsTelemetry();
        $second = $first;
        $second['data']['wifiAccessPoints'] = array_reverse($second['data']['wifiAccessPoints']);
        $second['data']['wifiAccessPoints'][0]['signalStrengthDbm'] = -80;

        $enricher->enrich($first);
        $enricher->enrich($second);

        self::assertSame(1, $calls);
    }

    public function testResolvesNonGpsEvidenceEvenWhenFirmwareIncludesStaleCoordinates(): void
    {
        $calls = 0;
        $enricher = new BeaconDbTelemetryEnricher(
            new BeaconDbRequestBuilder(),
            function () use (&$calls) {
                $calls++;
                return resolve([
                    'httpStatus' => 200,
                    'body' => ['location' => ['lat' => 41.7, 'lng' => -8.8], 'accuracy' => 100],
                ]);
            },
        );
        $telemetry = $this->nonGpsTelemetry();
        $telemetry['data']['gpsValid'] = false;
        $telemetry['data']['lat'] = 40.0;
        $telemetry['data']['lon'] = -7.0;
        $result = null;

        $enricher->enrich($telemetry)->then(function (array $value) use (&$result): void {
            $result = $value;
        });

        self::assertSame(1, $calls);
        self::assertSame(41.7, $result['data']['lat']);
        self::assertSame(-8.8, $result['data']['lon']);
    }

    public function testDoesNotResolvePureCellLbsEvidence(): void
    {
        $calls = 0;
        $enricher = new BeaconDbTelemetryEnricher(
            new BeaconDbRequestBuilder(),
            function () use (&$calls) {
                $calls++;
                return resolve([
                    'httpStatus' => 200,
                    'body' => ['location' => ['lat' => 41.7, 'lng' => -8.8], 'accuracy' => 100],
                ]);
            },
        );
        $telemetry = $this->nonGpsTelemetry();
        $telemetry['data']['source'] = 'cell';
        $telemetry['data']['gpsValid'] = false;
        $telemetry['data']['lat'] = 41.0;
        $telemetry['data']['lon'] = -8.0;
        unset($telemetry['data']['wifiAccessPoints']);
        $result = null;

        $enricher->enrich($telemetry)->then(function (array $value) use (&$result): void {
            $result = $value;
        });

        self::assertSame(0, $calls);
        self::assertFalse($result['data']['hasCoordinates']);
        self::assertArrayNotHasKey('lat', $result['data']);
        self::assertArrayNotHasKey('lon', $result['data']);
        self::assertNotEmpty($result['data']['baseStations']);
    }

    public function testDoesNotFallBackToCellOnlyWhenWifiEvidenceIsInsufficient(): void
    {
        $calls = 0;
        $enricher = new BeaconDbTelemetryEnricher(
            new BeaconDbRequestBuilder(),
            function () use (&$calls) {
                $calls++;
                return resolve([]);
            },
        );
        $telemetry = $this->nonGpsTelemetry();
        $telemetry['data']['wifiAccessPoints'] = [
            ['ssid' => 'Only one', 'mac' => 'dc:fe:23:b8:31:73', 'signalStrengthDbm' => -44],
        ];
        $result = null;

        $enricher->enrich($telemetry)->then(function (array $value) use (&$result): void {
            $result = $value;
        });

        self::assertSame(0, $calls);
        self::assertFalse($result['data']['hasCoordinates']);
        self::assertNotEmpty($result['data']['baseStations']);
    }

    public function testDropsUntrustedCoordinatesWhenProviderAccuracyIsUnacceptable(): void
    {
        $enricher = new BeaconDbTelemetryEnricher(
            new BeaconDbRequestBuilder(),
            static fn () => resolve([
                'httpStatus' => 200,
                'body' => ['location' => ['lat' => 41.7, 'lng' => -8.8], 'accuracy' => 6000],
            ]),
            maxAccuracyMeters: 5000,
        );
        $telemetry = $this->nonGpsTelemetry();
        $telemetry['data']['gpsValid'] = false;
        $telemetry['data']['lat'] = 40.0;
        $telemetry['data']['lon'] = -7.0;
        $telemetry['data']['accuracyMeters'] = 0;
        $result = null;

        $enricher->enrich($telemetry)->then(function (array $value) use (&$result): void {
            $result = $value;
        });

        self::assertFalse($result['data']['hasCoordinates']);
        self::assertArrayNotHasKey('lat', $result['data']);
        self::assertArrayNotHasKey('lon', $result['data']);
        self::assertArrayNotHasKey('accuracyMeters', $result['data']);
    }

    public function testDropsUntrustedCoordinatesWhenProviderDoesNotResolveEvidence(): void
    {
        $enricher = new BeaconDbTelemetryEnricher(
            new BeaconDbRequestBuilder(),
            static fn () => resolve(['httpStatus' => 404, 'body' => ['error' => 'not found']]),
        );
        $telemetry = $this->nonGpsTelemetry();
        $telemetry['data']['gpsValid'] = false;
        $telemetry['data']['lat'] = 41.706315;
        $telemetry['data']['lon'] = -8.7930237;
        $result = null;

        $enricher->enrich($telemetry)->then(function (array $value) use (&$result): void {
            $result = $value;
        });

        self::assertFalse($result['data']['hasCoordinates']);
        self::assertArrayNotHasKey('lat', $result['data']);
        self::assertArrayNotHasKey('lon', $result['data']);
        self::assertSame('cell_wifi', $result['data']['source']);
        self::assertNotEmpty($result['data']['baseStations']);
        self::assertNotEmpty($result['data']['wifiAccessPoints']);
    }

    private function nonGpsTelemetry(): array
    {
        return [
            'schemaVersion' => 2,
            'type' => 'location',
            'occurredAt' => '2026-07-31T12:30:00Z',
            'device' => ['id' => '868705080304889'],
            'data' => [
                'source' => 'cell_wifi',
                'hasCoordinates' => false,
                'radioType' => 'lte',
                'baseStations' => [[
                    'mcc' => '268',
                    'mnc' => '3',
                    'lac' => '180',
                    'cellId' => '194809015',
                    'radioType' => 'lte',
                ]],
                'wifiAccessPoints' => [
                    ['ssid' => 'One', 'mac' => 'dc:fe:23:b8:31:73', 'signalStrengthDbm' => -44],
                    ['ssid' => 'Two', 'mac' => 'dc:fe:23:36:57:4d', 'signalStrengthDbm' => -47],
                ],
            ],
            'source' => ['protocol' => 'wonlex-json', 'nativeType' => 'upLocation'],
        ];
    }
}
