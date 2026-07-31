<?php

declare(strict_types=1);

namespace Tests\Unit\Location;

use Hub\Location\UnwiredLabsRequestBuilder;
use PHPUnit\Framework\TestCase;

final class UnwiredLabsRequestBuilderTest extends TestCase
{
    public function testConvertsNormalizedProviderRequestAndCapsEvidence(): void
    {
        $cells = [];
        for ($i = 1; $i <= 9; $i++) {
            $cells[] = [
                'radioType' => 'wcdma',
                'mobileCountryCode' => 268,
                'mobileNetworkCode' => 3,
                'locationAreaCode' => 180,
                'cellId' => 1000 + $i,
                'signalStrength' => -80,
            ];
        }
        $wifi = [];
        for ($i = 1; $i <= 18; $i++) {
            $wifi[] = [
                'macAddress' => sprintf('00:11:22:33:44:%02x', $i),
                'signalStrength' => -40 - $i,
                'channel' => 11,
            ];
        }

        $payload = (new UnwiredLabsRequestBuilder())->build([
            'cellTowers' => $cells,
            'wifiAccessPoints' => $wifi,
        ], 'secret');

        self::assertSame('secret', $payload['token']);
        self::assertSame('umts', $payload['radio']);
        self::assertSame(268, $payload['mcc']);
        self::assertSame(3, $payload['mnc']);
        self::assertSame(0, $payload['address']);
        self::assertSame(0, $payload['bt']);
        self::assertCount(7, $payload['cells']);
        self::assertCount(15, $payload['wifi']);
        self::assertSame('00:11:22:33:44:01', $payload['wifi'][0]['bssid']);
        self::assertSame(-41, $payload['wifi'][0]['signal']);
    }

    public function testRejectsLessThanTwoWifiAccessPoints(): void
    {
        self::assertNull((new UnwiredLabsRequestBuilder())->build([
            'wifiAccessPoints' => [['macAddress' => '00:11:22:33:44:55']],
        ], 'secret'));
    }
}
