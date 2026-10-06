<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Domain\Capability\ConfigurationInputDefaults;
use PHPUnit\Framework\TestCase;

/**
 * As configurações sem contrato próprio caem na `GenericCapability`, que traduz por protocolo.
 * Percorre-se o catálogo inteiro para a configuração seguinte entrar no teste sozinha.
 */
final class PillDispenserGenericConfigurationTest extends TestCase
{
    public function testEveryDispenserConfigurationSurvivesTheGenericContract(): void
    {
        $registry = new CapabilityRegistry();
        $failures = [];

        foreach (DeviceConfigurationCatalog::configsForProtocol('zayata-m228') as $entry) {
            $nativeKey = trim((string)($entry['key'] ?? ''));
            $genericKey = CapabilityCatalog::mapConfigurationKey($nativeKey) ?? $nativeKey;

            try {
                $registry->toNative('zayata-m228', $genericKey, ConfigurationInputDefaults::forEntry($entry));
            } catch (\Throwable $e) {
                $failures[] = $genericKey . ': ' . $e->getMessage();
            }
        }

        self::assertNotSame([], DeviceConfigurationCatalog::configsForProtocol('zayata-m228'));
        self::assertSame([], $failures);
    }

    /** Uma acção não leva parâmetros, e a dashboard envia um objecto vazio. */
    public function testAnActionWithoutParametersIsAcceptedAsAnEmptyObject(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(
            ['restart_device' => []],
            $registry->toNative('zayata-m228', 'restart_device', []),
        );
    }
}
