<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt;

/**
 * Uma ingestão que tem o que entregar entre mensagens recebidas, sem esperar pelo anúncio de
 * sessão seguinte do gateway. O `IngressRunner` chama-a por temporizador.
 */
interface DispatchesQueued
{
    /** Entrega o que estiver em fila. Chamado por um temporizador do loop. */
    public function dispatchQueued(): void;
}
