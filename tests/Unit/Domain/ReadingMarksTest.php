<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\ReadingMarks;
use PHPUnit\Framework\TestCase;

/** As leituras que não são ao vivo ou não são de uma pessoa viva saem marcadas, para quem aplica limites. */
final class ReadingMarksTest extends TestCase
{
    private const NOW = 1_793_000_000;

    public function testABodyTemperatureBelowThirtyFourIsImplausible(): void
    {
        $marked = ReadingMarks::mark($this->reading('temperature', ['bodyCelsius' => 33.9, 'site' => 'forehead']), self::NOW);

        self::assertSame(['bodyCelsius' => 33.9, 'site' => 'forehead', 'implausible' => true], $marked['data']);
    }

    public function testAFeverIsALiveReading(): void
    {
        $reading = $this->reading('temperature', ['bodyCelsius' => 38.4]);

        self::assertSame($reading, ReadingMarks::mark($reading, self::NOW));
    }

    /** A pele e o ar não são a temperatura do corpo, e descem com o quarto. */
    public function testASkinOnlyTemperatureIsNotJudged(): void
    {
        $reading = $this->reading('temperature', ['surfaceCelsius' => 30.1]);

        self::assertSame($reading, ReadingMarks::mark($reading, self::NOW));
    }

    public function testAReadingMeasuredMoreThanHalfAnHourBeforeItArrivedIsFromHistory(): void
    {
        $marked = ReadingMarks::mark($this->reading('blood_oxygen', ['spo2Percent' => 88], self::NOW - 1801), self::NOW);

        self::assertTrue($marked['data']['fromHistory'] ?? null);
    }

    public function testAReadingFromTheLastHalfHourIsLive(): void
    {
        $reading = $this->reading('blood_oxygen', ['spo2Percent' => 88], self::NOW - 1800);

        self::assertSame($reading, ReadingMarks::mark($reading, self::NOW));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function reading(string $type, array $data, int $at = self::NOW): array
    {
        return ['type' => $type, 'occurredAt' => gmdate('Y-m-d\TH:i:s\Z', $at), 'data' => $data];
    }
}
