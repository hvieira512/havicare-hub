<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * O hub não oferece desligar a cifra do dispensador, porque o aparelho não a desliga.
 *
 * O `0x8005` aparece na especificação entre os parâmetros de configuração, mas o fornecedor
 * confirmou que não se escreve: ou o aparelho cifra tudo o que envia, ou não cifra nada. Um
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

    public function testTheFrameCannotEvenBeBuilt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeviceCommandCatalog::buildDownlink('zayata-m228', '869243062262262', 'disableEncryption', []);
    }
}
