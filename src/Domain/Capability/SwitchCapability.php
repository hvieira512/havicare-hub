<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

/** Um interruptor com contrato: `{enabled}` nos dois sentidos, debaixo da chave nativa de cada protocolo. */
final class SwitchCapability implements CapabilityContract
{
    use CapabilityHelpers;

    /**
     * @param array<string, string> $nativeKeys protocolo => chave nativa; é também quem se serve
     * @param list<string> $offByDefault os protocolos em que vem desligado de fábrica
     * @param array<string, array<string, mixed>> $meta protocolo => metadados
     */
    public function __construct(
        private string $key,
        private array $nativeKeys,
        private bool $multipleNativeKeys = false,
        private array $offByDefault = [],
        private array $meta = [],
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function isList(): bool
    {
        return false;
    }

    public function supportsMultipleNativeKeys(): bool
    {
        return $this->multipleNativeKeys;
    }

    public function supportedProtocols(): array
    {
        return array_keys($this->nativeKeys);
    }

    public function toNative(string $protocol, mixed $value): array
    {
        $nativeKey = $this->nativeKeys[$protocol]
            ?? throw new \InvalidArgumentException("Unsupported protocol {$protocol} for {$this->key}");

        return [$nativeKey => ['enabled' => self::requireBoolLikeField($value, 'enabled')]];
    }

    public function fromNative(string $protocol, string $nativeKey, array $desired): mixed
    {
        return ['enabled' => (bool)($desired['enabled'] ?? false)];
    }

    public function defaultValue(string $protocol): mixed
    {
        return ['enabled' => !in_array($protocol, $this->offByDefault, true)];
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        return array_replace_recursive($this->meta[$protocol] ?? [], $accumulatedMeta);
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return self::mergeAssociativeValues($existing, $incoming);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return ['value' => $value, '_meta' => $this->meta($protocol, $meta)];
    }
}
