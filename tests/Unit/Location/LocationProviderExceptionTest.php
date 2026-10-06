<?php

declare(strict_types=1);

namespace Tests\Unit\Location;

use Hub\Location\LocationProviderException;
use PHPUnit\Framework\TestCase;

/**
 * "Não sei onde isto está" é um resultado normal e "não consegui perguntar" é uma avaria, e
 * os dois não podem sair no registo ao mesmo nível.
 */
final class LocationProviderExceptionTest extends TestCase
{
    public function testA404IsAResultAndNotAFailure(): void
    {
        $error = new LocationProviderException('BeaconDB request failed (HTTP 404)', 'beacondb', 404, false);

        self::assertTrue($error->isNoMatch());
    }

    public function testEverythingElseIsAFailure(): void
    {
        foreach ([429, 500, 502, 503, 400, 401] as $status) {
            self::assertFalse(
                (new LocationProviderException('boom', 'beacondb', $status))->isNoMatch(),
                "O estado {$status} não pode passar por 'sem correspondência'."
            );
        }
    }

    /** Uma falha de rede não traz estado nenhum, e também não é uma resposta. */
    public function testAnErrorWithoutAnHttpStatusIsAFailure(): void
    {
        self::assertFalse((new LocationProviderException('connection refused', 'beacondb'))->isNoMatch());
    }

    /** O 404 não é repetível, e o `recordFailure` limpa o estado nesse caso. */
    public function testANoMatchIsNotRetryable(): void
    {
        $error = new LocationProviderException('BeaconDB request failed (HTTP 404)', 'beacondb', 404, false);

        self::assertFalse($error->retryable);
    }
}
