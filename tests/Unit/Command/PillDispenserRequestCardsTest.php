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
     * Um mosaico só: a configuração no `0x05`.
     *
     * O `0x07` também se pede, mas não daqui — reler o estado é uma função do ecrã e não uma
     * capacidade, e por isso é um comando de `kind` `refresh` sem capacidade por trás.
     */
    public function testOnlyTheConfigurationReadIsARequestCard(): void
    {
        self::assertSame(['sync_configuration'], array_keys($this->requests()));
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
            'humidity',
            'medication_alarm_status',
            'temperature',
        ];

        foreach ($filledByTheStatusFrame as $feature) {
            self::assertArrayNotHasKey($feature, $requests, $feature);
            self::assertTrue($telemetry[$feature]['isTelemetry'], $feature);
            self::assertFalse($telemetry[$feature]['isRequestable'], $feature);
        }
    }

    /** E quem relê as sete é o comando de recarregar, sem capacidade que o anuncie. */
    public function testTheStatusReadIsARefreshAndNotACapability(): void
    {
        $refresh = DeviceCommandCatalog::refreshCommandForProtocol('zayata-m228');

        self::assertNotNull($refresh);
        self::assertSame('readStatus', $refresh['command']);
        self::assertSame(['read_status_ack'], $refresh['expectedReplyTypes']);
        self::assertNotContains(
            'device_status',
            array_column(CapabilityCatalog::definitionsForDeviceType('pill_dispenser'), 'key'),
        );
    }

    /** Cada um tem de saber que trama manda e que resposta espera, senão não fecha o ciclo. */
    public function testEachRequestKnowsItsCommandAndItsReply(): void
    {
        $requests = $this->requests();

        self::assertSame('readConfiguration', $requests['sync_configuration']['command'] ?? null);
        self::assertSame(['read_config_ack'], $requests['sync_configuration']['expectedReplyTypes'] ?? null);
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
