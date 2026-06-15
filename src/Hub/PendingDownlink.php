<?php

namespace App\Hub;

final class PendingDownlink
{
    public function __construct(
        public readonly string $imei,
        public readonly string $dedupeKey,
        public readonly string $bytes,
        public readonly ?array $command,
        public readonly int $queuedAt,
        public readonly int $expiresAt,
    ) {
    }
}
