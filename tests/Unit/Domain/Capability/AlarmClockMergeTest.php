<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Domain\Capability\AlarmClock\AlarmClockCapability;
use PHPUnit\Framework\TestCase;

/** Dois despertadores concorrentes acumulam-se. */
final class AlarmClockMergeTest extends TestCase
{
    public function testConcurrentAlarmsAccumulate(): void
    {
        $handler = new AlarmClockCapability();

        self::assertSame(
            [['at' => '07:00'], ['at' => '21:30']],
            $handler->merge([['at' => '07:00']], [['at' => '21:30']]),
        );
    }

    public function testANonListSideCountsAsEmpty(): void
    {
        $handler = new AlarmClockCapability();

        self::assertSame([['at' => '07:00']], $handler->merge(null, [['at' => '07:00']]));
        self::assertSame([['at' => '07:00']], $handler->merge([['at' => '07:00']], null));
        self::assertSame([], $handler->merge(null, null));
    }

    public function testTheResultIsAlwaysAList(): void
    {
        $handler = new AlarmClockCapability();

        self::assertSame(
            ['a', 'b'],
            $handler->merge(['x' => 'a'], ['y' => 'b']),
            'chaves de texto dos dois lados não podem sobreviver ao merge',
        );
    }
}
