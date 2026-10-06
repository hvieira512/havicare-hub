<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * O `DeviceConfigurationCatalog` declara as chaves nativas e o `mapConfigurationKey()`
 * traduz-as: uma chave sem tradução compila e nunca chega à API.
 */
final class ConfigurationKeyMappingTest extends TestCase
{
    public function testEveryNativeConfigurationKeyMapsToAGenericCapabilityKey(): void
    {
        $protocols = ProtocolRegistry::protocolsWithConfigCatalog();
        self::assertNotSame([], $protocols);

        $checked = 0;
        foreach ($protocols as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
                $nativeKey = (string)($entry['key'] ?? '');
                $checked++;
                self::assertNotNull(
                    CapabilityCatalog::mapConfigurationKey($nativeKey),
                    "A chave nativa `{$nativeKey}` do protocolo `{$protocol}` não tem chave genérica: "
                    . 'a definição existe mas a API nunca a apresenta como capacidade.'
                );
            }
        }

        self::assertGreaterThan(0, $checked, 'Nenhuma chave foi verificada -- a varredura falhou.');
    }
}
