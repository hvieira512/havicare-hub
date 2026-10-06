<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * O que cada contrato anuncia no `supportedProtocols` e o que o seu `match` despacha são a
 * mesma lista, nas duas direcções.
 */
final class CapabilityContractDispatchTest extends TestCase
{
    /** Despacha-se antes de validar, para a recusa apontar o protocolo e não o valor. */
    public function testAnUnadvertisedProtocolIsRefusedForBeingUnsupported(): void
    {
        $registry = new CapabilityRegistry();
        $todos = array_keys(ProtocolRegistry::all());
        $wrong = [];
        $verificados = 0;

        foreach (CapabilityCatalog::keys() as $key) {
            $contract = $registry->get($key);
            if ($contract === null) {
                continue;
            }

            $anunciados = $contract->supportedProtocols();
            // A capacidade genérica anuncia todos os protocolos de propósito: não ter contrato
            // próprio é o caso normal, e não há aqui divergência possível.
            if (count($anunciados) === count($todos)) {
                continue;
            }

            foreach (array_diff($todos, $anunciados) as $protocol) {
                $verificados++;
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

        self::assertGreaterThan(0, $verificados, 'Nenhum par contrato/protocolo foi verificado.');
        self::assertSame([], $wrong);
    }

    /**
     * Um protocolo anunciado e em falta no despacho só falha ao carregar em Enviar. Um valor vazio
     * recusado pela validação é o comportamento certo.
     */
    public function testEveryAdvertisedProtocolIsActuallyServed(): void
    {
        $registry = new CapabilityRegistry();
        $porServir = [];
        $verificados = 0;

        foreach (CapabilityCatalog::keys() as $key) {
            $contract = $registry->get($key);
            if ($contract === null) {
                continue;
            }

            foreach ($contract->supportedProtocols() as $protocol) {
                $verificados++;
                try {
                    $contract->toNative($protocol, []);
                } catch (\Throwable $e) {
                    if (str_contains($e->getMessage(), 'Unsupported')) {
                        $porServir[] = "{$key} anuncia `{$protocol}` e não o serve: {$e->getMessage()}";
                    }
                }
            }
        }

        self::assertGreaterThan(0, $verificados, 'Nenhum par contrato/protocolo foi verificado.');
        self::assertSame([], $porServir);
    }
}
