<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * O que um contrato anuncia e o que ele serve são a mesma lista.
 *
 * Cada contrato diz, no `supportedProtocols`, com que protocolos sabe lidar, e despacha-os
 * depois num `match`. São duas afirmações escritas à mão em sítios diferentes, e este teste
 * prende as duas direcções para todos os contratos de uma vez.
 */
final class CapabilityContractDispatchTest extends TestCase
{
    /**
     * Um protocolo que o contrato não anuncia tem de ser recusado **por ser esse protocolo**,
     * e não por causa do valor.
     *
     * Validar antes de despachar dá a mensagem errada e manda quem depura à procura do valor
     * em vez do protocolo. Despacha-se primeiro, valida-se depois.
     */
    public function testAnUnadvertisedProtocolIsRefusedForBeingUnsupported(): void
    {
        $registry = new CapabilityRegistry();
        $todos = array_keys(ProtocolRegistry::all());
        $errados = [];
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
                    $errados[] = "{$key} serve `{$protocol}` sem o anunciar.";
                } catch (\Throwable $e) {
                    if (!str_contains($e->getMessage(), 'Unsupported protocol')) {
                        $errados[] = "{$key} recusa `{$protocol}` pela razão errada: {$e->getMessage()}";
                    }
                }
            }
        }

        self::assertGreaterThan(0, $verificados, 'Nenhum par contrato/protocolo foi verificado.');
        self::assertSame([], $errados);
    }

    /**
     * E a outra direcção: o que é anunciado tem de ser servido.
     *
     * Um protocolo declarado no `supportedProtocols` mas em falta no despacho passa por todos
     * os outros testes, e o `Unsupported` só aparece ao carregar em Enviar. Só se olha para a
     * razão da recusa: um valor vazio recusado pela validação é o comportamento certo.
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
