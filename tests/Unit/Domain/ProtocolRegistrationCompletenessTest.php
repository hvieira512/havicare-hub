<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Domain\ProtocolRegistry;
use Hub\Protocol\AdapterRegistry;
use Hub\Device\Watch\WatchProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Um protocolo não é um conceito, é uma convenção espalhada por cinco registos.
 *
 * Trinta e três ficheiros nomeiam uma string de protocolo, e cinco registos independentes
 * enumeram os mesmos fornecedores: o `AdapterRegistry` (o codec da linha), o
 * `WatchProtocolRegistry` (a sessão e o handshake), o `ProtocolRegistry` (os metadados), o
 * `DeviceConfigurationCatalog` (as configurações) e o `CapabilityRegistry` (as capacidades).
 * Nada os liga. Um fornecedor registado em quatro dos cinco compila, passa no PHPStan, e
 * falha em produção num caminho de código só.
 *
 * Consolidar isso numa peça só é um trabalho grande e, com três fornecedores, ainda a
 * adivinhar a forma certa. Este teste é a alternativa barata: não remove o espalhamento,
 * torna-o verificável. Quando aparecer o quarto fornecedor, é isto que diz exactamente do
 * que um fornecedor é feito -- e aí a interface certa deixa de ser um palpite.
 */
final class ProtocolRegistrationCompletenessTest extends TestCase
{
    /** @return list<string> */
    private static function watchProtocols(): array
    {
        $watch = [];
        foreach (ProtocolRegistry::all() as $protocol => $meta) {
            if (($meta['deviceType'] ?? '') === 'watch') {
                $watch[] = $protocol;
            }
        }

        return $watch;
    }

    public function testTheWatchProtocolsAreTheOnesWeExpect(): void
    {
        // Uma âncora, para as asserções abaixo não passarem por a lista ter ficado vazia.
        self::assertSame(
            ['wonlex-json', 'vivistar-iw', 'four-p-touch'],
            self::watchProtocols()
        );
    }

    /** Sem codec, o dispositivo liga-se e nada do que ele diz é entendido. */
    public function testEveryWatchProtocolHasAWireAdapter(): void
    {
        $adapters = new AdapterRegistry();

        foreach (self::watchProtocols() as $protocol) {
            self::assertNotNull(
                $adapters->get($protocol),
                "O protocolo `{$protocol}` está declarado no ProtocolRegistry mas não tem adaptador."
            );
        }
    }

    /** Sem protocolo de sessão, o dispositivo nunca chega a autenticar-se. */
    public function testEveryWatchProtocolHasASessionProtocol(): void
    {
        $sessions = new WatchProtocolRegistry();

        foreach (self::watchProtocols() as $protocol) {
            self::assertNotNull(
                $sessions->get($protocol),
                "O protocolo `{$protocol}` não tem entrada no WatchProtocolRegistry."
            );
        }
    }

    /**
     * E o sentido inverso: um adaptador ou uma sessão sem metadados é um protocolo que a
     * dashboard não sabe nomear nem configurar.
     */
    public function testEveryRegisteredImplementationIsDeclaredInTheProtocolRegistry(): void
    {
        foreach ((new AdapterRegistry())->protocols() as $protocol) {
            self::assertTrue(
                ProtocolRegistry::exists($protocol),
                "O adaptador `{$protocol}` não está declarado no ProtocolRegistry."
            );
        }

        foreach (array_keys((new WatchProtocolRegistry())->all()) as $protocol) {
            self::assertTrue(
                ProtocolRegistry::exists((string)$protocol),
                "O protocolo de sessão `{$protocol}` não está declarado no ProtocolRegistry."
            );
        }
    }

    /** Prometer um catálogo de configuração e não ter nenhum deixa o separador vazio. */
    public function testEveryProtocolThatPromisesAConfigCatalogHasOne(): void
    {
        $promised = ProtocolRegistry::protocolsWithConfigCatalog();
        self::assertNotSame([], $promised);

        foreach ($promised as $protocol) {
            self::assertNotSame(
                [],
                DeviceConfigurationCatalog::configsForProtocol($protocol),
                "O protocolo `{$protocol}` diz suportar catálogo de configuração mas não declara nenhuma."
            );
        }
    }

    /**
     * Uma capacidade que diz suportar `wonlex` em vez de `wonlex-json` nunca casa com nada,
     * e não há nada que se queixe: o valor genérico simplesmente não chega ao dispositivo.
     */
    public function testNoCapabilityClaimsAProtocolThatDoesNotExist(): void
    {
        $registry = new CapabilityRegistry();
        $checked = 0;

        foreach (CapabilityCatalog::keys() as $key) {
            $contract = $registry->get($key);
            if ($contract === null) {
                // As capacidades simples são tratadas genericamente pelos metadados do
                // catálogo e não têm contrato próprio.
                continue;
            }

            foreach ($contract->supportedProtocols() as $protocol) {
                $checked++;
                self::assertTrue(
                    ProtocolRegistry::exists($protocol),
                    "A capacidade `{$key}` diz suportar o protocolo `{$protocol}`, que não existe."
                );
            }
        }

        self::assertGreaterThan(0, $checked, 'Nenhuma capacidade foi verificada -- a varredura falhou.');
    }
}
