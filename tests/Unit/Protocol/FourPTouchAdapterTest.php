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

    public function testDecodeIncomingParsesBodyTemperatureReport(): void
    {
        $adapter = new FourPTouchAdapter();

        $payload = $adapter->decodeIncoming('[3G*8800000015*000D*btemp2,1,36.7]');

        self::assertIsArray($payload);
        self::assertSame('btemp2', $payload['type']);
        self::assertSame(1, $payload['data']['measureType']);
        self::assertSame(36.7, $payload['data']['temp']);
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
        // Uma trama de posição traz o campo de estado, mas os bits de alarme são assunto do
        // frame `AL`: aqui não se decodificam, nem se fabrica um alarme a partir deles.
        self::assertArrayNotHasKey('sos', $payload['data']);
        self::assertArrayNotHasKey('fall', $payload['data']);
        self::assertArrayNotHasKey('alarmCode', $payload['data']);
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

    public function testDecodeIncomingParsesTakePillsReply(): void
    {
        $adapter = new FourPTouchAdapter();

        $payload = $adapter->decodeIncoming('[3G*8800000015*000B*TAKEPILLS,1]');

        self::assertIsArray($payload);
        self::assertSame('TAKEPILLS', $payload['type']);
        self::assertSame('1', $payload['data']['configAck']);
        self::assertSame(['1'], $payload['data']['fields']);
    }

    public function testDecodeIncomingPreservesRejectedTakePillsReply(): void
    {
        $payload = (new FourPTouchAdapter())->decodeIncoming('[3G*8800000015*000B*TAKEPILLS,0]');

        self::assertIsArray($payload);
        self::assertSame('0', $payload['data']['configAck']);
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

    public function testDecodeIncomingParsesFirmwareVersion(): void
    {
        $adapter = new FourPTouchAdapter();

        $payload = $adapter->decodeIncoming('[3G*8800000015*000C*VERNO,ABC123]');

        self::assertIsArray($payload);
        self::assertSame('VERNO', $payload['type']);
        self::assertSame('ABC123', $payload['data']['firmware']);
    }

    /**
     * A resposta ao `TS` é um bloco `chave:valor` e não um carimbo de tempo -- este teste
     * prendia um `deviceTime` que o aparelho nunca devolve.
     */
    public function testDecodeIncomingParsesDeviceStatus(): void
    {
        $adapter = new FourPTouchAdapter();

        $content = 'TS,ver:ABC123; lk:300; batlevel:87; zone:+01:00; gprsOpen:true';
        $payload = $adapter->decodeIncoming(
            sprintf('[3G*8800000015*%04X*%s]', strlen($content), $content)
        );

        self::assertIsArray($payload);
        self::assertSame('TS', $payload['type']);
        self::assertSame('ABC123', $payload['data']['firmware']);
        self::assertSame(300, $payload['data']['heartbeatIntervalSeconds']);
        self::assertSame(87, $payload['data']['batteryPercent']);
        self::assertSame('+01:00', $payload['data']['timeZone']);
        self::assertTrue($payload['data']['cellularEnabled']);
        self::assertArrayNotHasKey('deviceTime', $payload['data']);
    }

    /** @param list<string> $fields */
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
