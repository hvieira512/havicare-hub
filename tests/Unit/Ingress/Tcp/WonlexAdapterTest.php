<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Tcp;

use Hub\Ingress\Tcp\Wonlex\WonlexAdapter;
use PHPUnit\Framework\TestCase;

final class WonlexAdapterTest extends TestCase
{
    public function testCanDecodeRecognizesWonlexFrame(): void
    {
        $adapter = new WonlexAdapter();

        $frame = $adapter->encodeOutgoing(['type' => 'upHeartRate', 'data' => ['value' => 72]]);

        self::assertTrue($adapter->canDecode($frame));
        self::assertFalse($adapter->canDecode('IWAP49,72#'));
    }

    public function testDecodeIncomingParsesPayload(): void
    {
        $adapter = new WonlexAdapter();
        $raw = $adapter->encodeOutgoing(['type' => 'upHeartRate', 'data' => ['value' => 72]]);

        $decoded = $adapter->decodeIncoming($raw);

        self::assertIsArray($decoded);
        self::assertSame('upHeartRate', $decoded['type']);
        self::assertSame(['value' => 72], $decoded['data']);
    }

    public function testDecodeIncomingRejectsInvalidPayload(): void
    {
        $adapter = new WonlexAdapter();

        self::assertNull($adapter->decodeIncoming('bad'));

        $invalidJsonFrame = pack('nn', 0xFCAF, 5) . 'abcde';
        self::assertNull($adapter->decodeIncoming($invalidJsonFrame));
    }

    public function testEncodeOutgoingKeepsUnicodeUnescaped(): void
    {
        $payload = ['type' => 'upText', 'data' => ['value' => 'á']];

        self::assertStringEndsWith('{"type":"upText","data":{"value":"á"}}', (new WonlexAdapter())->encodeOutgoing($payload));
    }
}
