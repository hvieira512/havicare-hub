<?php

namespace Hub\Location;

final class ArrayProviderCircuitStateStore implements ProviderCircuitStateStoreContract
{
    /** @var array<string, array{consecutiveFailures: int, openUntil: int}> */
    private array $states = [];

    public function get(string $provider): array
    {
        return $this->states[$provider] ?? ['consecutiveFailures' => 0, 'openUntil' => 0];
    }

    public function put(string $provider, array $state, int $ttlSeconds): void
    {
        $this->states[$provider] = $state;
    }

    public function clear(string $provider): void
    {
        unset($this->states[$provider]);
    }
}
