<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

final class MokoMessageDecoder implements MessageDecoder
{
    /** @return array<string, mixed>|null */
    public function decode(string $payload): ?array
    {
        return (new Mkgw3MessageDecoder())->decode($payload) ?? (new Mkgw4MessageDecoder())->decode($payload);
    }
}
