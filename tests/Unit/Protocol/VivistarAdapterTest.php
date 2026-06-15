<?php

declare(strict_types=1);

namespace Tests\Unit\Protocol;

use Hub\Protocol\Adapter\VivistarAdapter;
use PHPUnit\Framework\TestCase;

final class VivistarAdapterTest extends TestCase
{
    public function testCanDecodeRecognizesVivistarFrame(): void
    {
        $adapter = new VivistarAdapter();

        self::assertTrue($adapter->canDecode('IWAP49,72#'));
        self::assertFalse($adapter->canDecode('{"type":"upHeartRate"}'));
        self::assertFalse($adapter->canDecode('AP49,72#'));
    }

    public function testDecodeIncomingParsesLoginPacket(): void
    {
        $adapter = new VivistarAdapter();

        $payload = $adapter->decodeIncoming('IWAP00865028000000308#');

        self::assertIsArray($payload);
        self::assertSame('login', $payload['type']);
        self::assertSame('865028000000308', $payload['imei']);
        self::assertSame('w:update', $payload['ref']);
    }

    public function testDecodeIncomingParsesMeasurementPacket(): void
    {
        $adapter = new VivistarAdapter();

        $payload = $adapter->decodeIncoming('IWAP49,72#', ['session' => ['imei' => '865028000000308']]);

        self::assertIsArray($payload);
        self::assertSame('AP49', $payload['type']);
        self::assertSame('865028000000308', $payload['imei']);
        self::assertSame(['72'], $payload['data']['fields']);
        self::assertSame(72, $payload['data']['heartRate']);
    }

    public function testDecodeIncomingParsesAp10AlarmPacket(): void
    {
        $adapter = new VivistarAdapter();

        $payload = $adapter->decodeIncoming(
            'IWAP10080524A2232.9806N11404.9355E000.1061830323.8706000908000602,460,0,9520,3671,06,zh-cn,00,HOME|74-DE-2B-44-88-8C|97#',
            ['session' => ['imei' => '861265062542599']]
        );

        self::assertIsArray($payload);
        self::assertSame('AP10', $payload['type']);
        self::assertSame('861265062542599', $payload['imei']);
        self::assertSame(22.549676666666667, $payload['data']['lat']);
        self::assertSame(114.08225833333333, $payload['data']['lon']);
        self::assertSame('06', $payload['data']['alarmCode']);
        self::assertTrue($payload['data']['fall']);
        self::assertSame(80, $payload['data']['battery']);
        self::assertSame(9520, $payload['data']['lac']);
        self::assertSame(3671, $payload['data']['cellId']);
    }

    public function testDecodeIncomingRejectsMalformedPacket(): void
    {
        $adapter = new VivistarAdapter();

        self::assertNull($adapter->decodeIncoming('IWAP#'));
        self::assertNull($adapter->decodeIncoming('not-a-packet'));
    }

    public function testEncodeOutgoingBuildsLoginResponse(): void
    {
        $adapter = new VivistarAdapter();

        $line = $adapter->encodeOutgoing(['type' => 'login_ok'], ['timezoneHours' => 1]);

        self::assertMatchesRegularExpression('/^IWBP00,\d{14},1#$/', $line);
    }

    public function testEncodeOutgoingBuildsCommandReplyForApType(): void
    {
        $adapter = new VivistarAdapter();

        $line = $adapter->encodeOutgoing([
            'type' => 'AP49',
            'data' => ['fields' => ['72']],
        ]);

        self::assertSame('IWBP49,72#', $line);
    }

    public function testEncodeOutgoingBuildsDownlinkBpFrame(): void
    {
        $adapter = new VivistarAdapter();

        $line = $adapter->encodeOutgoing([
            'type' => 'BPXL',
            'imei' => '865028000000308',
            'ident' => '123456',
            'data' => ['fields' => ['1', '2']],
        ]);

        self::assertSame('IWBPXL,865028000000308,123456,1,2#', $line);
    }

    public function testEncodeOutgoingBuildsAp10UnicodeReplyWithoutComma(): void
    {
        $adapter = new VivistarAdapter();

        $line = $adapter->encodeOutgoing([
            'type' => 'AP10',
            'data' => ['unicodeHex' => '004C00610074'],
        ]);

        self::assertSame('IWBP10004C00610074#', $line);
    }

    public function testEncodeOutgoingBuildsAp02UnicodeReplyWithoutComma(): void
    {
        $adapter = new VivistarAdapter();

        $line = $adapter->encodeOutgoing([
            'type' => 'AP02',
            'data' => ['unicodeHex' => '4F4D4E00'],
        ]);

        self::assertSame('IWBP024F4D4E00#', $line);
    }
}
