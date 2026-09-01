<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * As duas direcções da tradução de chaves vivem em ficheiros diferentes e não se conhecem: o
 * `DeviceConfigurationCatalog` declara as chaves nativas, e o `mapConfigurationKey()` traduz.
 *
 * Divergirem falha em silêncio -- uma chave nativa sem tradução compila, passa no PHPStan, e
 * nunca chega à API como capacidade.
 */
final class ConfigurationKeyMappingTest extends TestCase
{
    /**
     * As chaves nativas que deliberadamente não têm capacidade genérica, com o motivo.
     *
     * @var array<string, string>
     */
    private const INTENTIONALLY_UNMAPPED = [
        // Envelope em bruto do bloco `configs` das medições. As definições que a interface
        // apresenta são as irmãs com o mesmo comando -- `wonlexHeartRateInterval`,
        // `wonlexBPInterval`, ... -- e essas mapeiam. Traduzi-la duplicava o mesmo comando
        // numa capacidade que apresentava o blob inteiro.
        'deviceMeasuringFrequency' => 'envelope json do comando; as sub-definições é que mapeiam',
        // Mesmo caso: o `deviceConfig` é o envelope do bloco `configs` de sistema, e cada
        // definição dentro dele (`wonlexStepTarget`, `wonlexLowPower`, ...) tem a sua chave.
        'deviceConfig' => 'envelope json do comando; as sub-definições é que mapeiam',
    ];

    public function testEveryNativeConfigurationKeyMapsToAGenericCapabilityKey(): void
    {
        $protocols = ProtocolRegistry::protocolsWithConfigCatalog();
        self::assertNotSame([], $protocols);

        $checked = 0;
        foreach ($protocols as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
                $nativeKey = (string)($entry['key'] ?? '');
                if (isset(self::INTENTIONALLY_UNMAPPED[$nativeKey])) {
                    continue;
                }

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

    /**
     * A lista de excepções também apodrece: uma chave que deixe de existir, ou que ganhe
     * tradução, deixa aqui uma linha que já não protege nada e esconde a próxima divergência.
     */
    public function testTheIntentionallyUnmappedKeysStillExistAndAreStillUnmapped(): void
    {
        $nativeKeys = [];
        foreach (ProtocolRegistry::protocolsWithConfigCatalog() as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
                $nativeKeys[(string)($entry['key'] ?? '')] = true;
            }
        }

        foreach (self::INTENTIONALLY_UNMAPPED as $nativeKey => $reason) {
            self::assertArrayHasKey(
                $nativeKey,
                $nativeKeys,
                "A excepção `{$nativeKey}` ({$reason}) já não é declarada por nenhum protocolo."
            );
            self::assertNull(
                CapabilityCatalog::mapConfigurationKey($nativeKey),
                "A chave `{$nativeKey}` já tem tradução genérica -- tirar da lista de excepções."
            );
        }
    }
}
