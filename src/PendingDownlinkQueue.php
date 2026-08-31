<?php

namespace Hub;

interface PendingDownlinkQueue
{
    public function enqueue(string $imei, string $bytes, ?array $command, int $ttlSeconds): PendingDownlink;

    /**
     * @return array<int, PendingDownlink>
     */
    public function pendingFor(string $imei): array;

    public function remove(PendingDownlink $downlink): void;
}
