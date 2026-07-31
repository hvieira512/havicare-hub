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
}
