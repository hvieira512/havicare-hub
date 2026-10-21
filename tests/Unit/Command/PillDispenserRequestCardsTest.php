<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/** O `0x07` pede sempre as 31 TAGs do `STATUS_TAGS` e enche as sete leituras de uma vez: um botão por pergunta, não por grandeza. */
final class PillDispenserRequestCardsTest extends TestCase
{
    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function requests(): array
    {
        $requests = [];
        foreach (DeviceCommandCatalog::commandsForProtocol('zayata-m228') as $entry) {
            if (($entry['kind'] ?? '') === 'request') {
                $requests[(string)$entry['feature']][] = $entry;
            }
        }

        return $requests;
    }

    /** O estado no `0x07` e a configuração no `0x05` são as duas perguntas que o aparelho responde, e pedem-se como as outras. */
    public function testTheTwoReadsAreRequestCards(): void
    {
        self::assertSame(['device_status', 'sync_configuration'], array_keys($this->requests()));
    }

    /** Como no relógio: a bateria vem no `device_status`, e a frequência cardíaca tem botão porque carregar nela manda medir. */
    public function testTheSevenReadingsTheStatusFrameFillsAreNotRequestedOnTheirOwn(): void
    {
        $requests = $this->requests();
        $telemetry = [];
        foreach (CapabilityCatalog::definitionsForDeviceType('pill_dispenser') as $definition) {
            $telemetry[(string)$definition['key']] = $definition;
        }

        $filledByTheStatusFrame = [
            'battery',
            'cells_remaining',
            'connectivity',
            'ambient_humidity',
            'medication_alarm_status',
            'ambient_temperature',
        ];

        foreach ($filledByTheStatusFrame as $feature) {
            self::assertArrayNotHasKey($feature, $requests, $feature);
            self::assertTrue($telemetry[$feature]['isTelemetry'], $feature);
            self::assertFalse($telemetry[$feature]['isRequestable'], $feature);
        }
    }

    /** Quem as relê é o `device_status`, e é o único pedível entre as leituras do `0x07`. */
    public function testTheStatusReadIsTheOneRequestableReading(): void
    {
        $status = $this->requests()['device_status'][0] ?? null;

        self::assertNotNull($status);
        self::assertSame('readStatus', $status['command']);
        self::assertSame(['read_status_ack'], $status['expectedReplyTypes']);
        self::assertContains(
            'device_status',
            array_column(CapabilityCatalog::definitionsForDeviceType('pill_dispenser'), 'key'),
        );
    }

    /** Cada pedido sabe que trama manda e que resposta espera; a configuração vai em duas porque as TAGs não cabem em 300 bytes. */
    public function testEachRequestKnowsItsCommandAndItsReply(): void
    {
        $sync = $this->requests()['sync_configuration'] ?? [];

        self::assertSame(
            ['readConfiguration', 'readConfiguration2'],
            array_column($sync, 'command'),
        );
        foreach ($sync as $entry) {
            self::assertSame(['read_config_ack'], $entry['expectedReplyTypes']);
        }
    }

    /** As sondas da descoberta não servem decisão nenhuma ao administrador, e as listas estão no capítulo 19. */
    public function testTheIntegrationProbesAreNotPartOfTheProduct(): void
    {
        $keys = array_column(CapabilityCatalog::definitionsForDeviceType('pill_dispenser'), 'key');

        foreach (['supported_configuration', 'supported_status', 'supported_control'] as $probe) {
            self::assertNotContains($probe, $keys, $probe);
            self::assertArrayNotHasKey($probe, $this->requests(), $probe);
        }
    }

    /** Um mosaico dispara ao primeiro clique, e dispensar consome uma dose e reiniciar corta a ligação. */
    public function testNothingThatChangesTheDeviceIsARequest(): void
    {
        foreach (['dispense_now', 'restart_device', 'reset_tray', 'mute_alarm', 'calibrate_clock'] as $feature) {
            self::assertArrayNotHasKey($feature, $this->requests(), $feature);
        }
    }

    /** A trama que cada pedido manda leva o tipo de pacote que o protocolo exige. */
    public function testEachRequestBuildsTheFrameItsPacketTypeRequires(): void
    {
        $adapter = new PillDispenserAdapter();
        $expected = ['readStatus' => 0x07, 'readConfiguration' => 0x05, 'readConfiguration2' => 0x05];

        foreach ($this->requests() as $entries) {
            foreach ($entries as $entry) {
                $command = (string)$entry['command'];

                $decoded = $adapter->decodeIncoming(
                    DeviceCommandCatalog::buildDownlink('zayata-m228', '869243062262262', $command)
                );

                self::assertIsArray($decoded, $command);
                self::assertSame($expected[$command], $decoded['packetType'], $command);
            }
        }
    }
}
