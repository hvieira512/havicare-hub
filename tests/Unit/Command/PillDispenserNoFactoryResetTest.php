<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Uma reposição devolve o M228 ao servidor do fornecedor; nos relógios a acção fica, porque lá é
 * recuperável.
 */
final class PillDispenserNoFactoryResetTest extends TestCase
{
    public function testTheDispenserCatalogDoesNotOfferAFactoryReset(): void
    {
        $keys = array_column(DeviceConfigurationCatalog::configsForProtocol('zayata-m228'), 'key');

        self::assertNotContains('reset_device', $keys);
    }

    public function testTheDispenserDoesNotAdvertiseTheCapability(): void
    {
        $keys = array_column(CapabilityCatalog::definitionsForDeviceType('pill_dispenser'), 'key');

        self::assertNotContains('reset_device', $keys);
    }

    public function testTheFrameCannotEvenBeBuilt(): void
    {
        // A última linha de defesa: mesmo que alguém chame o comando à mão, não há trama.
        $this->expectException(\InvalidArgumentException::class);

        DeviceCommandCatalog::buildDownlink('zayata-m228', '869243062262262', 'factoryReset', []);
    }

    public function testTheWatchesKeepTheirs(): void
    {
        $keys = array_column(CapabilityCatalog::definitionsForDeviceType('watch'), 'key');

        self::assertContains('reset_device', $keys, 'nos relógios a reposição é recuperável');
    }
}
