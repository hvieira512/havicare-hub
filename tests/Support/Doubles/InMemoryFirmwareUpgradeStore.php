<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Device\Firmware\FirmwareUpgradeStore;

/** O estado da transferência em memória, com o `$state` à vista para o teste o ler. */
final class InMemoryFirmwareUpgradeStore implements FirmwareUpgradeStore
{
    /** @param array<string, mixed> $state */
    public function __construct(public array $state)
    {
    }

    /** @return array<string, mixed>|null */
    public function load(string $imei): ?array
    {
        return $this->state;
    }

    /** @param array<string, mixed> $state */
    public function save(string $imei, array $state): void
    {
        $this->state = $state;
    }
}
