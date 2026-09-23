<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt;

/**
 * Uma ingestão que tem o que entregar entre mensagens recebidas.
 *
 * O caso são as pulseiras servidas por um gateway BLE: o gateway está subscrito ao seu tópico
 * de comandos enquanto correr, e não há razão para esperar pelo anúncio de sessão seguinte.
 * Quem conduz ingestões é o `IngressRunner`, e quem tem fila di-lo aqui.
 */
interface DispatchesQueued
{
    /** Entrega o que estiver em fila. Chamado por um temporizador do loop. */
    public function dispatchQueued(): void;
}
