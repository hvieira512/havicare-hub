<?php

declare(strict_types=1);

namespace Hub\Command;

/** Os estados de entrega de um comando, onde mais de um sítio decide pela mesma lista. */
final class DeliveryStatus
{
    /** Não chegou ao aparelho: o mesmo valor, pedido outra vez, volta a ser enviado. */
    public const UNDELIVERED = ['created', 'failed', 'retry_exhausted', 'response_timeout', 'dropped'];
}
