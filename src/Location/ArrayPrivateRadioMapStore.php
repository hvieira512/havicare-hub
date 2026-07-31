<?php

declare(strict_types=1);

namespace Hub\Location;

final class ArrayPrivateRadioMapStore implements PrivateRadioMapStoreContract
{
    /** @var array<string, array<string, mixed>> */
    private array $entries = [];

    public function findMany(array $bssidHashes): array
    {
        $found = [];
        foreach ($bssidHashes as $hash) {
            if (isset($this->entries[$hash])) {
                $found[$hash] = $this->entries[$hash];
            }
        }
        return $found;
    }

    public function save(string $bssidHash, array $entry): void
    {
        $this->entries[$bssidHash] = $entry;
    }

    public function saveMany(array $entries): void
    {
        foreach ($entries as $hash => $entry) {
            $this->save($hash, $entry);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->entries;
    }
}
