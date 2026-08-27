<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\Ingress\Mqtt\Qinglanst\DashboardWritePolicy;
use PHPUnit\Framework\TestCase;

/**
 * O estrangulamento da escrita do histórico no Redis.
 *
 * A política é chamada com a chave da capacidade e não com o tipo do envelope do fabricante.
 * Trocar as duas não estoira nada: a amostragem deixa de actuar, cada leitura vai para o
 * Redis, e só se vê na factura.
 */
final class DashboardWritePolicyTest extends TestCase
{
    public function testPositionsAreSampledAndEverythingElseAlwaysStores(): void
    {
        $policy = new DashboardWritePolicy(positionHistorySampleMs: 1000);

        self::assertTrue($policy->shouldStoreTelemetry('radar-1', 'positions', 0));
        self::assertFalse(
            $policy->shouldStoreTelemetry('radar-1', 'positions', 500),
            'uma posição dentro da janela de amostragem não vai para o histórico',
        );
        self::assertTrue($policy->shouldStoreTelemetry('radar-1', 'positions', 1000));

        // Os sinais vitais chegam ao mesmo ritmo e passam sempre: são o que o cartão mostra.
        self::assertTrue($policy->shouldStoreTelemetry('radar-1', 'vitals', 0));
        self::assertTrue($policy->shouldStoreTelemetry('radar-1', 'vitals', 1));
    }

    /** A janela é por dispositivo: um radar movimentado não cala o do lado. */
    public function testTheSamplingWindowIsPerDevice(): void
    {
        $policy = new DashboardWritePolicy(positionHistorySampleMs: 1000);

        self::assertTrue($policy->shouldStoreTelemetry('radar-1', 'positions', 0));
        self::assertTrue($policy->shouldStoreTelemetry('radar-2', 'positions', 0));
    }

    public function testSamplingOffStoresEveryReading(): void
    {
        $policy = new DashboardWritePolicy(positionHistorySampleMs: 0);

        self::assertTrue($policy->shouldStoreTelemetry('radar-1', 'positions', 0));
        self::assertTrue($policy->shouldStoreTelemetry('radar-1', 'positions', 1));
    }
}
