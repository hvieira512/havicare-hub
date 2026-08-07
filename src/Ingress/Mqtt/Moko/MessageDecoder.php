<?php

namespace Hub\Ingress\Mqtt\Moko;

interface MessageDecoder
{
    /** @return array<string, mixed>|null */
    public function decode(string $payload): ?array;
}
