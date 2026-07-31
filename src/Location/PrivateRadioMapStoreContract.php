<?php

declare(strict_types=1);

namespace Hub\Location;

interface PrivateRadioMapStoreContract
{
    /**
     * @param list<string> $bssidHashes
     * @return array<string, array<string, mixed>> Entries keyed by BSSID hash.
     */
    public function findMany(array $bssidHashes): array;

    /** @param array<string, mixed> $entry */
    public function save(string $bssidHash, array $entry): void;

    /** @param array<string, array<string, mixed>> $entries Entries keyed by BSSID hash. */
    public function saveMany(array $entries): void;
}
