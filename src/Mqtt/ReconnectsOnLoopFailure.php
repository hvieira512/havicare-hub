<?php

declare(strict_types=1);

namespace Hub\Mqtt;

use Hub\Log\Logger;
use PhpMqtt\Client\MqttClient;

/**
 * Reconectar a um broker que largou a ligação, com recuo.
 *
 * O recuo só volta ao princípio quando a ligação anterior chegou a **durar**: ligar não é o
 * mesmo que ficar ligado, e um broker que aceita e larga logo a seguir devolvia sucesso na
 * mesma. Reposto a cada tentativa, o recuo nunca crescia e dava mais de uma reconexão por
 * segundo -- e o `connect` é bloqueante no mesmo event loop que serve o HTTP, por isso cada
 * tentativa parava a dashboard.
 *
 * Recuar não custa mensagens: as sessões são persistentes e o broker reentrega ao voltar.
 */
trait ReconnectsOnLoopFailure
{
    /** Quanto tempo uma ligação tem de durar para o recuo a considerar resolvida. */
    private const STABLE_CONNECTION_SECONDS = 60;

    private float $nextReconnectAt = 0.0;
    private float $connectedSince = 0.0;
    private int $reconnectDelay = 2;

    /** Marcado por quem subscreve, que é o momento a partir do qual se conta a duração. */
    private function markConnected(): void
    {
        $this->connectedSince = microtime(true);
    }

    /**
     * @param callable(): MqttClient $reconnect  devolve um cliente novo, já ligado
     * @param callable(): void       $resubscribe  volta a subscrever no cliente novo
     */
    private function reconnectAfterLoopFailure(
        \Throwable $failure,
        string $label,
        callable $reconnect,
        callable $resubscribe,
    ): void {
        $now = microtime(true);
        if ($now < $this->nextReconnectAt) {
            return;
        }

        if ($this->connectedSince > 0.0 && ($now - $this->connectedSince) >= self::STABLE_CONNECTION_SECONDS) {
            $this->reconnectDelay = 2;
        }
        $this->connectedSince = 0.0;

        Logger::channel('hub')->warning("{$label} connection lost: {$failure->getMessage()}; reconnecting");
        $this->nextReconnectAt = $now + $this->reconnectDelay;
        $this->reconnectDelay = min($this->reconnectDelay * 2, 60);

        try {
            $reconnect();
            // Sem repor o recuo aqui: e o `markConnected` do `resubscribe` que marca o
            // instante a partir do qual se sabe se a ligacao durou.
            $resubscribe();
        } catch (\Throwable $reconnectError) {
            Logger::channel('hub')->error("{$label} reconnect failed: {$reconnectError->getMessage()}");
        }
    }
}
