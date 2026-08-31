<?php

namespace Hub\Location;

final class LocationProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = true,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /**
     * O fornecedor respondeu, e a resposta foi "não sei onde isto está".
     *
     * A API do BeaconDB segue o feitio da ichnaea: um 404 é a forma de dizer que a evidência
     * -- as células e os pontos de acesso que o dispositivo viu -- não casa com nada que ela
     * conheça. É um resultado normal, e não uma avaria: acontece sempre que um dispositivo
     * anda por um sítio que ainda não está mapeado.
     *
     * Distinguir isto importa por causa do registo. Dezasseis destes em dois dias saíam como
     * `WARNING`, no meio das falhas a sério, e é assim que uma avaria de verdade passa
     * despercebida. O disjuntor já não os contava -- um 404 não é `retryable`, e o
     * `recordFailure` limpa o estado nesse caso --, por isso o que muda é só o nível.
     */
    public function isNoMatch(): bool
    {
        return $this->httpStatus === 404;
    }
}
