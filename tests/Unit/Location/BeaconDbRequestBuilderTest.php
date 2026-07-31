<?php

namespace Tests\Unit\Location;

use Hub\Location\BeaconDbRequestBuilder;
use PHPUnit\Framework\TestCase;

final class BeaconDbRequestBuilderTest extends TestCase
{
    public function testBuildsCombinedCellAndWifiRequest(): void
    {
        $request = (new BeaconDbRequestBuilder())->build([
            'type' => 'location',
            'data' => [
                'radioType' => 'lte',
                'mcc' => '268',
                'mnc' => '020',
                'baseStations' => [[
                    'lac' => '1234',
                    'cellId' => '23152151',
                    'signalStrengthDbm' => -100,
                ]],
                'wifiAccessPoints' => [
                    ['ssid' => 'Office', 'mac' => 'BC-5F-F6-1E-07-BE', 'signalStrengthDbm' => -55],
                    ['ssid' => 'Lobby', 'mac' => 'c4:b8:b5:c4:14:79', 'signalStrengthDbm' => -53],
                ],
            ],
        ]);

        self::assertNotNull($request);
        self::assertFalse($request['considerIp']);
        self::assertSame(['ipf' => false, 'lacf' => false], $request['fallbacks']);
        self::assertSame([
            'radioType' => 'lte',
            'mobileCountryCode' => 268,
            'mobileNetworkCode' => 20,
            'locationAreaCode' => 1234,
            'cellId' => 23152151,
            'signalStrength' => -100,
        ], $request['cellTowers'][0]);
        self::assertSame('bc:5f:f6:1e:07:be', $request['wifiAccessPoints'][0]['macAddress']);
        self::assertSame('Office', $request['wifiAccessPoints'][0]['ssid']);
    }

    public function testFiltersUnsafeWifiAndRequiresTwoUsableAccessPoints(): void
    {
        $request = (new BeaconDbRequestBuilder())->build([
            'wifiAccessPoints' => [
                ['ssid' => 'Private_nomap', 'mac' => 'bc:5f:f6:1e:07:be'],
                ['ssid' => 'Local', 'mac' => '02:11:22:33:44:55'],
                ['ssid' => 'Broadcast', 'mac' => 'ff:ff:ff:ff:ff:ff'],
                ['ssid' => 'OnlyValid', 'mac' => 'c4:b8:b5:c4:14:79'],
            ],
        ]);

        self::assertNull($request);
    }

    public function testDoesNotSendCellWithUnknownOrUnsupportedRadio(): void
    {
        $builder = new BeaconDbRequestBuilder();
        $base = [
            'mcc' => 268,
            'mnc' => 1,
            'baseStations' => [['lac' => 1234, 'cellId' => 5678]],
        ];

        self::assertNull($builder->build($base));
        self::assertNull($builder->build(['radioType' => 'cdma'] + $base));
        self::assertNull($builder->build(['radioType' => 'nr'] + $base));
    }

    public function testDeduplicatesWifiByCanonicalMac(): void
    {
        $request = (new BeaconDbRequestBuilder())->build([
            'wifiAccessPoints' => [
                ['ssid' => 'Office-old', 'mac' => 'BC-5F-F6-1E-07-BE'],
                ['ssid' => 'Office', 'mac' => 'bc:5f:f6:1e:07:be', 'signalStrengthDbm' => -55],
                ['ssid' => 'Lobby', 'mac' => 'c4:b8:b5:c4:14:79'],
            ],
        ]);

        self::assertNotNull($request);
        self::assertCount(2, $request['wifiAccessPoints']);
        self::assertSame('Office', $request['wifiAccessPoints'][0]['ssid']);
    }
}
