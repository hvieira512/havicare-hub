<?php

declare(strict_types=1);

namespace Hub\Device;

interface PendingDownlinkQueue
{
    /** @param array<string, mixed>|null $command o comando que o downlink transporta, para registo */
    public function enqueue(string $imei, string $bytes, ?array $command, int $ttlSeconds): PendingDownlink;

    /**
     * @return array<int, PendingDownlink>
     */
    public function pendingFor(string $imei): array;

    public function remove(PendingDownlink $downlink): void;
}
