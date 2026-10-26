<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Ingress\Tcp\Zayata\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/** Uma reposição devolve o M228 ao servidor do fornecedor, e o firmware não deixa desligar a cifra. */
final class PillDispenserNoFactoryResetTest extends TestCase
{
    public function testTheDispenserCatalogDoesNotOfferThem(): void
    {
        $keys = array_column(DeviceConfigurationCatalog::configsForProtocol('zayata-m228'), 'key');

        self::assertNotContains('reset_device', $keys);
        self::assertNotContains('disable_encryption', $keys);
    }

    public function testTheDispenserDoesNotAdvertiseTheCapabilities(): void
    {
        $keys = array_column(CapabilityCatalog::definitionsForDeviceType('pill_dispenser'), 'key');

        self::assertNotContains('reset_device', $keys);
        self::assertNotContains('disable_encryption', $keys);
    }

    /** O fornecedor respondeu «0x8005 cannot be set»: o hub nem pergunta pela TAG da cifra. */
    public function testTheHubNeverEvenAsksAboutTheCipherTag(): void
    {
        self::assertNotContains(0x8005, PillDispenserAdapter::CONFIGURATION_TAGS);
        self::assertNotContains(0x8005, PillDispenserAdapter::STATUS_TAGS);
    }

    /** São de uma versão da série M2 posterior à do aparelho de ensaio, que as recusa com «TAG inválida». */
    public function testTheOnesThisFirmwareRefusesAreNotOffered(): void
    {
        $keys = array_column(DeviceConfigurationCatalog::configsForProtocol('zayata-m228'), 'key');

        self::assertNotContains('rotate_to_cell', $keys);
        self::assertNotContains('medication_pause', $keys);
    }

    public function testTheWatchesKeepTheirs(): void
    {
        $keys = array_column(CapabilityCatalog::definitionsForDeviceType('watch'), 'key');

        self::assertContains('reset_device', $keys, 'nos relógios a reposição é recuperável');
    }
}
