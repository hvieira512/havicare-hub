<?php

declare(strict_types=1);

namespace Tests\Unit\Protocol;

use Hub\Protocol\Adapter\FourPTouchAdapter;
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

    public function testDecodeIncomingParsesOxygenReport(): void
    {
        $adapter = new FourPTouchAdapter();

        $payload = $adapter->decodeIncoming('[3G*8800000015*000B*oxygen,0,96]');

        self::assertIsArray($payload);
        self::assertSame('oxygen', $payload['type']);
        self::assertSame(0, $payload['data']['measureType']);
        self::assertSame(96, $payload['data']['spo2']);
    }

    public function testDecodeIncomingParsesUd2LocationWithBaseStations(): void
    {
        $adapter = new FourPTouchAdapter();
        $payload = $adapter->decodeIncoming($this->frame($adapter, 'UD2', [
            '240617', '101530', 'A', '38.7167', 'N', '9.1399', 'W', '0.50', '152', '12.0', '9', '88', '76', '1042', '15',
            '00010010', '1', '1', '268', '01', '1234', '5678', '91', '0', '15.5',
        ]));

        self::assertIsArray($payload);
        self::assertSame('UD2', $payload['type']);
        self::assertSame('GSM', $payload['data']['networkType']);
        self::assertTrue($payload['data']['gpsValid']);
        self::assertSame(38.7167, $payload['data']['lat']);
        self::assertSame(-9.1399, $payload['data']['lon']);
        self::assertSame('268', $payload['data']['mcc']);
        self::assertSame('01', $payload['data']['mnc']);
        self::assertSame('1234', $payload['data']['lac']);
        self::assertSame('5678', $payload['data']['cellId']);
        self::assertSame(91, $payload['data']['cellSignal']);
        self::assertSame(15.5, $payload['data']['accuracy']);
        self::assertCount(1, $payload['data']['baseStations']);
        self::assertTrue($payload['data']['staticState']);
        self::assertTrue($payload['data']['sos']);
    }

    public function testDecodeIncomingParsesAlarmWithWifiPayload(): void
    {
        $adapter = new FourPTouchAdapter();
        $payload = $adapter->decodeIncoming($this->frame($adapter, 'AL_LTE', [
            '240617', '101530', 'V', '0.0', 'N', '0.0', 'E', '0.0', '0', '0', '0', '55', '44', '0', '0',
            '00200000', '1', '1', '334', '020', '13011', '23152151', '100', '2',
            'OfficeNet', 'bc:5f:f6:1e:07:be', '-55',
            'Lobby', 'c4:b8:b5:c4:14:79', '-53',
            '0.0',
        ]));

        self::assertIsArray($payload);
        self::assertSame('AL_LTE', $payload['type']);
        self::assertSame('LTE', $payload['data']['networkType']);
        self::assertFalse($payload['data']['gpsValid']);
        self::assertSame('00200000', $payload['data']['alarmCode']);
        self::assertTrue($payload['data']['fall']);
        self::assertCount(1, $payload['data']['baseStations']);
        self::assertCount(2, $payload['data']['wifi']);
        self::assertSame('OfficeNet', $payload['data']['wifi'][0]['label']);
        self::assertSame(-55, $payload['data']['wifi'][0]['signal']);
    }

    public function testDecodeIncomingParsesWifiInfoReport(): void
    {
        $adapter = new FourPTouchAdapter();
        $payload = $adapter->decodeIncoming($this->frame($adapter, 'WIFIINFOUP', [
            '486f6d65',
            '3132333435363738',
            '08:c0:21:1e:68:e0',
        ]));

        self::assertIsArray($payload);
        self::assertSame('WIFIINFOUP', $payload['type']);
        self::assertSame('Home', $payload['data']['wifiName']);
        self::assertSame('12345678', $payload['data']['wifiPassword']);
        self::assertSame('08:c0:21:1e:68:e0', $payload['data']['wifiSsid']);
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

    private function frame(FourPTouchAdapter $adapter, string $type, array $fields): string
    {
        return $adapter->encodeOutgoing([
            'type' => $type,
            'imei' => '0304187109',
            'manufacturer' => '3G',
            'data' => ['fields' => $fields],
        ]);
    }
}
