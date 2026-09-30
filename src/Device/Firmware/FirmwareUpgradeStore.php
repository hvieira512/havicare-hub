<?php

declare(strict_types=1);

namespace Hub\Device\Firmware;

/**
 * Onde o estado da transferência vive entre tramas: cada pacote é tratado sozinho, e sem isto
 * o hub não saberia em que offset ia.
 */
interface FirmwareUpgradeStore
{
    /** @return array<string, mixed>|null */
    public function load(string $imei): ?array;

    /** @param array<string, mixed> $state */
    public function save(string $imei, array $state): void;
}
