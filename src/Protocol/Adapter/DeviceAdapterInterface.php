<?php

declare(strict_types=1);

namespace Hub\Protocol\Adapter;

interface DeviceAdapterInterface
{
    public function protocol(): string;

    public function canDecode(string $raw): bool;

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function decodeIncoming(string $raw, array $context = []): ?array;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    public function encodeOutgoing(array $payload, array $context = []): string;
}
