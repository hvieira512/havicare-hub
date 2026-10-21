<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

/**
 * A conversão de uma capacidade genérica para um protocolo: codificar, descodificar, valores
 * por omissão e metadados.
 */
interface CapabilityProtocolHandler
{
    public function nativeKey(): string;

    /** @return array<string, mixed> */
    public function toNative(mixed $value): array;

    /** @param array<array-key, mixed> $desired */
    public function fromNative(array $desired): mixed;

    public function defaultValue(): mixed;

    /**
     * @param array<string, mixed> $accumulatedMeta
     * @return array<string, mixed>
     */
    public function meta(array $accumulatedMeta = []): array;
}
