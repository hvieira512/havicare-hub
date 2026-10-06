<?php

declare(strict_types=1);

namespace Hub\Device;

final class PendingDownlink
{
    /** @param array<string, mixed>|null $command */
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
