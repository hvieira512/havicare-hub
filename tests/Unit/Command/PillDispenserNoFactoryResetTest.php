<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * O hub não expõe a reposição de fábrica do dispensador, e isso tem de continuar assim.
 *
 * O M228 só aponta para o hub porque o fornecedor lhe mandou essa configuração. Uma reposição
 * devolve-o ao servidor dele: deixa de nos falar, e recuperá-lo obriga a pedir a outra pessoa,
 * noutro fuso horário, que a volte a empurrar. Um clique enganado na dashboard -- e o botão
 * estava debaixo do «Reiniciar» -- custava o aparelho.
 *
 * Nos relógios a mesma acção continua a existir: lá é recuperável, e por isso a chave genérica
 * não desaparece do catálogo, só a exposição do dispensador.
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
