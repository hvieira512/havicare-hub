<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Domain\Capability\HubAppliedCapability;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * O `supportedProtocols()` e o despacho anunciam e aceitam os mesmos protocolos: um a mais na
 * lista cai no `default` do `match` e sai com os comandos de outro fornecedor.
 */
final class DeclaredProtocolsAreDispatchedTest extends TestCase
{
    public function testEveryDeclaredProtocolIsServedByItsOwnDispatch(): void
    {
        $wrong = [];
        foreach ($this->contracts() as $key => $capability) {
            foreach ($capability->supportedProtocols() as $protocol) {
                $own = $this->configurationKeysOf($protocol);
                if ($own === []) {
                    continue;
                }

                try {
                    $native = $capability->toNative($protocol, $capability->defaultValue($protocol));
                } catch (\Throwable $e) {
                    // Uma recusa do valor diz que o despacho chegou ao braço certo e não
                    // gostou do que lá ia; o que este teste procura é o despacho a falhar.
                    if ($e instanceof \UnhandledMatchError || str_contains($e->getMessage(), 'Unsupported protocol')) {
                        $wrong[] = "{$key}/{$protocol}: {$e->getMessage()}";
                    }
                    continue;
                }

                // A chave nativa que sai tem de ser deste protocolo: absorvido pelo `default` de outro,
                // sairia a do fornecedor errado.
                foreach (array_keys($native) as $nativeKey) {
                    if (!in_array((string)$nativeKey, $own, true)) {
                        $wrong[] = "{$key}/{$protocol}: a chave nativa `{$nativeKey}` não é deste protocolo";
                    }
                }
            }
        }

        self::assertSame([], $wrong);
    }

    /**
     * As chaves de configuração que um protocolo declara.
     *
     * @return list<string>
     */
    private function configurationKeysOf(string $protocol): array
    {
        $keys = [];
        foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $config) {
            $key = trim((string)($config['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Um protocolo que a capacidade não anuncia é recusado, e antes de validar: a recusa aponta
     * o protocolo e não o valor.
     */
    public function testAnUndeclaredProtocolIsRefusedForBeingUnsupported(): void
    {
        $registry = new CapabilityRegistry();
        $all = array_keys(ProtocolRegistry::all());
        $wrong = [];
        $checked = 0;

        foreach (CapabilityCatalog::keys() as $key) {
            $contract = $registry->get($key);
            if ($contract === null) {
                continue;
            }

            $declared = $contract->supportedProtocols();
            // A capacidade genérica anuncia todos os protocolos de propósito.
            if (count($declared) === count($all)) {
                continue;
            }

            foreach (array_diff($all, $declared) as $protocol) {
                $checked++;
                try {
                    $contract->toNative($protocol, []);
                    $wrong[] = "{$key} serve `{$protocol}` sem o anunciar.";
                } catch (\Throwable $e) {
                    if (!str_contains($e->getMessage(), 'Unsupported protocol')) {
                        $wrong[] = "{$key} recusa `{$protocol}` pela razão errada: {$e->getMessage()}";
                    }
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'Nenhum par contrato/protocolo foi verificado.');
        self::assertSame([], $wrong);
    }

    /** @return array<string, \Hub\Domain\Capability\CapabilityContract> */
    private function contracts(): array
    {
        $registry = new CapabilityRegistry();
        $contracts = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            $key = (string)$definition['key'];
            $contract = $registry->get($key);
            // As aplicadas pelo hub não têm comando nativo nenhum, por desenho.
            if ($contract !== null && !$contract instanceof HubAppliedCapability) {
                $contracts[$key] = $contract;
            }
        }

        return $contracts;
    }
}
