<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

/**
 * O contrato de conversão específica de protocolo, para uma capacidade genérica.
 *
 * Um contrato genérico pode delegar num ou mais handlers a codificação, a descodificação, os
 * valores por omissão, os metadados e a forma da resposta. O contrato público da API fica
 * estável enquanto o leque de fornecedores e os nomes de fio variam por baixo dele.
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
