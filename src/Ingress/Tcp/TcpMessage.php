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
     * @param \Closure(): void|null $withheld desfaz o que o protocolo deu por feito, quando as respostas não saem
     * @param bool $onlyInRaw o conteúdo não se leu, e só os bytes do canal `raw` o guardam
     */
    public function __construct(
        public readonly array $decoded,
        public readonly array $telemetry = [],
        public readonly array $responses = [],
        public readonly bool $repliesConfirmDelivery = false,
        public readonly ?\Closure $withheld = null,
        public readonly bool $onlyInRaw = false,
    ) {
    }
}
