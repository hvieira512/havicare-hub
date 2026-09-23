<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * As definições da toma que o aparelho suporta e o hub não expunha.
 *
 * Os dois tempos — quando avisar de atraso e quando desistir — decidem se uma dose por tomar
 * chega a alguém como alerta. São expostos em **minutos** e não nos segundos que o aparelho
 * quer: a conversão é trabalho do hub.
 */
final class PillDispenserRetrievalSettingsTest extends TestCase
{
    private const IMEI = '869243062262262';

    public function testTheWarningTimeTravelsInSeconds(): void
    {
        $tlv = $this->build('retrievalWarning', ['minutes' => 45], 0x06);

        self::assertSame(45 * 60, unpack('V', (string)$tlv[0x1017]['value'])[1]);
        self::assertSame(PillDispenserAdapter::T_INT32U, $tlv[0x1017]['type']);
    }

    public function testTheMissedTimeTravelsInSeconds(): void
    {
        $tlv = $this->build('retrievalTimeout', ['minutes' => 90], 0x06);

        self::assertSame(90 * 60, unpack('V', (string)$tlv[0x1018]['value'])[1]);
    }

    /** O aparelho aceita até 86400 segundos, que são 1440 minutos. */
    public function testATimeBeyondADayIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->build('retrievalTimeout', ['minutes' => 1441], 0x06);
    }

    public function testTheLoadedCellCountIsWritten(): void
    {
        $tlv = $this->build('loadedCells', ['cells' => 28], 0x06);

        self::assertSame(28, ord((string)$tlv[0x101C]['value']));
    }

    /** O prato tem 28 compartimentos e não há um 29.º. */
    public function testMoreCellsThanTheTrayHasIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->build('loadedCells', ['cells' => 29], 0x06);
    }

    /** E as três aparecem no catálogo, senão não há por onde as usar. */
    public function testTheyAreAllInTheCatalogue(): void
    {
        $keys = array_column(DeviceConfigurationCatalog::configsForProtocol('zayata-m228'), 'key');

        foreach (['retrieval_warning', 'retrieval_timeout', 'loaded_cells'] as $key) {
            self::assertContains($key, $keys, $key);
        }
    }

    /**
     * Rodar até um compartimento e pausar a medicação não entram.
     *
     * Estão na especificação da série M2, mas foram acrescentadas numa versão posterior à que
     * o aparelho de ensaio corre: ele recusa-as com «TAG inválida» e a descoberta de
     * parâmetros não as anuncia. O hub ficava a retentá-las de minuto a minuto.
     */
    public function testTheOnesThisFirmwareRefusesAreNotOffered(): void
    {
        $keys = array_column(DeviceConfigurationCatalog::configsForProtocol('zayata-m228'), 'key');

        self::assertNotContains('rotate_to_cell', $keys);
        self::assertNotContains('medication_pause', $keys);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{value?: string, type?: int}>
     */
    private function build(string $command, array $payload, int $packetType): array
    {
        $decoded = (new PillDispenserAdapter())->decodeIncoming(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::IMEI, $command, $payload)
        );

        self::assertIsArray($decoded);
        self::assertSame($packetType, $decoded['packetType'], $command);

        return $decoded['tlv'];
    }
}
