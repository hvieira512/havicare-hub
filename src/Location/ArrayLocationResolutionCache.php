<?php

namespace Hub\Location;

final class ArrayLocationResolutionCache implements LocationResolutionCacheContract
{
    /** @var array<string, array{expiresAt: float, entry: array<string, mixed>}> */
    private array $entries = [];

    public function get(string $evidenceKey): ?array
    {
        $cached = $this->entries[$evidenceKey] ?? null;
        if ($cached === null || $cached['expiresAt'] < microtime(true)) {
            unset($this->entries[$evidenceKey]);
            return null;
        }

        return $cached['entry'];
    }

    public function putResolved(string $evidenceKey, array $coordinates, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }
        $this->entries[$evidenceKey] = [
            'expiresAt' => microtime(true) + $ttlSeconds,
            'entry' => ['status' => 'resolved', 'coordinates' => $coordinates],
        ];
    }

    public function putUnresolved(string $evidenceKey, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }
        $this->entries[$evidenceKey] = [
            'expiresAt' => microtime(true) + $ttlSeconds,
            'entry' => ['status' => 'unresolved'],
        ];
    }
}
