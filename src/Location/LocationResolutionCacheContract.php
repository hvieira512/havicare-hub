<?php

namespace Hub\Location;

interface LocationResolutionCacheContract
{
    /** @return null|array{status: 'resolved'|'unresolved', coordinates?: array<string, float|bool>} */
    public function get(string $evidenceKey): ?array;

    /** @param array<string, float|bool> $coordinates */
    public function putResolved(string $evidenceKey, array $coordinates, int $ttlSeconds): void;

    public function putUnresolved(string $evidenceKey, int $ttlSeconds): void;
}
