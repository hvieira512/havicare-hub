<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Device\TelemetryEnvelope;
use PHPUnit\Framework\TestCase;

final class TelemetryEnvelopeRawTest extends TestCase
{
    public function testTheRawEnvelopeKeepsTextDebugDataUnderDebugKey(): void
    {
        $payload = TelemetryEnvelope::raw('865028000000308', 'Vivistar', 'VIVISTAR-CARE', 'tcp', 'vivistar-iw', 'IWAP49,72#', 'uplink', '1', 'Vivistar L08 Pro');

        self::assertArrayNotHasKey('schemaVersion', $payload);
        self::assertSame('uplink', $payload['direction']);
        self::assertSame('865028000000308', $payload['device']['id']);
        self::assertSame('Vivistar', $payload['device']['supplier']);
        self::assertSame('VIVISTAR-CARE', $payload['device']['model']);
        self::assertSame('Vivistar L08 Pro', $payload['device']['commercialName']);
        self::assertSame('text', $payload['debug']['encoding']);
        self::assertSame('IWAP49,72#', $payload['debug']['payload']);
        self::assertSame('1', $payload['debug']['connectionId']);
    }

    public function testTheRawEnvelopeKeepsBinaryDebugDataAsBase64(): void
    {
        $json = json_encode([
            'type' => 'upBO',
            'imei' => '865028000000306',
            'data' => ['data' => '96'],
        ], JSON_THROW_ON_ERROR);
        $raw = pack('nn', 0xFCAF, strlen($json)) . $json;

        $payload = TelemetryEnvelope::raw('865028000000306', 'Wonlex', 'HW20PRO', 'tcp', 'wonlex-json', $raw, 'uplink');

        self::assertSame('base64', $payload['debug']['encoding']);
        self::assertSame([
            'type' => 'upBO',
            'imei' => '865028000000306',
            'data' => ['data' => '96'],
        ], $payload['debug']['payload']);
        self::assertSame(base64_encode($raw), $payload['debug']['encoded']);
        self::assertArrayNotHasKey('text', $payload);
    }

    public function testTheRawEnvelopeDoesNotAddDecodedDataForMalformedWonlexFrame(): void
    {
        $raw = pack('nn', 0xFCAF, 10) . '{}';

        $payload = TelemetryEnvelope::raw('865028000000306', 'Wonlex', 'HW20PRO', 'tcp', 'wonlex-json', $raw, 'uplink');

        self::assertSame('base64', $payload['debug']['encoding']);
        self::assertSame(base64_encode($raw), $payload['debug']['payload']);
        self::assertArrayNotHasKey('encoded', $payload['debug']);
    }

    public function testStatusPayloadDoesNotExposeDebugFields(): void
    {
        $payload = TelemetryEnvelope::status('868705080300697', 'Wonlex', 'HW20PRO', 'online', null, 'Wonlex HW20 Pro');

        self::assertSame([
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
        $payload = TelemetryEnvelope::event('868705080300697', 'Wonlex', 'HW20PRO', 'device.connected', null, null, 'Wonlex HW20 Pro');

        self::assertSame([
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
}
