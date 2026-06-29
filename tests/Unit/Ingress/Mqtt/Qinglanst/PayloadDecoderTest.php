<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\Ingress\Mqtt\Qinglanst\PayloadDecoder;
use PHPUnit\Framework\TestCase;

final class PayloadDecoderTest extends TestCase
{
    public function testDecodesHeartBreathPayloadWithRawNativeClass(): void
    {
        $decoder = new PayloadDecoder();

        $payload = $decoder->decode('heartbreath', base64_encode($this->bytes([
            0x30, 18, 76, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0b10000000, 0, 0,
        ])), 'radar-uid-1');

        self::assertIsArray($payload);
        self::assertSame('heartbreath', $payload['type']);
        self::assertSame(18, $payload['breathing']);
        self::assertSame(76, $payload['heart_rate']);
        self::assertSame('Deep Sleep', $payload['sleep_state']);
    }

    public function testDecodesPosStaticsPayloadWithRawNativeClass(): void
    {
        $decoder = new PayloadDecoder();

        $payload = $decoder->decode('posstatics', base64_encode($this->bytes([
            0x01, 0x02, 0x03, 0x00, 0x2A, 0x05, 0x06, 0x07, 0x08, 0x09, 0x01, 0, 0, 0, 0, 0,
        ])), 'radar-uid-1');

        self::assertIsArray($payload);
        self::assertSame('posstatics', $payload['type']);
        self::assertSame(2, $payload['version']);
        self::assertSame(3, $payload['people']);
        self::assertSame(42, $payload['walking_distance']);
        self::assertTrue($payload['breathing_active']);
    }

    public function testDecodesHbStaticsPayload(): void
    {
        $decoder = new PayloadDecoder();

        $payload = $decoder->decode('hbstatics', base64_encode($this->bytes([
            0x01, 12, 66, 0, 0, 17, 70, 0, 0, 0, 0, 0, 0, 0b01111111, 0, 0,
        ])), 'radar-uid-1');

        self::assertIsArray($payload);
        self::assertSame('hbstatics', $payload['type']);
        self::assertSame('Apnea', $payload['breathing_status_per_minute']);
        self::assertSame('Undefined', $payload['heart_rate_status_per_minute']);
        self::assertSame('Weak', $payload['vital_signs_status']);
        self::assertSame('Light Sleep', $payload['sleep_state_status']);
    }

    public function testDecodesPositionPayload(): void
    {
        $decoder = new PayloadDecoder();

        $payload = $decoder->decode('position', base64_encode($this->bytes([
            0x01, 0xFF, 0x02, 0x03, 0, 0, 0, 0, 0, 0, 0, 0, 0x04, 0x05, 0x01, 0x09,
        ])), 'radar-uid-1');

        self::assertIsArray($payload);
        self::assertSame('position', $payload['type']);
        self::assertSame(-1, $payload['people'][0]['x_position_dm']);
        self::assertSame('Fall Confirmation', $payload['people'][0]['posture_state']);
        self::assertSame('Enter Room', $payload['people'][0]['last_event']);
    }

    /**
     * @param list<int> $bytes
     */
    private function bytes(array $bytes): string
    {
        return implode('', array_map(static fn (int $byte): string => chr($byte), $bytes));
    }
}
