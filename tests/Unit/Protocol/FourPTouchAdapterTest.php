<?php

declare(strict_types=1);

namespace Tests\Unit\Protocol;

use App\Protocol\Adapter\FourPTouchAdapter;
use PHPUnit\Framework\TestCase;

final class FourPTouchAdapterTest extends TestCase
{
    public function testCanDecodeRecognizesFourPTouchFrame(): void
    {
        $adapter = new FourPTouchAdapter();

        self::assertTrue($adapter->canDecode('[3G*8800000015*000D*LK,50,100,100]'));
        self::assertTrue($adapter->canDecode('[CS*0304187109*0009*LK,0,0,21]'));
        self::assertFalse($adapter->canDecode('IWAP49,72#'));
        self::assertFalse($adapter->canDecode('[3G*8800000015*000D*LK]'));
    }

    public function testDecodeIncomingParsesLinkKeep(): void
    {
        $adapter = new FourPTouchAdapter();

        $payload = $adapter->decodeIncoming('[3G*8800000015*000D*LK,50,100,100]');

        self::assertIsArray($payload);
        self::assertSame('LK', $payload['type']);
        self::assertSame('8800000015', $payload['imei']);
        self::assertSame('8800000015', $payload['ident']);
        self::assertSame('3G', $payload['data']['manufacturer']);
        self::assertSame('000D', $payload['data']['length']);
        self::assertSame(['50', '100', '100'], $payload['data']['fields']);
        self::assertSame(50, $payload['data']['steps']);
        self::assertSame(100, $payload['data']['tumblingCount']);
        self::assertSame(100, $payload['data']['batteryPercent']);
    }

    public function testDecodeIncomingParsesHealthReport(): void
    {
        $adapter = new FourPTouchAdapter();

        $payload = $adapter->decodeIncoming('[3G*8800000015*0013*bphrt,112,73,67,,,,]');

        self::assertIsArray($payload);
        self::assertSame('bphrt', $payload['type']);
        self::assertSame(['112', '73', '67', '', '', '', ''], $payload['data']['fields']);
        self::assertSame(112, $payload['data']['systolic']);
        self::assertSame(73, $payload['data']['diastolic']);
        self::assertSame(67, $payload['data']['heartRate']);
    }

    public function testDecodeIncomingParsesLteAlarm(): void
    {
        $adapter = new FourPTouchAdapter();

        $payload = $adapter->decodeIncoming('[3G*0304187109*0065*AL_LTE,310120,184251,V,0.0,N,0.0,E,22.0,0,-1,0,100,11,0,0,00200000,1,1,334,020,13011,23152151,100,0,5]');

        self::assertIsArray($payload);
        self::assertSame('AL_LTE', $payload['type']);
        self::assertFalse($payload['data']['gpsValid']);
        self::assertSame('lbs_wifi', $payload['data']['source']);
        self::assertSame('00200000', $payload['data']['alarmCode']);
        self::assertTrue($payload['data']['fall']);
    }

    public function testDecodeIncomingRejectsInvalidLength(): void
    {
        $adapter = new FourPTouchAdapter();

        self::assertNull($adapter->decodeIncoming('[3G*8800000015*0002*LK,50]'));
    }

    public function testEncodeOutgoingBuildsLinkKeepAck(): void
    {
        $adapter = new FourPTouchAdapter();

        $frame = $adapter->encodeOutgoing([
            'type' => 'LK',
            'imei' => '8800000015',
            'manufacturer' => '3G',
        ]);

        self::assertSame('[3G*8800000015*0002*LK]', $frame);
    }
}
