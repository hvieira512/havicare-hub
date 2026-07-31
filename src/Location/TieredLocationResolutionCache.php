<?php

namespace Hub\Location;

final class TieredLocationResolutionCache implements LocationResolutionCacheContract
{
    public function __construct(
        private readonly LocationResolutionCacheContract $local,
        private readonly LocationResolutionCacheContract $shared,
        private readonly int $localTtlSeconds = 60,
    ) {
    }

    public function get(string $evidenceKey): ?array
    {
        $entry = $this->local->get($evidenceKey);
        if ($entry !== null) {
            return $entry;
        }
        $entry = $this->shared->get($evidenceKey);
        if ($entry === null) {
            return null;
        }
        if (($entry['status'] ?? null) === 'resolved') {
            $this->local->putResolved($evidenceKey, $entry['coordinates'], $this->localTtlSeconds);
        } else {
            $this->local->putUnresolved($evidenceKey, $this->localTtlSeconds);
        }

        return $entry;
    }

    public function putResolved(string $evidenceKey, array $coordinates, int $ttlSeconds): void
    {
        $this->shared->putResolved($evidenceKey, $coordinates, $ttlSeconds);
        $this->local->putResolved($evidenceKey, $coordinates, min($ttlSeconds, $this->localTtlSeconds));
    }

    public function putUnresolved(string $evidenceKey, int $ttlSeconds): void
    {
        $this->shared->putUnresolved($evidenceKey, $ttlSeconds);
        $this->local->putUnresolved($evidenceKey, min($ttlSeconds, $this->localTtlSeconds));
    }
}
