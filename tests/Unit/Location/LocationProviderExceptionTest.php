<?php

declare(strict_types=1);

namespace Tests\Unit\Location;

use Hub\Location\LocationProviderException;
use PHPUnit\Framework\TestCase;

/**
 * A diferença entre "não sei onde isto está" e "não consegui perguntar".
 *
 * A primeira é um resultado normal -- o dispositivo andou por um sítio que o BeaconDB ainda
 * não conhece --, e saía no registo como `WARNING` ao lado das avarias a sério: dezasseis em
 * dois dias. É assim que uma avaria de verdade passa despercebida.
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

    /**
     * O disjuntor já tratava o 404 como não repetível, e o `recordFailure` limpa o estado
     * nesse caso. Isto prende esse acordo: mudar o nível do registo não pode ter mexido nele.
     */
    public function testANoMatchIsNotRetryable(): void
    {
        $error = new LocationProviderException('BeaconDB request failed (HTTP 404)', 'beacondb', 404, false);

        self::assertFalse($error->retryable);
    }
}
