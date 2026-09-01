<?php

namespace Tests\Unit\Domain\Alarms;

use Hub\Domain\Capability\AlarmClock\AlarmClockHandler;
use Hub\Domain\Capability\AlarmClock\FourPTouch;
use Hub\Domain\Capability\AlarmClock\Vivistar;
use Hub\Domain\Capability\AlarmClock\Wonlex;
use PHPUnit\Framework\TestCase;

/**
 * Descodificar duas vezes tem de dar o mesmo que descodificar uma: o
 * `DeviceCapabilityPresenter` chama o `fromNative` sobre a linha guardada e outra vez quando
 * o `responseEntry` embrulha o que já normalizou, e à segunda o que chega já é a lista
 * pública.
 *
 * É uma propriedade dos três handlers e não de um, e por isso afirma-se sobre os três.
 */
final class AlarmClockHandlerIdempotenceTest extends TestCase
{
    /**
     * @return array<string, array{0: AlarmClockHandler, 1: array<string, mixed>}>
     */
    public static function handlers(): array
    {
        return [
            // Na Vivistar os dias vão numa máquina de dígitos, não em lista: é o que o
            // `formatDayList` escreve e o que o dispositivo devolve.
            'vivistar-iw' => [new Vivistar(), ['items' => [
                ['time' => '07:30', 'enabled' => true, 'days' => '1234567'],
            ]]],
            'wonlex-json' => [new Wonlex(), ['alarmClockList' => [
                ['label' => 'Medicine', 'startTime' => '07:30', 'week' => '1111111', 'status' => '1'],
            ]]],
            'four-p-touch' => [new FourPTouch(), ['alarms' => [
                ['time' => '07:30', 'enabled' => true, 'mode' => 1, 'custom' => ''],
            ]]],
        ];
    }

    /**
     * @param array<string, mixed> $native
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('handlers')]
    public function testDecodingAnAlreadyDecodedValueChangesNothing(
        AlarmClockHandler $handler,
        array $native
    ): void {
        $once = $handler->fromNative($native);

        self::assertNotSame([], $once, 'o payload nativo do teste tem de descodificar');
        self::assertSame('07:30', $once[0]['time'] ?? null);
        self::assertSame($once, $handler->fromNative($once));
    }

    /**
     * O payload por omissão de cada handler é nativo, e é o que o apresentador serve a um
     * dispositivo que nunca guardou nada. Também ele passa pelo `fromNative` duas vezes.
     *
     * @param array<string, mixed> $native
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('handlers')]
    public function testTheDefaultPayloadSurvivesTheSameRoundTrip(
        AlarmClockHandler $handler,
        array $native
    ): void {
        $default = $handler->defaultValue();
        self::assertIsArray($default);

        $once = $handler->fromNative($default);

        self::assertSame($once, $handler->fromNative($once));
    }
}
