<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\FourPTouch\FourPTouchGenericHandler;
use Hub\Domain\ProtocolRegistry;

/**
 * A capacidade de quem não tem contrato escrito à mão -- interruptores, números, intervalos,
 * mensagens --, descritas no `DeviceConfigurationCatalog`.
 */
final class GenericCapability implements CapabilityContract
{
    use CapabilityHelpers;

    public function __construct(
        private string $genericKey,
        private FourPTouchGenericHandler $fourPTouch,
    ) {
    }

    public function key(): string
    {
        return $this->genericKey;
    }

    public function isList(): bool
    {
        return false;
    }

    public function supportsMultipleNativeKeys(): bool
    {
        return false;
    }

    /**
     * Todos. Uma capacidade sem contrato próprio não declara restrição, e a lista dos
     * protocolos conhecidos diz isso sem ter de aceitar um protocolo que não existe.
     *
     * @return list<string>
     */
    public function supportedProtocols(): array
    {
        return ProtocolRegistry::keys();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toNative(string $protocol, mixed $value): array
    {
        // Sem recusa por omissão: para quem não traduz nomes, o nativo é a própria chave genérica.
        return match ($protocol) {
            'vivistar-iw' => $this->vivistarToNative($value),
            'wonlex-json' => $this->wonlexGenericToNative($value),
            'four-p-touch' => $this->fourPTouch->toNative($this->genericKey, $value),
            default => $this->passThroughToNative($value),
        };
    }

    /**
     * Os protocolos cujo nome nativo é a chave genérica: quem monta a trama -- o gateway BLE da
     * Veepoo, o `DeviceCommandCatalog` do dispensador -- recebe a operação e o valor.
     *
     * @return array<string, array<string, mixed>>
     */
    private function passThroughToNative(mixed $value): array
    {
        return [$this->genericKey => self::requireObjectValue($value, $this->genericKey)];
    }

    public function fromNative(string $protocol, string $nativeKey, array $desired): mixed
    {
        if ($nativeKey !== '' && FourPTouchGenericHandler::nativeKeyToGenericKey($nativeKey) !== null) {
            return $this->fourPTouch->fromNative($this->genericKey, $nativeKey, $desired);
        }

        return $desired;
    }

    public function defaultValue(string $protocol): mixed
    {
        $entry = $this->findConfigEntry($protocol);

        return $entry === null ? [] : ConfigurationInputDefaults::forEntry($entry);
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        return $accumulatedMeta;
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return self::mergeAssociativeValues($existing, $incoming);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return ['value' => $value, '_meta' => $meta];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function vivistarToNative(mixed $value): array
    {
        return match ($this->genericKey) {
            'working_mode' => ['workingMode' => self::requireObjectValue($value, 'workingMode')],
            'fall_detection' => ['fallDetection' => ['enabled' => self::requireBoolLikeField($value, 'enabled')]],
            'fall_sensitivity' => ['fallSensitivity' => ['sensitivity' => self::requireIntField($value, 'sensitivity')]],
            'push_message' => ['pushMessage' => ['message' => self::requireStringField($value, 'message')]],
            'auto_vitals_interval' => ['autoHealthMeasurement' => self::requireObjectValue($value, 'autoHealthMeasurement')],
            default => throw new \InvalidArgumentException(
                "Unsupported vivistar-iw capability {$this->genericKey}"
            ),
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function wonlexGenericToNative(mixed $value): array
    {
        return match ($this->genericKey) {
            'reset_device' => ['factoryResetCommand' => []],
            'restart_device' => ['restartCommand' => []],
            'power_off' => ['powerOffCommand' => []],
            'find_device' => ['findDeviceCommand' => []],
            default => [$this->resolveWonlexNativeKey() => self::requireObjectValue($value, $this->genericKey)],
        };
    }

    private function resolveWonlexNativeKey(): string
    {
        $entry = $this->findConfigEntry('wonlex-json');
        if ($entry === null) {
            throw new \InvalidArgumentException("Unsupported wonlex-json capability {$this->genericKey}");
        }

        return trim((string)$entry['key']);
    }

    /**
     * A linha do catálogo do protocolo que corresponde a esta chave genérica.
     *
     * @return array<string, mixed>|null
     */
    private function findConfigEntry(string $protocol): ?array
    {
        foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
            $nativeKey = trim((string)($entry['key'] ?? ''));
            if ($nativeKey !== '' && CapabilityCatalog::mapConfigurationKey($nativeKey) === $this->genericKey) {
                return $entry;
            }
        }

        return null;
    }
}
