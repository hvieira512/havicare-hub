<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\RawPayload;
use PHPUnit\Framework\TestCase;

final class RawPayloadTest extends TestCase
{
    public function testRawPayloadKeepsTextDebugDataUnderDebugKey(): void
    {
        $payload = RawPayload::raw('865028000000308', 'Vivistar', 'VIVISTAR-CARE', 'tcp', 'vivistar-iw', 'IWAP49,72#', 'uplink', '1', 'Vivistar L08 Pro');

        self::assertSame(1, $payload['schemaVersion']);
        self::assertSame('uplink', $payload['direction']);
        self::assertSame('865028000000308', $payload['device']['id']);
        self::assertSame('Vivistar', $payload['device']['supplier']);
        self::assertSame('VIVISTAR-CARE', $payload['device']['model']);
        self::assertSame('Vivistar L08 Pro', $payload['device']['commercialName']);
        self::assertSame('text', $payload['debug']['encoding']);
        self::assertSame('IWAP49,72#', $payload['debug']['payload']);
        self::assertSame('1', $payload['debug']['connectionId']);
    }

    public function testRawPayloadKeepsBinaryDebugDataAsBase64(): void
    {
        $raw = "\xFC\xAF\x00\x02{}";

        $payload = RawPayload::raw('865028000000306', 'Wonlex', 'HW20PRO', 'tcp', 'wonlex-json', $raw, 'uplink');

        self::assertSame(base64_encode($raw), $payload['debug']['payload']);
        self::assertSame('base64', $payload['debug']['encoding']);
        self::assertArrayNotHasKey('text', $payload);
    }

    public function testStatusPayloadDoesNotExposeDebugFields(): void
    {
        $payload = RawPayload::status('868705080300697', 'Wonlex', 'HW20PRO', 'online', null, 'Wonlex HW20 Pro');

        self::assertSame([
            'schemaVersion' => 1,
            'state' => 'online',
            'updatedAt' => $payload['updatedAt'],
            'device' => [
                'id' => '868705080300697',
                'supplier' => 'Wonlex',
                'model' => 'HW20PRO',
                'commercialName' => 'Wonlex HW20 Pro',
            ],
        ], $payload);
    }

    public function testEventPayloadDoesNotExposeDebugFields(): void
    {
        $payload = RawPayload::event('868705080300697', 'Wonlex', 'HW20PRO', 'device.connected', null, null, 'Wonlex HW20 Pro');

        self::assertSame([
            'schemaVersion' => 1,
            'type' => 'device.connected',
            'occurredAt' => $payload['occurredAt'],
            'device' => [
                'id' => '868705080300697',
                'supplier' => 'Wonlex',
                'model' => 'HW20PRO',
                'commercialName' => 'Wonlex HW20 Pro',
            ],
        ], $payload);
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
