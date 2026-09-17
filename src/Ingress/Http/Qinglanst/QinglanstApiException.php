<?php

namespace Hub\Ingress\Http\Qinglanst;

final class QinglanstApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }
}
