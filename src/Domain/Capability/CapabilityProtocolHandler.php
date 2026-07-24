<?php

namespace Hub\Domain\Capability;

/**
 * Protocol-specific conversion contract for a generic capability.
 *
 * A generic capability contract can delegate protocol-specific encoding,
 * decoding, defaulting, metadata and response shaping to one or more
 * handlers. The generic API contract stays stable while supplier/protocol
 * fan-out and protocol-specific wire names vary underneath it.
 */
interface CapabilityProtocolHandler
{
    public function nativeKey(): string;

    public function toNative(mixed $value): array;

    public function fromNative(array $desired): mixed;

    public function defaultValue(): mixed;

    public function meta(array $accumulatedMeta = []): array;

    public function merge(mixed $existing, mixed $incoming): mixed;

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array;
}
