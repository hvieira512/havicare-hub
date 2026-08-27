<?php

namespace Hub\Ingress\Mqtt\Moko;

final class MokoMessageDecoder implements MessageDecoder
{
    public function __construct(
        private readonly ?Mkgw3MessageDecoder $mkgw3 = null,
        private readonly ?Mkgw4MessageDecoder $mkgw4 = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function decode(string $payload): ?array
    {
        return ($this->mkgw3 ?? new Mkgw3MessageDecoder())->decode($payload)
            ?? ($this->mkgw4 ?? new Mkgw4MessageDecoder())->decode($payload);
    }
}
