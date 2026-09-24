<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
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
    /** Os dois protocolos que sabem reler o estado oferecem-no. */
    public function testTheProtocolsThatCanRereadTheirStateOfferARefresh(): void
    {
        $dispenser = DeviceCommandCatalog::refreshCommandForProtocol('zayata-m228');
        self::assertNotNull($dispenser);
        self::assertSame('readStatus', $dispenser['command']);

        $watch = DeviceCommandCatalog::refreshCommandForProtocol('four-p-touch');
        self::assertNotNull($watch);
        self::assertSame('TS', $watch['command']);
    }

    /** E quem não sabe não oferece botão nenhum. */
    public function testAProtocolWithoutOneOffersNothing(): void
    {
        self::assertNull(DeviceCommandCatalog::refreshCommandForProtocol('wonlex-json'));
        self::assertNull(DeviceCommandCatalog::refreshCommandForProtocol('veepoo-ble'));
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
