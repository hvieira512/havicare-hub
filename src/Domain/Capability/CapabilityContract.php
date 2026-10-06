<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

/**
 * O contrato de uma capacidade genérica (`alarm_clock`, `call_whitelist`, ...), em que o
 * `DeviceService` e o `DeviceConfigurationCatalog` delegam.
 */
interface CapabilityContract
{
    /** A chave genérica usada nos pedidos e respostas da API (por exemplo, `alarm_clock`). */
    public function key(): string;

    /** Se a resposta desta capacidade tem forma de lista (`items`) em vez de valor. */
    public function isList(): bool;

    /**
     * Se várias linhas de configuração do protocolo se podem juntar na mesma entrada pública
     * de capacidade.
     */
    public function supportsMultipleNativeKeys(): bool;

    /**
     * Os protocolos que esta capacidade suporta.
     *
     * @return list<string>
     */
    public function supportedProtocols(): array;

    /**
     * Converte um valor genérico da API no mapa `chave de protocolo => payload` que o
     * `DeviceConfigurationCatalog` sabe consumir.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toNative(string $protocol, mixed $value): array;

    /**
     * Converte o payload pretendido guardado na forma genérica pública. Leva o protocolo porque a
     * mesma chave nativa difere entre fornecedores; `array-key` porque à segunda leitura já é a lista.
     *
     * @param array<array-key, mixed> $desired
     */
    public function fromNative(string $protocol, string $nativeKey, array $desired): mixed;

    /** O valor devolvido quando o dispositivo não tem linha de configuração nenhuma. */
    public function defaultValue(string $protocol): mixed;

    /**
     * Constrói o `_meta` da resposta da API.
     *
     * @param array<string, mixed> $accumulatedMeta  o meta acumulado das linhas de configuração
     * @return array<string, mixed>
     */
    public function meta(string $protocol, array $accumulatedMeta = []): array;

    /**
     * Junta o valor existente com o que chega, quando várias chaves de protocolo mapeiam na
     * mesma chave genérica.
     */
    public function merge(mixed $existing, mixed $incoming): mixed;

    /**
     * Constrói a entrada completa da capacidade, para a resposta da API.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array;
}
