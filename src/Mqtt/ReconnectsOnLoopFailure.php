<?php

declare(strict_types=1);

namespace Hub\Mqtt;

use Hub\Log\Logger;
use PhpMqtt\Client\Exceptions\DataTransferException;

/**
 * Reconexão a um broker que largou a ligação; o recuo só se repõe depois de a ligação durar,
 * porque o `connect` bloqueia o event loop. As sessões persistentes não perdem mensagens.
 */
trait ReconnectsOnLoopFailure
{
    /** Quanto tempo uma ligação tem de durar para o recuo a considerar resolvida. */
    private const STABLE_CONNECTION_SECONDS = 60;

    /** Quanto tempo se tolera um socket de saída cheio antes de o dar por morto. */
    private const WRITE_FAILURE_TOLERANCE_SECONDS = 15;

    private float $nextReconnectAt = 0.0;
    private float $connectedSince = 0.0;
    private int $reconnectDelay = 2;
    private float $writeFailingSince = 0.0;
    private float $lastWriteFailureAt = 0.0;

    /** Marcado por quem subscreve, que é o momento a partir do qual se conta a duração. */
    private function markConnected(): void
    {
        $this->connectedSince = microtime(true);
    }

    /**
     * @param callable(): mixed $reconnect  liga de novo; o que devolver não é usado aqui
     * @param callable(): void  $resubscribe  volta a subscrever no cliente novo
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

        if ($this->toleratesWriteFailure($failure, $now)) {
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
            // Sem repor o recuo aqui: é o `markConnected` do `resubscribe` que marca o instante a partir
            // do qual se sabe se a ligação durou.
            $resubscribe();
            // Regista também a recuperação, para vinte quedas por hora não se lerem como uma queda sem
            // regresso.
            Logger::channel('hub')->info(sprintf(
                '%s reconnected after %.1fs down',
                $label,
                microtime(true) - $now,
            ));
        } catch (\Throwable $reconnectError) {
            Logger::channel('hub')->error("{$label} reconnect failed: {$reconnectError->getMessage()}");
        }
    }

    /**
     * Uma escrita que não passa não é uma ligação perdida: com o buffer de saída cheio, a
     * biblioteca lê a escrita parcial como queda. Só uma série que não pára denuncia um socket morto.
     */
    private function toleratesWriteFailure(\Throwable $failure, float $now): bool
    {
        if ($failure->getCode() !== DataTransferException::EXCEPTION_TX_DATA) {
            $this->writeFailingSince = 0.0;
            return false;
        }

        $isNewSeries = $this->writeFailingSince === 0.0
            || ($now - $this->lastWriteFailureAt) > self::WRITE_FAILURE_TOLERANCE_SECONDS;
        if ($isNewSeries) {
            $this->writeFailingSince = $now;
        }
        $this->lastWriteFailureAt = $now;

        if (($now - $this->writeFailingSince) < self::WRITE_FAILURE_TOLERANCE_SECONDS) {
            return true;
        }

        $this->writeFailingSince = 0.0;
        return false;
    }
}
