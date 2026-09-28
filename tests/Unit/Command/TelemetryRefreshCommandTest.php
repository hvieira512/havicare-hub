<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Reler o estado do dispensador é um pedido como os outros.
 *
 * Foi um `kind` próprio com botão próprio à cabeça dos mosaicos, e isso trouxe o botão para
 * todos os 4P Touch quando o `TS` deles foi marcado do mesmo modo -- num sítio que promete
 * actualizar mosaicos que o `TS` não actualiza. Deixou de haver caminho especial: é uma
 * capacidade pedível, e o mosaico dela é igual ao da versão do firmware.
 */
final class TelemetryRefreshCommandTest extends TestCase
{
    public function testTheDispenserStatusIsAnOrdinaryRequestableCapability(): void
    {
        $definitions = CapabilityCatalog::definitionsForDeviceType('pill_dispenser');
        $status = null;
        foreach ($definitions as $definition) {
            if ($definition['key'] === 'device_status') {
                $status = $definition;
            }
        }

        self::assertNotNull($status);
        self::assertSame('telemetry', $status['section']);
        self::assertTrue($status['isRequestable']);
    }

    /** O comando que a serve é um `request`, como os outros todos. */
    public function testTheCommandBehindItIsAnOrdinaryRequest(): void
    {
        $entries = DeviceCommandCatalog::commandsForFeature('zayata-m228', 'device_status');

        self::assertCount(1, $entries);
        self::assertSame('readStatus', $entries[0]['command']);
        self::assertSame('request', $entries[0]['kind']);
        self::assertContains('device_status', DeviceCommandCatalog::featuresForProtocol('zayata-m228'));
    }

    /** E não sobra `kind` nenhum fora do `request` no catálogo inteiro. */
    public function testNoCommandKeepsARefreshKind(): void
    {
        foreach (\Hub\Domain\ProtocolRegistry::keys() as $protocol) {
            foreach (DeviceCommandCatalog::commandsForProtocol($protocol) as $entry) {
                self::assertNotSame('refresh', $entry['kind'] ?? null, $protocol . '/' . (string)($entry['id'] ?? '?'));
            }
        }
    }

    /** O `TS` dos 4P Touch é o mesmo pedido, com o mesmo mosaico. */
    public function testTheWatchStatusIsTheSameRequestableCapability(): void
    {
        $keys = array_column(CapabilityCatalog::definitionsForDeviceType('watch'), 'key');
        self::assertContains('device_status', $keys);

        $entries = DeviceCommandCatalog::commandsForFeature('four-p-touch', 'device_status');
        self::assertCount(1, $entries);
        self::assertSame('TS', $entries[0]['command']);
        self::assertSame('request', $entries[0]['kind']);
    }

    /** E tem uma porta só: o mosaico. A entrada no painel de configuração era a segunda. */
    public function testTheWatchStatusHasNoSecondDoorInTheConfigurationPanel(): void
    {
        self::assertNull(
            \Hub\Command\DeviceConfigurationCatalog::configForProtocol('four-p-touch', 'deviceStatus'),
        );
    }

    /** E o `device_state` do relógio é outra coisa: o acontecimento de se desligar ou repor. */
    public function testTheWatchKeepsItsSeparateLifecycleEvent(): void
    {
        foreach (CapabilityCatalog::definitionsForDeviceType('watch') as $definition) {
            if ($definition['key'] === 'device_state') {
                self::assertTrue($definition['isEvent'] ?? false);
                return;
            }
        }

        self::fail('o `device_state` do relógio desapareceu');
    }
}
