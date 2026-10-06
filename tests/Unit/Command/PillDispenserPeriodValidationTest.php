<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\Configuration\Payload\ZayataPayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * O aparelho aceita as datas (`0x1004`–`0x1006` e `0x1007`–`0x1009`) sem reclamar da ordem, e um
 * plano ao contrário nunca vale: os alarmes não tocam, sem erro.
 */
final class PillDispenserPeriodValidationTest extends TestCase
{
    public function testAValidPeriodPassesThrough(): void
    {
        self::assertSame(
            ['enabled' => true, 'startDate' => '2026-01-01', 'endDate' => '2026-12-31'],
            ZayataPayloadBuilder::build('medication_period', [
                'enabled' => true,
                'startDate' => '2026-01-01',
                'endDate' => '2026-12-31',
            ]),
        );
    }

    public function testTheSameDayAtBothEndsIsAValidPeriod(): void
    {
        // Um dia só: o plano vale nesse dia e mais nenhum.
        $built = ZayataPayloadBuilder::build('medication_period', [
            'enabled' => true,
            'startDate' => '2026-03-04',
            'endDate' => '2026-03-04',
        ]);

        self::assertSame('2026-03-04', $built['endDate']);
    }

    public function testAPeriodThatEndsBeforeItStartsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('endDate must not be before startDate');

        ZayataPayloadBuilder::build('medication_period', [
            'enabled' => true,
            'startDate' => '2026-12-31',
            'endDate' => '2026-01-01',
        ]);
    }

    /** Vazio é «sem período», e não uma data: metade preenchida não tem ordem a comparar. */
    public function testAnOpenEndedPeriodIsNotCompared(): void
    {
        $built = ZayataPayloadBuilder::build('medication_period', [
            'enabled' => false,
            'startDate' => '2026-12-31',
            'endDate' => '',
        ]);

        self::assertSame('', $built['endDate']);
    }
}
