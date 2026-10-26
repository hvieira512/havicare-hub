<?php

declare(strict_types=1);

namespace Hub\Command;

/** Os estados de entrega de um comando, onde mais de um sítio decide pela mesma lista. */
final class DeliveryStatus
{
    /** À espera do aparelho: em fila, ou enviado e sem resposta. */
    public const PENDING = ['queued', 'waiting'];

    /** A caminho do aparelho, contando o que saiu sem resposta prevista. */
    public const IN_FLIGHT = ['queued', 'waiting', 'sent'];

    /** Os estados de que um comando não sai. */
    public const FINISHED = ['acked', 'failed', 'dropped', 'superseded'];

    /** As falhas que a entrega de uma configuração pode dar. */
    public const FAILURES = ['failed', 'dropped', 'delivery_failed', 'retry_exhausted', 'response_timeout'];

    /** Não chegou ao aparelho: o mesmo valor, pedido outra vez, volta a ser enviado. */
    public const UNDELIVERED = ['created', 'failed', 'retry_exhausted', 'response_timeout', 'dropped'];
}
