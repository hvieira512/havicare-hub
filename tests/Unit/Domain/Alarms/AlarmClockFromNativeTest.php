<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Alarms;

use Hub\Domain\Capability\AlarmClock\FourPTouch;
use Hub\Domain\Capability\AlarmClock\Vivistar;
use PHPUnit\Framework\TestCase;

/**
 * A leitura da lista de alarmes, e sobretudo a chave por que cada protocolo começa.
 *
 * O corpo é partilhado pelos dois handlers, mas a ordem de precedência é oposta: a 4P Touch
 * procura `alarms` primeiro e a Vivistar `items`. É a única diferença entre eles, e é
 * exactamente o que uma extracção descuidada achataria sem nada ficar vermelho.
 */
final class AlarmClockFromNativeTest extends TestCase
{
    private const ALARM = ['time' => '07:30', 'enabled' => true, 'mode' => 1, 'custom' => ''];
    private const OTHER = ['time' => '21:00', 'enabled' => true, 'mode' => 1, 'custom' => ''];

    public function testFourPTouchPrefersAlarmsOverItems(): void
    {
        $result = (new FourPTouch())->fromNative([
            'items' => [self::OTHER],
            'alarms' => [self::ALARM],
        ]);

        self::assertSame('07:30', $result[0]['time'] ?? null);
    }

    public function testVivistarPrefersItemsOverAlarms(): void
    {
        $result = (new Vivistar())->fromNative([
            'items' => [['time' => '07:30', 'enabled' => true, 'days' => '1234567']],
            'alarms' => [['time' => '21:00', 'enabled' => true, 'days' => '1234567']],
        ]);

        self::assertSame('07:30', $result[0]['time'] ?? null);
    }

    public function testABareListIsAcceptedWithoutAWrappingKey(): void
    {
        self::assertNotSame([], (new FourPTouch())->fromNative([self::ALARM]));
        self::assertNotSame([], (new Vivistar())->fromNative([
            ['time' => '07:30', 'enabled' => true, 'days' => '1234567'],
        ]));
    }

    /** Um item que já é público sai como entrou: é o que torna a leitura idempotente. */
    public function testAlreadyPublicItemsPassThroughUntouched(): void
    {
        $public = [['time' => '07:30', 'enabled' => true, 'recurrence' => [1, 2, 3]]];

        self::assertSame($public, (new FourPTouch())->fromNative(['alarms' => $public]));
        self::assertSame($public, (new Vivistar())->fromNative(['items' => $public]));
    }

    public function testASingleItemIsWrappedIntoAList(): void
    {
        $result = (new FourPTouch())->fromNative(['alarms' => self::ALARM]);

        self::assertArrayHasKey(0, $result);
        self::assertSame('07:30', $result[0]['time'] ?? null);
    }

    public function testAValueThatIsNotAnArrayYieldsAnEmptyList(): void
    {
        self::assertSame([], (new FourPTouch())->fromNative(['alarms' => 'nada']));
        self::assertSame([], (new Vivistar())->fromNative(['items' => 'nada']));
    }
}
