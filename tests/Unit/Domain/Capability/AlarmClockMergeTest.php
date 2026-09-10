<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Domain\Capability\AlarmClock\AlarmClockCapability;
use Hub\Domain\Capability\AlarmClock\FourPTouch;
use Hub\Domain\Capability\AlarmClock\Vivistar;
use PHPUnit\Framework\TestCase;

/**
 * Dois despertadores concorrentes acumulam-se; um não substitui o outro.
 *
 * A regra estava escrita três vezes, uma por classe, e agora vem do `AlarmClockHelpers` que
 * as três já partilhavam. O teste percorre as três para que a partilha não possa regredir
 * para uma delas em silêncio -- um método declarado na classe ganha ao do trait sem erro.
 */
final class AlarmClockMergeTest extends TestCase
{
    /** @return list<array{0: object}> */
    public static function handlers(): array
    {
        return [
            'capability' => [new AlarmClockCapability()],
            'four-p-touch' => [new FourPTouch()],
            'vivistar' => [new Vivistar()],
        ];
    }

    /** @dataProvider handlers */
    public function testConcurrentAlarmsAccumulate(object $handler): void
    {
        self::assertSame(
            [['at' => '07:00'], ['at' => '21:30']],
            $handler->merge([['at' => '07:00']], [['at' => '21:30']]),
        );
    }

    /** @dataProvider handlers */
    public function testANonListSideCountsAsEmpty(object $handler): void
    {
        self::assertSame([['at' => '07:00']], $handler->merge(null, [['at' => '07:00']]));
        self::assertSame([['at' => '07:00']], $handler->merge([['at' => '07:00']], null));
        self::assertSame([], $handler->merge(null, null));
    }

    /** @dataProvider handlers */
    public function testTheResultIsAlwaysAList(object $handler): void
    {
        self::assertSame(
            ['a', 'b'],
            $handler->merge(['x' => 'a'], ['y' => 'b']),
            'chaves de texto dos dois lados não podem sobreviver ao merge',
        );
    }
}
