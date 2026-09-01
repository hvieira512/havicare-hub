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
     * O fornecedor respondeu, e a resposta foi "não sei onde isto está" -- na ichnaea, um 404
     * quer dizer que a evidência não casa com nada que ela conheça.
     *
     * É um resultado normal e não uma avaria, e distingui-lo importa para o registo: no meio
     * das falhas a sério, é assim que uma avaria de verdade passa despercebida.
     */
    public function isNoMatch(): bool
    {
        return $this->httpStatus === 404;
    }
}
