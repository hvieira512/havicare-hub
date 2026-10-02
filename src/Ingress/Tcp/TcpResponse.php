<?php

declare(strict_types=1);

namespace Hub\Ingress\Tcp;

final class TcpResponse
{
    public function __construct(
        public readonly string $bytes,
        public readonly bool $publishRaw = false,
    ) {
    }
}
