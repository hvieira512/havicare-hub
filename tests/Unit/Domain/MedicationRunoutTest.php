<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use DateTimeImmutable;
use Hub\Domain\Capability\Medication\MedicationRunout;
use PHPUnit\Framework\TestCase;

final class MedicationRunoutTest extends TestCase
{
    private const NOW = '2026-10-14T09:00:00Z';

    public function testTheLastLoadedDoseFallsOnThePlanInTheDeviceTimeZone(): void
    {
        $configurations = [
            'medication_reminders' => ['plans' => [
                ['slot' => 1, 'hour' => 8, 'minute' => 0],
                ['slot' => 2, 'hour' => 20, 'minute' => 0],
            ]],
            'time_zone' => ['timeZone' => 100],
        ];

        // São 10:00 no aparelho: as 08:00 de hoje já passaram.
        self::assertSame('2026-10-15T20:00:00+01:00', $this->runout(3, $configurations));
    }

    /** O valor guardado chega na forma pública quando foi escrito por ela. */
    public function testThePublicPlanShapeCountsTheSame(): void
    {
        $configurations = [
            'medication_reminders' => ['plans' => [
                ['times' => [['time' => '08:00', 'enabled' => true]]],
                ['times' => [['time' => '13:00', 'enabled' => false]]],
            ]],
        ];

        self::assertSame('2026-10-16T08:00:00+00:00', $this->runout(2, $configurations));
    }

    public function testThePeriodBoundsTheDoses(): void
    {
        $plan = ['medication_reminders' => ['plans' => [['hour' => 8, 'minute' => 0]]]];

        self::assertSame('2026-10-21T08:00:00+00:00', $this->runout(2, $plan + [
            'medication_period' => ['enabled' => true, 'startDate' => '2026-10-20', 'endDate' => ''],
        ]));
        self::assertNull($this->runout(5, $plan + [
            'medication_period' => ['enabled' => true, 'startDate' => '2026-10-01', 'endDate' => '2026-10-16'],
        ]));
    }

    public function testWithoutPlanOrDosesThereIsNoDate(): void
    {
        self::assertNull($this->runout(5, []));
        self::assertNull($this->runout(0, ['medication_reminders' => ['plans' => [['hour' => 8, 'minute' => 0]]]]));
    }

    /** @param array<string, mixed> $configurations */
    private function runout(int $doses, array $configurations): ?string
    {
        return MedicationRunout::at($doses, $configurations, new DateTimeImmutable(self::NOW));
    }
}
