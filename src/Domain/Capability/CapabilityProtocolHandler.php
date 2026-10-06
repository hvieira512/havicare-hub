<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

/**
 * A conversão de uma capacidade genérica para um protocolo: codificar, descodificar, valores
 * por omissão, metadados e forma da resposta.
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

    public function merge(mixed $existing, mixed $incoming): mixed;

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array;
}
