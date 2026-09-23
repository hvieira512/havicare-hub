<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\TestCase;

/**
 * O que o aparelho recusaria é recusado à entrada.
 *
 * O `ZayataPayloadBuilder` existe para isso, e as gamas dele estavam declaradas sem nenhum
 * teste lhes dar um valor ilegal — a recusa acontecia por sorte ou não acontecia de todo. O
 * protocolo responde a um valor fora da gama com um estado no TFLV, e uma escrita recusada
 * pelo aparelho fica em «em envio» até alguém reparar.
 */
final class PillDispenserValidationRangesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function illegalValues(): iterable
    {
        yield 'volume acima da escala' => ['alarm_volume', ['volume' => 4]];
        yield 'volume negativo' => ['alarm_volume', ['volume' => -1]];
        yield 'toque acima da escala' => ['alarm_ringtone', ['ringtone' => 5]];
        yield 'idioma que não existe' => ['device_language', ['language' => 2]];
        yield 'fuso a leste de +1400' => ['time_zone', ['timeZone' => 1500]];
        yield 'fuso a oeste de -1200' => ['time_zone', ['timeZone' => -1300]];
        yield 'hora de silêncio acima das 23' => ['do_not_disturb', ['startHour' => 24]];
        yield 'minuto de silêncio acima dos 59' => ['do_not_disturb', ['endMinute' => 60]];
        yield 'data mal formada' => ['medication_period', ['startDate' => '02-09-2026', 'endDate' => '2026-09-04']];
        yield 'data que não existe' => ['medication_period', ['startDate' => '2026-02-31', 'endDate' => '2026-03-01']];
        yield 'hora de alarme acima das 23' => ['medication_reminders', ['plans' => [['hour' => 24, 'minute' => 0]]]];
        yield 'minuto de alarme acima dos 59' => ['medication_reminders', ['plans' => [['hour' => 8, 'minute' => 60]]]];
        yield 'mais alarmes do que o aparelho tem' => ['medication_reminders', ['plans' => array_fill(0, 10, ['hour' => 8, 'minute' => 0])]];
        yield 'plano que não é lista' => ['medication_reminders', ['plans' => 'oito e meia']];
        yield 'slot fora dos nove' => ['medication_reminders', ['plans' => [['slot' => 10, 'hour' => 8, 'minute' => 0]]]];
        yield 'minutos acima de um dia' => ['retrieval_warning', ['minutes' => 1441]];
        yield 'minutos negativos' => ['retrieval_timeout', ['minutes' => -1]];
        yield 'mais células do que o prato tem' => ['loaded_cells', ['cells' => 29]];
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
        yield 'volume no silêncio' => ['alarm_volume', ['volume' => 3]];
        yield 'toque nenhum' => ['alarm_ringtone', ['ringtone' => 0]];
        yield 'fuso na ponta leste' => ['time_zone', ['timeZone' => 1400]];
        yield 'fuso na ponta oeste' => ['time_zone', ['timeZone' => -1200]];
        yield 'um dia inteiro de espera' => ['retrieval_warning', ['minutes' => 1440]];
        yield 'o prato todo carregado' => ['loaded_cells', ['cells' => 28]];
        yield 'os nove alarmes' => ['medication_reminders', ['plans' => array_fill(0, 9, ['hour' => 8, 'minute' => 0])]];
        yield 'período sem datas' => ['medication_period', ['enabled' => false, 'startDate' => '', 'endDate' => '']];
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
