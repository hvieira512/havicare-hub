<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use App\Hub\RawPayload;
use PHPUnit\Framework\TestCase;

final class RawPayloadTest extends TestCase
{
    public function testEnvelopePreservesTextPayloadAsBase64AndText(): void
    {
        $payload = RawPayload::envelope('865028000000308', 'tcp', 'vivistar-iw', 'IWAP49,72#', 'uplink', '1');

        self::assertSame('865028000000308', $payload['device']['imei']);
        self::assertSame('base64', $payload['encoding']);
        self::assertSame(base64_encode('IWAP49,72#'), $payload['payload']);
        self::assertSame('IWAP49,72#', $payload['text']);
    }

    public function testEnvelopePreservesBinaryPayloadWithoutTextProjection(): void
    {
        $raw = "\xFC\xAF\x00\x02{}";

        $payload = RawPayload::envelope('865028000000306', 'websocket', 'wonlex-json', $raw, 'uplink');

        self::assertSame(base64_encode($raw), $payload['payload']);
        self::assertArrayNotHasKey('text', $payload);
    }

    public function testDownlinkAcceptsTextEnvelope(): void
    {
        self::assertSame('IWBP03#', RawPayload::bytesFromDownlink([
            'encoding' => 'text',
            'payload' => 'IWBP03#',
        ]));
    }

    public function testDownlinkAcceptsBase64Envelope(): void
    {
        self::assertSame('raw-bytes', RawPayload::bytesFromDownlink([
            'encoding' => 'base64',
            'payload' => base64_encode('raw-bytes'),
        ]));
    }
}
