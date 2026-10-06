<?php

declare(strict_types=1);

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
     * O fornecedor respondeu "não sei onde isto está" (na ichnaea, um 404): é um resultado normal,
     * e separá-lo das avarias impede que uma verdadeira passe despercebida.
     */
    public function isNoMatch(): bool
    {
        return $this->httpStatus === 404;
    }
}
