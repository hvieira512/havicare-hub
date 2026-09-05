<?php

namespace Hub\Domain\Capability;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\FourPTouch\FourPTouchGenericHandler;
use Hub\Domain\ProtocolRegistry;

/**
 * A capacidade de quem não tem uma escrita à mão -- interruptores, números, intervalos,
 * mensagens. O que é preciso saber sobre elas está no `DeviceConfigurationCatalog`.
 *
 * Não ter contrato próprio é o caso normal, e é por isto ser um contrato como os outros que o
 * registo devolve sempre alguém.
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
        return match ($protocol) {
            'vivistar-iw' => $this->vivistarToNative($value),
            'wonlex-json' => $this->wonlexGenericToNative($value),
            'four-p-touch' => $this->fourPTouch->toNative($this->genericKey, $value),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol}"),
        };
    }

    public function fromNative(string $protocol, string $nativeKey, array $desired): mixed
    {
        if ($nativeKey !== '' && FourPTouchGenericHandler::nativeKeyToGenericKey($nativeKey) !== null) {
            return $this->fourPTouch->fromNative($this->genericKey, $nativeKey, $desired);
        }

        return $protocol === 'wonlex-json'
            ? $this->wonlexFromNative($desired)
            : $desired;
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


    // ------------------------------------------------------------------
    // vivistar-iw
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // wonlex-json
    // ------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    private function wonlexGenericToNative(mixed $value): array
    {
        return match ($this->genericKey) {
            'reset_device' => ['resetCommand' => []],
            'restart_device' => ['restartCommand' => []],
            'power_off' => ['powerOffCommand' => []],
            'find_device' => ['findDeviceCommand' => []],
            'push_message' => ['pushMessage' => ['message' => self::requireStringField($value, 'message')]],
            default => [
                $this->resolveWonlexNativeKey() => $this->wonlexToNative(
                    self::requireObjectValue($value, $this->genericKey)
                ),
            ],
        };
    }

    /**
     * Mantém os nomes de transporte da Wonlex fora do contrato genérico da API.
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function wonlexFromNative(array $value): array
    {
        if (array_key_exists('switchState', $value)) {
            $value['enabled'] = self::wonlexBool($value['switchState'], 'switchState');
            unset($value['switchState']);
        }
        if (array_key_exists('exerciseSwitchState', $value)) {
            $value['exerciseEnabled'] = self::wonlexBool($value['exerciseSwitchState'], 'exerciseSwitchState');
            unset($value['exerciseSwitchState']);
        }

        return $value;
    }

    /**
     * Aceita os nomes normalizados da API mantendo a compatibilidade com payloads antigos
     * que já traziam campos nativos da Wonlex.
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function wonlexToNative(array $value): array
    {
        if (array_key_exists('enabled', $value) && !array_key_exists('switchState', $value)) {
            $value['switchState'] = self::wonlexBool($value['enabled'], 'enabled');
        }
        unset($value['enabled']);

        if (array_key_exists('exerciseEnabled', $value) && !array_key_exists('exerciseSwitchState', $value)) {
            $value['exerciseSwitchState'] = self::wonlexBool($value['exerciseEnabled'], 'exerciseEnabled');
        }
        unset($value['exerciseEnabled']);

        return $value;
    }

    private static function wonlexBool(mixed $value, string $field): bool
    {
        $normalized = self::requireBoolLikeValue($value, $field);

        return in_array($normalized, [true, 1, '1'], true);
    }

    private function resolveWonlexNativeKey(): string
    {
        $entry = $this->findConfigEntry('wonlex-json');
        if ($entry === null) {
            throw new \InvalidArgumentException("Unsupported wonlex-json capability {$this->genericKey}");
        }

        return trim((string)$entry['key']);
    }

    // ------------------------------------------------------------------
    // catálogo
    // ------------------------------------------------------------------

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
