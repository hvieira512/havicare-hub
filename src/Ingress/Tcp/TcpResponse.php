<?php

namespace Hub\Ingress\Tcp;

final class TcpResponse
{
    public function __construct(
        public readonly string $bytes,
        public readonly bool $publishRaw = false,
    ) {
    }
}
