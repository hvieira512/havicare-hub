<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
/**
 * Só os pedidos que leem viram mosaico no ecrã principal: as sete leituras que o `0x07` enche
 * e a configuração do `0x05`. O que muda o aparelho fica no modal.
 */
final class PillDispenserRequestCardsTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function requests(): array
    {
        $requests = [];
        foreach (DeviceCommandCatalog::commandsForProtocol('zayata-m228') as $entry) {
            if (($entry['kind'] ?? '') === 'request') {
                $requests[(string)$entry['feature']] = $entry;
            }
        }

        return $requests;
    }

    /**
    /** Cada leitura que o `0x07` enche pede-se do seu próprio mosaico. */
    public function testEveryReadingTheStatusFramePullsIsRequestable(): void
    {
        self::assertSame([
            'battery',
            'cells_remaining',
            'connectivity',
            'humidity',
            'lid_state',
            'medication_alarm_status',
            'temperature',
            'sync_configuration',
        ], array_keys($this->requests()));
    }

    /** E o `device_status`, que só existia para ser botão, deixou de fazer falta. */
    public function testTheEmptyStatusCapabilityIsGone(): void
    {
        self::assertArrayNotHasKey('device_status', $this->requests());
    }

    /** Cada um tem de saber que trama manda e que resposta espera, senão não fecha o ciclo. */
    public function testEachRequestKnowsItsCommandAndItsReply(): void
    {
        $requests = $this->requests();

        foreach (['battery', 'temperature', 'humidity', 'connectivity', 'cells_remaining', 'lid_state', 'medication_alarm_status'] as $feature) {
            self::assertSame('readStatus', $requests[$feature]['command'] ?? null, $feature);
            self::assertSame(['read_status_ack'], $requests[$feature]['expectedReplyTypes'] ?? null, $feature);
        }
        self::assertSame('readConfiguration', $requests['sync_configuration']['command'] ?? null);
        self::assertSame(['read_config_ack'], $requests['sync_configuration']['expectedReplyTypes'] ?? null);
    }

    /**
     * O que muda o aparelho não é pedido.
     *
     * A distinção não é cosmética: um mosaico do ecrã principal dispara ao primeiro clique,
     * sem confirmação e sem contexto. Dispensar consome uma dose e reiniciar corta a ligação.
     */
    public function testNothingThatChangesTheDeviceIsARequest(): void
    {
        foreach (['dispense_now', 'restart_device', 'reset_tray', 'mute_alarm', 'calibrate_clock'] as $feature) {
            self::assertArrayNotHasKey($feature, $this->requests(), $feature);
        }
    }

    /**
     * A trama que cada pedido manda é a que o protocolo exige, e traz o corpo a perguntar.
     *
     * Afirmar que os bytes não são vazios não media nada: o construtor ou lança, ou devolve
     * uma trama por construção.
     */
    public function testEachRequestBuildsTheFrameItsPacketTypeRequires(): void
    {
        $adapter = new PillDispenserAdapter();
        $expected = [
            'readStatus' => [0x07, PillDispenserAdapter::STATUS_TAGS],
            'readConfiguration' => [0x05, PillDispenserAdapter::CONFIGURATION_TAGS],
        ];

        foreach ($this->requests() as $feature => $entry) {
            $command = (string)$entry['command'];
            [$packetType, $tags] = $expected[$command];

            $decoded = $adapter->decodeIncoming(
                DeviceCommandCatalog::buildDownlink('zayata-m228', '869243062262262', $command)
            );

            self::assertIsArray($decoded, $feature);
            self::assertSame($packetType, $decoded['packetType'], $feature);
            self::assertSame($tags, array_keys($decoded['tlv']), $feature);
        }
    }
}
