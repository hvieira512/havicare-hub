<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Device\DownlinkRetryContext;
use PHPUnit\Framework\TestCase;

/**
 * Uma repetição tem de levar o mesmo valor que a primeira tentativa.
 *
 * Nos protocolos que entregam a um gateway, os bytes em fila são só o nome da operação -- o
 * valor viaja ao lado. Repetir com os bytes e mais nada põe em fila um comando sem valor, e
 * o gateway executa-o com o interruptor a falso: foi assim que um alerta de frequência
 * cardíaca já aplicado se desligou sozinho, e que um ECG correu quatro vezes seguidas.
 */
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

    /**
     * A chave de de-duplicação sai do identificador da operação, e sem ele uma repetição
     * entra na fila como comando novo em vez de substituir o que lá está.
     */
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
