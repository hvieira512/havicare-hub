<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Location\PrivateRadioMapStoreContract;

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

    public function saveMany(array $entries): void
    {
        $this->entries = $entries + $this->entries;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->entries;
    }
}
