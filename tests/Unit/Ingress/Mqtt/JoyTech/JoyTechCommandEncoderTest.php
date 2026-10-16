<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\JoyTech;

use DateTimeImmutable;
use Hub\Ingress\Mqtt\JoyTech\BpFrame;
use Hub\Ingress\Mqtt\JoyTech\JoyTechCommandEncoder;
use PHPUnit\Framework\TestCase;

/** As tramas são as que a sonda escreveu e leu no DBP-6292B a 2026-10-16. */
final class JoyTechCommandEncoderTest extends TestCase
{
    /** As 14:46 de Lisboa: a hora vai em hora local, ano em dois dígitos. */
    public function testTheClockGoesInLocalTime(): void
    {
        self::assertSame(
            [['char' => 'fff2', 'hex' => 'faaaaaaf000f041a0a100e2e83f55f']],
            (new JoyTechCommandEncoder())->writes('calibrateClock', [], 23, new DateTimeImmutable('2026-10-16T13:46:00Z'), 60),
        );
    }

    /** A leitura da memória só acaba com o `0x8B`, que chega depois de todos os registos. */
    public function testReadingTheMemoryWaitsForItsEnd(): void
    {
        self::assertSame(
            [['char' => 'fff2', 'hex' => 'faaaaaaf000a0a14f55f', 'await' => ['char' => 'fff1', 'prefix' => 'faaaaaaf000a8b', 'timeoutMs' => 30000]]],
            (new JoyTechCommandEncoder())->writes('readBloodPressureHistory', [], 23, new DateTimeImmutable(), 60),
        );
    }

    public function testAFrameIsDecodedOnlyWhenItsChecksumAgrees(): void
    {
        self::assertSame(['function' => 0x8b, 'data' => ''], BpFrame::decode('fa aa aa af 00 0a 8b 95 f5 5f'));
        self::assertSame(0x83, BpFrame::decode('faaaaaaf0016830088 00494c001a0a100e33012cf55f')['function'] ?? null);
        self::assertNull(BpFrame::decode('faaaaaaf0016830088 00494c001a0a100e33012df55f'));
        // A metade que chega sem o fim, quando o gateway não a juntou.
        self::assertNull(BpFrame::decode('faaaaaaf00168300880049 4c001a0a100e33012c'));
    }
}
