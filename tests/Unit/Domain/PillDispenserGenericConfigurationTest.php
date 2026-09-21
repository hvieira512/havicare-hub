<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Domain\Capability\ConfigurationInputDefaults;
use PHPUnit\Framework\TestCase;

/**
 * As configurações sem contrato próprio caem na `GenericCapability`, e ela traduz por
 * protocolo. Um protocolo que ela não conheça rebenta na gravação com «Unsupported protocol»,
 * já depois de o formulário ter aparecido e de o utilizador ter carregado em Enviar.
 *
 * Percorre-se o catálogo inteiro em vez de uma configuração escolhida à mão: o defeito não era
 * de nenhuma delas em particular, era do protocolo não estar lá, e a próxima que se
 * acrescentar entra neste teste sozinha.
 */
final class PillDispenserGenericConfigurationTest extends TestCase
{
    public function testEveryDispenserConfigurationSurvivesTheGenericContract(): void
    {
        $registry = new CapabilityRegistry();
        $falhas = [];

        foreach (DeviceConfigurationCatalog::configsForProtocol('zayata-m228') as $entry) {
            $nativeKey = trim((string)($entry['key'] ?? ''));
            $genericKey = CapabilityCatalog::mapConfigurationKey($nativeKey) ?? $nativeKey;

            try {
                $registry->toNative('zayata-m228', $genericKey, ConfigurationInputDefaults::forEntry($entry));
            } catch (\Throwable $e) {
                $falhas[] = $genericKey . ': ' . $e->getMessage();
            }
        }

        self::assertNotSame([], DeviceConfigurationCatalog::configsForProtocol('zayata-m228'));
        self::assertSame([], $falhas);
    }

    /**
     * Uma acção não leva parâmetros, e o valor que a dashboard envia é um objecto vazio. Vale
     * a pena prendê-lo à parte: é o caminho que o Hugo carregou primeiro no aparelho real.
     */
    public function testAnActionWithoutParametersIsAcceptedAsAnEmptyObject(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(
            ['restart_device' => []],
            $registry->toNative('zayata-m228', 'restart_device', []),
        );
    }
}
