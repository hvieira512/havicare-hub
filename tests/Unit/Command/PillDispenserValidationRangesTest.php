<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Cada gama do `ZayataPayloadBuilder` leva aqui um valor ilegal: uma escrita que o aparelho recuse
 * fica «em envio» até alguém reparar.
 */
final class PillDispenserValidationRangesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function illegalValues(): iterable
    {
        yield 'volume above the scale' => ['alarm_volume', ['volume' => 4]];
        yield 'negative volume' => ['alarm_volume', ['volume' => -1]];
        yield 'ringtone above the scale' => ['alarm_ringtone', ['ringtone' => 4]];
        yield 'language that does not exist' => ['device_language', ['language' => 2]];
        yield 'time zone east of +1400' => ['time_zone', ['timeZone' => 1500]];
        yield 'time zone west of -1200' => ['time_zone', ['timeZone' => -1300]];
        yield 'quiet hour above 23' => ['do_not_disturb', ['startHour' => 24]];
        yield 'quiet minute above 59' => ['do_not_disturb', ['endMinute' => 60]];
        yield 'malformed date' => ['medication_period', ['startDate' => '02-09-2026', 'endDate' => '2026-09-04']];
        yield 'date that does not exist' => ['medication_period', ['startDate' => '2026-02-31', 'endDate' => '2026-03-01']];
        yield 'alarm hour above 23' => ['medication_reminders', ['plans' => [['hour' => 24, 'minute' => 0]]]];
        yield 'alarm minute above 59' => ['medication_reminders', ['plans' => [['hour' => 8, 'minute' => 60]]]];
        yield 'more alarms than the device has' => ['medication_reminders', ['plans' => array_fill(0, 10, ['hour' => 8, 'minute' => 0])]];
        yield 'plan that is not a list' => ['medication_reminders', ['plans' => 'oito e meia']];
        yield 'slot outside the nine' => ['medication_reminders', ['plans' => [['slot' => 10, 'hour' => 8, 'minute' => 0]]]];
        yield 'minutes above a day' => ['retrieval_warning', ['minutes' => 1441]];
        yield 'negative minutes' => ['retrieval_timeout', ['minutes' => -1]];
        yield 'more cells than the tray has' => ['loaded_cells', ['cells' => 29]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('illegalValues')]
    public function testAnIllegalValueIsRefused(string $key, array $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeviceConfigurationCatalog::commandPayloads('zayata-m228', $key, $payload);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function legalEdges(): iterable
    {
        yield 'no ringtone' => ['alarm_ringtone', ['ringtone' => 0]];
        yield 'all nine alarms' => ['medication_reminders', ['plans' => array_fill(0, 9, ['hour' => 8, 'minute' => 0])]];
        yield 'period without dates' => ['medication_period', ['enabled' => false, 'startDate' => '', 'endDate' => '']];
    }

    /**
     * A ponta da gama passa. Sem isto, apertar a validação de mais passava despercebido.
     *
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('legalEdges')]
    public function testTheEdgeOfTheRangeIsAccepted(string $key, array $payload): void
    {
        self::assertNotSame([], DeviceConfigurationCatalog::commandPayloads('zayata-m228', $key, $payload));
    }
}
