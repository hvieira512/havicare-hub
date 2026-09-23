<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * O hub não oferece desligar a cifra: o fornecedor respondeu «0x8005 cannot be set», e um
 * botão que o firmware recusa sempre promete uma saída que não existe.
 */
final class PillDispenserNoEncryptionSwitchTest extends TestCase
{
    public function testTheDispenserCatalogDoesNotOfferTheSwitch(): void
    {
        $keys = array_column(DeviceConfigurationCatalog::configsForProtocol('zayata-m228'), 'key');

        self::assertNotContains('disable_encryption', $keys);
    }

    public function testTheDispenserDoesNotAdvertiseTheCapability(): void
    {
        $keys = array_column(CapabilityCatalog::definitionsForDeviceType('pill_dispenser'), 'key');

        self::assertNotContains('disable_encryption', $keys);
    }

    /**
     * E o `0x8005` não entra em nenhuma das duas listas que o hub pergunta.
     *
     * É a afirmação que vale: `buildDownlink` recusa qualquer nome que não conheça, e por isso
     * um comando inventado a lançar não provava nada sobre a cifra em particular.
     */
    public function testTheHubNeverEvenAsksAboutTheCipherTag(): void
    {
        self::assertNotContains(0x8005, PillDispenserAdapter::CONFIGURATION_TAGS);
        self::assertNotContains(0x8005, PillDispenserAdapter::STATUS_TAGS);
    }
}
