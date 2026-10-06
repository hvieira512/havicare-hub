<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Um botão por pergunta, e não um botão por grandeza.
 *
 * O `0x07` do M228 pede sempre as 31 TAGs do `STATUS_TAGS`, e a resposta enche as sete
 * leituras de uma vez. Sete botões a construir a trama idêntica prometiam uma granularidade
 * que o protocolo não dá, e atropelavam-se entre si quando carregados de seguida.
 */
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

    /**
     * Dois mosaicos: o estado no `0x07` e a configuração no `0x05`. São as duas perguntas
     * que o aparelho responde, e pedem-se como qualquer outra.
     */
    public function testTheTwoReadsAreRequestCards(): void
    {
        self::assertSame(['device_status', 'sync_configuration'], array_keys($this->requests()));
    }

    /**
     * As sete leituras que a resposta ao `0x07` enche mostram-se, mas não se pedem.
     *
     * É a mesma regra que o relógio já segue: a bateria dele não tem botão porque vem no
     * `device_status`, e a frequência cardíaca tem porque carregar nela manda medir.
     */
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

    /**
     * Cada um tem de saber que trama manda e que resposta espera, senão não fecha o ciclo. A
     * configuração vai em duas porque as TAGs já não cabem numa trama de 300 bytes.
     */
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

    /**
     * As sondas da descoberta não ficam: serviram para fazer a integração.
     *
     * Perguntavam ao firmware que TAGs ele serve. Nada no hub lia a resposta, e o
     * administrador que carregasse no botão recebia uma lista de TAGs sobre a qual não tem
     * nenhuma decisão. As três listas estão escritas no capítulo 19.
     */
    public function testTheIntegrationProbesAreNotPartOfTheProduct(): void
    {
        $keys = array_column(CapabilityCatalog::definitionsForDeviceType('pill_dispenser'), 'key');

        foreach (['supported_configuration', 'supported_status', 'supported_control'] as $probe) {
            self::assertNotContains($probe, $keys, $probe);
            self::assertArrayNotHasKey($probe, $this->requests(), $probe);
        }
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
        $chunks = PillDispenserAdapter::configurationReadChunks();
        $expected = [
            'readStatus' => [0x07, PillDispenserAdapter::STATUS_TAGS],
            'readConfiguration' => [0x05, $chunks[0]],
            'readConfiguration2' => [0x05, $chunks[1] ?? []],
        ];

        foreach ($this->requests() as $entries) {
            foreach ($entries as $entry) {
                $command = (string)$entry['command'];
                [$packetType, $tags] = $expected[$command];

                $decoded = $adapter->decodeIncoming(
                    DeviceCommandCatalog::buildDownlink('zayata-m228', '869243062262262', $command)
                );

                self::assertIsArray($decoded, $command);
                self::assertSame($packetType, $decoded['packetType'], $command);
                self::assertSame($tags, array_keys($decoded['tlv']), $command);
            }
        }
    }
}
