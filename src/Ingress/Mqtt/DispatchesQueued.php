<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt;

/**
 * Uma ingestão que tem o que entregar entre mensagens recebidas.
 *
 * O caso são as pulseiras servidas por um gateway BLE: o comando é criado pela API ou pelo
 * ecrã e fica em fila, e o gateway está subscrito ao seu tópico de comandos enquanto correr --
 * portanto não há razão para esperar pelo anúncio de sessão seguinte, que chega de 30 em 30 s,
 * mais do que a pulseira leva a desistir de vibrar.
 *
 * É uma interface e não um caso especial no arranque porque o `bin/server-hub.php` guardava a
 * bridge Veepoo numa variável só para lhe pendurar o temporizador. Quem conduz ingestões é o
 * `IngressRunner`, e quem tem fila di-lo aqui.
 */
interface DispatchesQueued
{
    /** Entrega o que estiver em fila. Chamado por um temporizador do loop. */
    public function dispatchQueued(): void;
}
