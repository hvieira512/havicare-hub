<?php

declare(strict_types=1);

namespace Hub\Mqtt;

use Hub\Log\Logger;
use PhpMqtt\Client\MqttClient;

/**
 * Reconectar a um broker que largou a ligação, com recuo.
 *
 * Esteve escrito duas vezes, linha por linha -- no `Ingress\Mqtt\Bridge` e no subscritor
 * de downlink, entretanto removido --, e as duas cópias tinham o mesmo defeito: repunham o recuo
 * assim que o `connect` devolvia sucesso. Ligar não é o mesmo que ficar ligado. Um broker
 * que aceita e larga logo a seguir -- o que um `client_id` duplicado provoca, porque o
 * segundo cliente a chegar expulsa o primeiro -- devolvia sempre sucesso, e o recuo era
 * reposto antes de alguma vez crescer.
 *
 * O resultado era mais de uma reconexão por segundo, e isso não é ruído no log: o
 * `IngressRunner` agenda estes ticks no mesmo event loop que serve o HTTP, e o `connect` é
 * bloqueante. Cada tentativa parava a dashboard. Media-se em pedidos de 88 a 400 ms com
 * `duration_ms: 0` registado pelo próprio hub -- tempo à espera do loop, não a trabalhar.
 *
 * O recuo só volta ao princípio quando a ligação anterior chegou a durar. Recuar não custa
 * mensagens: as sessões são persistentes (`cleanSession = false`) e as subscrições são
 * QoS 1, por isso o broker guarda o que chega enquanto estamos fora e reentrega ao voltar.
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
