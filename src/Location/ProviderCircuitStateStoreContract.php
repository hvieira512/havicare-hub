<?php

namespace Hub\Location;

interface ProviderCircuitStateStoreContract
{
    /** @return array{consecutiveFailures: int, openUntil: int} */
    public function get(string $provider): array;

    /** @param array{consecutiveFailures: int, openUntil: int} $state */
    public function put(string $provider, array $state, int $ttlSeconds): void;

    public function clear(string $provider): void;
}
