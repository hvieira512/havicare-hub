<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Os dois níveis que guardam uma gama recusam o mesmo valor.
 *
 * O validador recusa à entrada da API e o construtor da trama recusa à saída, e os limites
 * estão escritos nos dois. Apertar um sem o outro passava despercebido: nenhum teste os punha
 * lado a lado.
 */
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
        yield 'toque' => ['alarm_ringtone', 'alarmRingtone', 'ringtone', 4, 5];
        yield 'idioma' => ['device_language', 'deviceLanguage', 'language', 1, 2];
        yield 'compartimentos' => ['loaded_cells', 'loadedCells', 'cells', 28, 29];
        yield 'aviso de atraso' => ['retrieval_warning', 'retrievalWarning', 'minutes', 1440, 1441];
        yield 'tempo até falhar' => ['retrieval_timeout', 'retrievalTimeout', 'minutes', 1440, 1441];
        yield 'fuso a leste' => ['time_zone', 'timeZone', 'timeZone', 1400, 1401];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ranges')]
    public function testTheEdgeIsAcceptedByBothLevels(
        string $key,
        string $command,
        string $field,
        int $edge,
    ): void {
        self::assertNull(DeviceConfigurationCatalog::validate('zayata-m228', $key, [$field => $edge]), $key);
        self::assertNotSame('', DeviceCommandCatalog::buildDownlink('zayata-m228', self::IMEI, $command, [$field => $edge]));
    }

    /** O validador recusa o primeiro valor de fora. */
    #[\PHPUnit\Framework\Attributes\DataProvider('ranges')]
    public function testTheValidatorRefusesWhatIsOutside(
        string $key,
        string $command,
        string $field,
        int $edge,
        int $outside,
    ): void {
        self::assertNotNull(
            DeviceConfigurationCatalog::validate('zayata-m228', $key, [$field => $outside]),
            $key,
        );
    }

    /**
     * E o construtor da trama também.
     *
     * É a metade que faltava: o validador pode ser contornado -- o painel da dashboard chama
     * o construtor por outros caminhos -- e um limite só de um lado não é limite.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ranges')]
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
        self::assertNotNull(DeviceConfigurationCatalog::validate('zayata-m228', 'time_zone', ['timeZone' => -1201]));

        $this->expectException(\InvalidArgumentException::class);
        DeviceCommandCatalog::buildDownlink('zayata-m228', self::IMEI, 'timeZone', ['timeZone' => -1201]);
    }
}
