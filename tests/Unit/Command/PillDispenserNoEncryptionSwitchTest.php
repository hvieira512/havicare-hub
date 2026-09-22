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
 * O `0x8005` aparece na especificação na tabela dos parâmetros de configuração, e daí veio a
 * leitura de que bastava escrever-lhe zero para o M228 parar de cifrar o que envia. O
 * fornecedor respondeu que não: «0x8005 cannot be set», e que a chave e os números aleatórios
 * são derivados da codificação do próprio aparelho — ou cifra tudo o que envia, ou não cifra
 * nada, e não é do nosso lado que isso se decide.
 *
 * Um botão que o firmware recusa sempre é pior do que botão nenhum: promete uma saída que não
 * existe e manda quem depura atrás do envio em vez do aparelho. O caminho por onde os eventos
 * hão-de chegar passa por o fornecedor desligar a cifra na plataforma dele, e não por aqui.
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
