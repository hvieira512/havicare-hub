<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use DateTimeImmutable;
use Hub\Domain\Capability\Medication\MedicationOccurrence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** O dispensador conta as horas no relógio dele; o contrato fala UTC, pelo fuso que o hub confirmou. */
final class MedicationOccurrenceTest extends TestCase
{
    private const PLAN = ['plans' => [
        ['name' => 'Metformina', 'times' => [['time' => '08:00', 'enabled' => true, 'slot' => 2, 'recurrence' => ['kind' => 'daily']]]],
        ['times' => [['time' => '22:30', 'enabled' => true, 'slot' => 5, 'recurrence' => ['kind' => 'daily']]]],
    ]];

    public function testIntakeTimesBecomeUtcThroughANumericZone(): void
    {
        $data = MedicationOccurrence::intake(
            ['slot' => 2, 'scheduledAt' => '2026-10-19T08:00:00', 'takenAt' => '2026-10-19T08:04:10', 'result' => 'on_time'],
            ['time_zone' => ['timeZone' => 100]],
        );

        self::assertSame('2026-10-19T07:00:00Z', $data['scheduledAt']);
        self::assertSame('2026-10-19T07:04:10Z', $data['takenAt']);
        self::assertSame('on_time', $data['result']);
    }

    /** Uma zona com nome vale pela hora de verão do dia da toma, e não pela de hoje. */
    public function testANamedZoneUsesTheOffsetOfTheIntakeDate(): void
    {
        $zone = ['time_zone' => ['timeZone' => 'Europe/Lisbon']];

        self::assertSame('2026-07-01T07:00:00Z', MedicationOccurrence::intake(['scheduledAt' => '2026-07-01T08:00:00'], $zone)['scheduledAt']);
        self::assertSame('2026-12-01T08:00:00Z', MedicationOccurrence::intake(['scheduledAt' => '2026-12-01T08:00:00'], $zone)['scheduledAt']);
    }

    /** Sem fuso confirmado a hora do aparelho não se pode dizer em UTC, e não sai. */
    public function testWithoutAConfirmedZoneTheIntakeTimesAreLeftOut(): void
    {
        $data = MedicationOccurrence::intake(
            ['slot' => 2, 'scheduledAt' => '2026-10-19T08:00:00', 'takenAt' => '2026-10-19T08:04:10', 'result' => 'on_time'],
            [],
        );

        self::assertSame(['slot' => 2, 'result' => 'on_time'], $data);
    }

    public function testAnAlarmChangeGainsTheOccurrenceOfItsSlot(): void
    {
        $data = MedicationOccurrence::alarmChange(
            ['slot' => 2, 'state' => 'taken'],
            ['medication_reminders' => self::PLAN, 'time_zone' => ['timeZone' => 100]],
            new DateTimeImmutable('2026-10-19T07:10:00Z'),
        );

        self::assertSame(
            ['slot' => 2, 'state' => 'taken', 'scheduledAt' => '2026-10-19T07:00:00Z', 'doseDate' => '2026-10-19'],
            $data,
        );
    }

    /** A dose das 22:30 que se dá por falhada depois da meia-noite é a da véspera. */
    public function testAChangeAfterMidnightBelongsToTheNearestOccurrence(): void
    {
        $data = MedicationOccurrence::alarmChange(
            ['slot' => 5, 'state' => 'missed'],
            ['medication_reminders' => self::PLAN, 'time_zone' => ['timeZone' => 100]],
            new DateTimeImmutable('2026-10-19T23:20:00Z'),
        );

        self::assertSame('2026-10-19T21:30:00Z', $data['scheduledAt']);
        self::assertSame('2026-10-19', $data['doseDate']);
    }

    /** A toma antecipada sai antes da hora: a dose é a que vem. */
    public function testAnEarlyDispenseBelongsToTheComingOccurrence(): void
    {
        $data = MedicationOccurrence::alarmChange(
            ['slot' => 2, 'state' => 'preparing'],
            ['medication_reminders' => self::PLAN, 'time_zone' => ['timeZone' => 100]],
            new DateTimeImmutable('2026-10-19T05:30:00Z'),
        );

        self::assertSame('2026-10-19T07:00:00Z', $data['scheduledAt']);
        self::assertSame('2026-10-19', $data['doseDate']);
    }

    /** Também com o plano na forma nativa, como o aparelho o reporta. */
    public function testTheNativePlanShapeAnswersTheSame(): void
    {
        $data = MedicationOccurrence::alarmChange(
            ['slot' => 2, 'state' => 'taken'],
            ['medication_reminders' => ['plans' => [['slot' => 2, 'hour' => 8, 'minute' => 0]]], 'time_zone' => ['timeZone' => 0]],
            new DateTimeImmutable('2026-10-19T08:10:00Z'),
        );

        self::assertSame('2026-10-19T08:00:00Z', $data['scheduledAt']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unknownOccurrences(): iterable
    {
        yield 'no confirmed zone' => [['medication_reminders' => self::PLAN]];
        yield 'slot not in the plan' => [['medication_reminders' => ['plans' => []], 'time_zone' => ['timeZone' => 100]]];
        yield 'no confirmed plan' => [['time_zone' => ['timeZone' => 100]]];
    }

    /** @param array<string, mixed> $configurations */
    #[DataProvider('unknownOccurrences')]
    public function testAnOccurrenceThatCannotBeWorkedOutIsLeftOut(array $configurations): void
    {
        $data = MedicationOccurrence::alarmChange(
            ['slot' => 2, 'state' => 'taken'],
            $configurations,
            new DateTimeImmutable('2026-10-19T07:10:00Z'),
        );

        self::assertSame(['slot' => 2, 'state' => 'taken'], $data);
    }
}
