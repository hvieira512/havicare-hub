<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** O validador recusa à entrada da API e o construtor da trama à saída, com os limites escritos nos dois. */
final class PillDispenserRangesAgreeTest extends TestCase
{
    private const IMEI = '869243062262262';

    /**
     * A ponta da gama e o primeiro valor de fora, por definição.
     *
     * @return iterable<string, array{string, string, string, int, int}>
     */
    public static function ranges(): iterable
    {
        yield 'volume' => ['alarm_volume', 'alarmVolume', 'volume', 3, 4];
        yield 'ringtone' => ['alarm_ringtone', 'alarmRingtone', 'ringtone', 3, 4];
        yield 'language' => ['device_language', 'deviceLanguage', 'language', 1, 2];
        yield 'compartments' => ['loaded_cells', 'loadedCells', 'cells', 28, 29];
        yield 'late warning' => ['retrieval_warning', 'retrievalWarning', 'minutes', 1440, 1441];
        yield 'time until missed' => ['retrieval_timeout', 'retrievalTimeout', 'minutes', 1440, 1441];
        yield 'time zone to the east' => ['time_zone', 'timeZone', 'timeZone', 1400, 1401];
    }

    #[DataProvider('ranges')]
    public function testTheEdgeIsAcceptedByBothLevels(
        string $key,
        string $command,
        string $field,
        int $edge,
    ): void {
        self::assertNull(DeviceConfigurationCatalog::validate('zayata-m228', $key, [$field => $edge]), $key);
        self::assertNotSame('', DeviceCommandCatalog::buildDownlink('zayata-m228', self::IMEI, $command, [$field => $edge]));
    }

    /** E o construtor da trama, a que o painel da dashboard chega sem passar pelo validador. */
    #[DataProvider('ranges')]
    public function testTheFrameBuilderRefusesWhatIsOutside(
        string $key,
        string $command,
        string $field,
        int $edge,
        int $outside,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        DeviceCommandCatalog::buildDownlink('zayata-m228', self::IMEI, $command, [$field => $outside]);
    }

    /** O fuso a oeste tem gama própria, e é a única que é negativa. */
    public function testTheWesternTimeZoneEdgeAgrees(): void
    {
        self::assertNull(DeviceConfigurationCatalog::validate('zayata-m228', 'time_zone', ['timeZone' => -1200]));

        $this->expectException(\InvalidArgumentException::class);
        DeviceCommandCatalog::buildDownlink('zayata-m228', self::IMEI, 'timeZone', ['timeZone' => -1201]);
    }
}
