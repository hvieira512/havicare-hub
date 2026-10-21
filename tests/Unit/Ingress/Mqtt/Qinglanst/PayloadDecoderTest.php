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
        self::assertSame('deep_sleep', $payload['sleep_state']);
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
        self::assertSame('apnea', $payload['breathing_status_per_minute']);
        self::assertSame('undefined', $payload['heart_rate_status_per_minute']);
        self::assertSame('weak', $payload['vital_signs_status']);
        self::assertSame('light_sleep', $payload['sleep_state_status']);
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
        self::assertSame('fall_confirmation', $payload['people'][0]['posture_state']);
        self::assertSame('enter_room', $payload['people'][0]['last_event']);
    }

    /** O fabricante chama-lhe «Squatting», mas é a postura de quem está sentado numa cadeira. */
    public function testPostureThreeIsSitting(): void
    {
        $decoder = new PayloadDecoder();

        $payload = $decoder->decode('position', base64_encode($this->bytes([
            0x01, 0x00, 0x00, 0x00, 0, 0, 0, 0, 0, 0, 0, 0, 0x00, 0x03, 0x00, 0x00,
        ])), 'radar-uid-1');

        self::assertIsArray($payload);
        self::assertSame('sitting', $payload['people'][0]['posture_state']);
    }

    /** O firmware pode acrescentar estados, e um código desconhecido sai na enumeração de reserva em vez de se deitar fora. */
    public function testAnUnknownCodeFallsBackWithoutLosingTheReading(): void
    {
        $decoder = new PayloadDecoder();

        $payload = $decoder->decode('position', base64_encode($this->bytes([
            0x01, 0x00, 0x00, 0x00, 0, 0, 0, 0, 0, 0, 0, 0, 0x00, 0x7F, 0x7F, 0x00,
        ])), 'radar-uid-1');

        self::assertIsArray($payload);
        self::assertSame('unknown', $payload['people'][0]['posture_state']);
        self::assertSame('unknown', $payload['people'][0]['last_event']);
    }

    /** Cada pessoa ocupa 16 bytes: um comprimento que não seja múltiplo de 16 não se lê. */
    public function testAPositionWithATruncatedPersonIsNotDecoded(): void
    {
        $payload = (new PayloadDecoder())->decode('position', base64_encode(str_repeat("\x01", 20)), 'radar-uid-1');

        self::assertNull($payload);
    }

    /**
     * @param list<int> $bytes
     */
    private function bytes(array $bytes): string
    {
        return implode('', array_map(static fn (int $byte): string => chr($byte), $bytes));
    }
}
