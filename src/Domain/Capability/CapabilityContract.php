<?php

namespace Hub\Domain\Capability;

/**
 * O contrato de uma capacidade genérica.
 *
 * Cada capacidade (`alarm_clock`, `call_whitelist`, `fall_detection`, ...) implementa esta
 * interface para o `DeviceService` e o `DeviceConfigurationCatalog` delegarem num objecto em
 * vez de espalharem a lógica por braços de `match`.
 */
interface CapabilityContract
{
    /** A chave genérica usada nos pedidos e respostas da API (por exemplo, `alarm_clock`). */
    public function key(): string;

    /** A secção a que esta capacidade pertence (por exemplo, `alarms` ou `contacts`). */
    public function section(): string;

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
     * Converte um payload pretendido guardado em `device_configurations` de volta à forma
     * genérica pública, para a resposta da API.
     */
    public function fromNative(string $nativeKey, array $desired): mixed;

    /** O valor devolvido quando o dispositivo não tem linha de configuração nenhuma. */
    public function defaultValue(string $protocol): mixed;

    /**
     * Constrói o `_meta` da resposta da API.
     *
     * @param array<string, mixed> $accumulatedMeta  Meta accumulated from config rows
     */
    public function meta(string $protocol, array $accumulatedMeta = []): array;

    /**
     * Junta o valor existente com o que chega, quando várias chaves de protocolo mapeiam na
     * mesma chave genérica.
     */
    public function merge(mixed $existing, mixed $incoming): mixed;

    /** Constrói a entrada completa da capacidade, para a resposta da API. */
    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array;

    /**
     * A chave de configuração específica do protocolo, usada na camada de persistência e de
     * transporte. Não é a chave pública da API, que continua a ser o nome genérico.
     */
    public function resolveConfigKey(string $protocol, string $key): ?string;
}
