<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Device\DownlinkRetryContext;
use PHPUnit\Framework\TestCase;

/** Nos protocolos de gateway os bytes em fila são só o nome da operação, e repetir sem o valor executa com o interruptor a falso. */
final class DownlinkRetryContextTest extends TestCase
{
    public function testTheValueTravelsWithTheRetry(): void
    {
        $context = DownlinkRetryContext::forCommand([
            'operationId' => 'op-1',
            'changeId' => 'ch-1',
            'genericConfigKey' => 'heart_rate_alert',
            'nativeType' => 'config:heart_rate_alert',
            'payload' => ['enabled' => true, 'maxBpm' => 160, 'minBpm' => 45],
        ]);

        self::assertSame(['enabled' => true, 'maxBpm' => 160, 'minBpm' => 45], $context['payload']);
        self::assertSame('config:heart_rate_alert', $context['command']);
        self::assertSame('op-1', $context['operationId']);
    }

    /** É pelo identificador que o gateway distingue uma reentrega de um pedido novo, que voltaria a medir. */
    public function testTheRequestKeepsItsIdentityAcrossRetries(): void
    {
        $context = DownlinkRetryContext::forCommand([
            'id' => 'a1b2c3d4',
            'nativeType' => 'measure.heartRate.start',
        ]);

        self::assertSame('a1b2c3d4', $context['id']);
    }

    /** A chave de de-duplicação sai do identificador da operação, para a repetição substituir o comando em fila em vez de se somar. */
    public function testACommandWithoutAnOperationKeepsItsOwnIdentity(): void
    {
        $context = DownlinkRetryContext::forCommand([
            'nativeType' => 'measure.ecg.start',
            'payload' => null,
        ]);

        self::assertSame('measure.ecg.start', $context['command']);
        self::assertArrayNotHasKey('operationId', $context);
        self::assertArrayNotHasKey('payload', $context);
    }

    /** Um comando sem nada de que se agarre não inventa contexto. */
    public function testAnEmptyCommandGivesNoContext(): void
    {
        self::assertNull(DownlinkRetryContext::forCommand([]));
    }
}
