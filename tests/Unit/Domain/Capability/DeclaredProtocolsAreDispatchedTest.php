<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Domain\Capability\HubAppliedCapability;
use PHPUnit\Framework\TestCase;

/**
 * O `supportedProtocols()` não pode anunciar o que o despacho recusa, nem o despacho aceitar
 * o que ele não anuncia.
 *
 * Uma lista à parte do despacho deriva em silêncio: um protocolo acrescentado à lista cai no
 * `default` do `match` e sai com os comandos nativos de outro fornecedor.
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

                // A chave nativa que sai tem de ser uma que este protocolo declare. Absorvido
                // pelo `default` de outro fornecedor, sairia a chave nativa do fornecedor
                // errado -- que é a forma silenciosa de isto falhar.
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

    /** Um protocolo que a capacidade não anuncia tem de ser recusado, e não absorvido. */
    public function testAnUndeclaredProtocolIsRefused(): void
    {
        $absorbed = [];
        foreach ($this->contracts() as $key => $capability) {
            $protocol = $capability->supportedProtocols()[0] ?? null;
            if ($protocol === null) {
                continue;
            }

            try {
                $capability->toNative('nao-existe-este-protocolo', $capability->defaultValue($protocol));
                $absorbed[] = $key;
            } catch (\InvalidArgumentException) {
                // recusado, que é o que se pede
            }
        }

        self::assertSame([], $absorbed);
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
