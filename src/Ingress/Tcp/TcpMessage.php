<?php

declare(strict_types=1);

namespace Hub\Ingress\Tcp;

final class TcpMessage
{
    /**
     * @param array<string, mixed> $decoded
     * @param array<int, array<string, mixed>> $telemetry
     * @param array<int, TcpResponse> $responses
     * @param bool $repliesConfirmDelivery o aparelho apaga o que mandou quando recebe a resposta
     */
    public function __construct(
        public readonly array $decoded,
        public readonly array $telemetry = [],
        public readonly array $responses = [],
        public readonly bool $repliesConfirmDelivery = false,
    ) {
    }
}
