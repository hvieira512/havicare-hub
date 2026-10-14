<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use DateTimeImmutable;
use Hub\Domain\Capability\TimeZoneOffset;
use PHPUnit\Framework\TestCase;

final class TimeZoneOffsetTest extends TestCase
{
    public function testLisbonIsAnHourAheadInSummerAndUtcInWinter(): void
    {
        self::assertSame(100, TimeZoneOffset::hhmm('Europe/Lisbon', new DateTimeImmutable('2026-10-24T12:00:00Z')));
        self::assertSame(0, TimeZoneOffset::hhmm('Europe/Lisbon', new DateTimeImmutable('2026-10-25T12:00:00Z')));
    }

    /** Os minutos vão nos dois últimos algarismos, com o sinal do conjunto. */
    public function testAHalfHourWestOfUtcKeepsItsSign(): void
    {
        self::assertSame(-330, TimeZoneOffset::hhmm('America/St_Johns', new DateTimeImmutable('2026-01-15T12:00:00Z')));
    }

    public function testAnUnknownZoneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TimeZoneOffset::hhmm('Lisboa', new DateTimeImmutable());
    }
}
