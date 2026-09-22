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
 * chega a alguém como alerta ou fica em silêncio. De fábrica são trinta e sessenta minutos, e
 * até agora só se mudavam por script. As outras três dizem quantos compartimentos estão
 * carregados, mandam o prato rodar até um deles, e suspendem a medicação por uns minutos.
 *
 * Os tempos são expostos em **minutos** e não nos segundos que o aparelho quer: quem marca
 * uma janela de medicação pensa em minutos, e a conversão é trabalho do hub.
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

    /** Rodar é uma ordem de controlo, não uma configuração: vai num `0x08`. */
    public function testRotatingToACellIsAControlPacket(): void
    {
        $tlv = $this->build('rotateToCell', ['cell' => 7], 0x08);

        self::assertSame(7, ord((string)$tlv[0xA124]['value']));
    }

    public function testPausingMedicationIsAControlPacket(): void
    {
        $tlv = $this->build('medicationPause', ['minutes' => 20], 0x08);

        self::assertSame(20, ord((string)$tlv[0xA125]['value']));
    }

    /** Zero é «voltar ao normal», e é um valor legítimo. */
    public function testPausingWithZeroResumesMedication(): void
    {
        $tlv = $this->build('medicationPause', ['minutes' => 0], 0x08);

        self::assertSame(0, ord((string)$tlv[0xA125]['value']));
    }

    /** E as cinco aparecem no catálogo, senão não há por onde as usar. */
    public function testTheyAreAllInTheCatalogue(): void
    {
        $chaves = array_column(DeviceConfigurationCatalog::configsForProtocol('zayata-m228'), 'key');

        foreach (['retrieval_warning', 'retrieval_timeout', 'loaded_cells', 'rotate_to_cell', 'medication_pause'] as $chave) {
            self::assertContains($chave, $chaves, $chave);
        }
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
