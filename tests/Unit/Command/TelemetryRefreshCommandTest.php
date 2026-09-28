<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Actualizar a telemetria é uma função do ecrã, e não uma capacidade do aparelho.
 *
 * O `device_status` era uma capacidade declarada como telemetria que nunca publicava nada:
 * nos relógios o `TS` devolve sobretudo o que o hub lá escreveu, e no dispensador a resposta
 * ao `0x07` enche as sete leituras, cada uma na sua capacidade. Quem consultasse o catálogo
 * pela API via um tipo anunciado que o MQTT nunca carrega.
 *
 * Passou a ser um comando de `kind` próprio, que o painel oferece como botão de recarregar à
 * cabeça dos cartões que ele actualiza.
 */
final class TelemetryRefreshCommandTest extends TestCase
{
    /** Só o dispensador relê telemetria, e por isso só ele oferece o botão. */
    public function testOnlyTheDispenserOffersARefresh(): void
    {
        $dispenser = DeviceCommandCatalog::refreshCommandForProtocol('zayata-m228');
        self::assertNotNull($dispenser);
        self::assertSame('readStatus', $dispenser['command']);

        foreach (['four-p-touch', 'wonlex-json', 'vivistar-iw', 'veepoo-ble'] as $protocol) {
            self::assertNull(DeviceCommandCatalog::refreshCommandForProtocol($protocol), $protocol);
        }
    }

    /**
     * O `TS` dos 4P Touch devolve sobretudo o que o hub lá escreveu, e por isso já vive no
     * painel de configuração como «Estado do dispositivo», com o verbo «Consultar». Oferecê-lo
     * também como «Atualizar» à cabeça dos mosaicos era uma segunda porta para o mesmo
     * comando, a prometer uma releitura de telemetria que ele não faz.
     */
    public function testTheWatchStatusStaysInTheConfigurationPanel(): void
    {
        $entry = DeviceConfigurationCatalog::configForProtocol('four-p-touch', 'deviceStatus');

        self::assertNotNull($entry);
        self::assertSame('Estado do dispositivo', $entry['label']);
        self::assertSame('Consultar', $entry['verb'] ?? '');
    }

    /** Deixou de ser capacidade, e por isso o catálogo deixa de a anunciar. */
    public function testTheRefreshIsNotACapabilityAnyMore(): void
    {
        foreach (['watch', 'pill_dispenser'] as $deviceType) {
            $keys = array_column(CapabilityCatalog::definitionsForDeviceType($deviceType), 'key');
            self::assertNotContains('device_status', $keys, $deviceType);
        }

        foreach (['zayata-m228', 'four-p-touch'] as $protocol) {
            self::assertNotContains('device_status', CapabilityCatalog::keysForProtocol($protocol), $protocol);
        }
    }

    /**
     * E o caminho que o envia tem de o encontrar.
     *
     * O `commandsForFeature` filtrava só por `kind` `request`, e por isso a API respondia
     * «Feature is not supported for this device» a um botão que ela própria anunciava.
     */
    public function testTheSendPathFindsIt(): void
    {
        foreach (['zayata-m228'] as $protocol) {
            $entries = DeviceCommandCatalog::commandsForFeature($protocol, 'telemetry_refresh');

            self::assertCount(1, $entries, $protocol);
            self::assertSame('refresh', $entries[0]['kind'], $protocol);
        }
    }

    /** E não entra entre os mosaicos, que são os que têm capacidade por trás. */
    public function testTheRefreshIsNotOneOfTheRequestCards(): void
    {
        foreach (['zayata-m228', 'four-p-touch'] as $protocol) {
            foreach (DeviceCommandCatalog::commandsForProtocol($protocol) as $entry) {
                self::assertNotSame(
                    'device_status',
                    $entry['feature'] ?? null,
                    $protocol . ': ' . (string)($entry['id'] ?? '?'),
                );
            }
        }
    }
}
