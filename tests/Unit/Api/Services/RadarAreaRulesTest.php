<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Services;

use Hub\Api\Services\RadarAreaRules;
use PHPUnit\Framework\TestCase;

final class RadarAreaRulesTest extends TestCase
{
    private const ROOM = ['xMinDm' => -20, 'yMinDm' => 0, 'xMaxDm' => 20, 'yMaxDm' => 30];

    /** @return array<string, mixed> */
    private static function area(string|int $type, int $xMin, int $yMin, int $xMax, int $yMax): array
    {
        return ['key' => 0, 'type' => $type, 'xMinDm' => $xMin, 'yMinDm' => $yMin, 'xMaxDm' => $xMax, 'yMaxDm' => $yMax];
    }

    public function testAreasThatKeepTheRulesHaveNoViolations(): void
    {
        self::assertSame([], RadarAreaRules::violations([
            self::area('monitoring_bed', -5, 5, 5, 20),
            self::area('door', 18, 26, 20, 30),
            self::area('interference', -4, 6, 0, 10),
        ], self::ROOM));
    }

    /** O tipo ainda pode chegar pelo número do fabricante: 4 é a porta, 5 a cama de monitorização. */
    public function testTheVendorTypeNumbersReadAsTheirNames(): void
    {
        self::assertSame(
            ['items[1]' => ['door_off_wall'], 'items[2]' => ['second_monitoring_bed']],
            RadarAreaRules::violations([
                self::area(5, -5, 5, 5, 20),
                self::area(4, 0, 10, 3, 13),
                self::area(5, 6, 5, 12, 20),
            ], self::ROOM),
        );
    }

    /** Sem planta guardada não se sabe onde estão as paredes. */
    public function testWithoutARoomOnlyTheRoomlessRulesApply(): void
    {
        self::assertSame([], RadarAreaRules::violations([self::area('door', 100, 100, 103, 103)], null));
    }
}
