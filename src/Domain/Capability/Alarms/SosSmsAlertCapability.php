<?php

namespace Hub\Domain\Capability\Alarms;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * O interruptor de enviar um SMS quando o relógio levanta um alarme de SOS.
 *
 * Forma pública: `{"enabled": boolean}`.
 */
final class SosSmsAlertCapability implements CapabilityContract
{
    use CapabilityHelpers;

    public function key(): string
    {
        return 'sos_sms_alert';
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
     * O nome de fio do interruptor em cada protocolo, e o campo que o transporta.
     *
     * Um protocolo novo é uma linha. O `supportedProtocols` sai daqui, para não poder anunciar
     * o que o despacho recusa.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const NATIVE = [
        'wonlex-json' => ['wonlexSOSSwitch', 'switchState'],
        'four-p-touch' => ['sosSmsAlerts', 'enabled'],
    ];

    public function supportedProtocols(): array
    {
        return array_keys(self::NATIVE);
    }

    public function toNative(string $protocol, mixed $value): array
    {
        // O protocolo decide-se antes do valor: um protocolo que não se serve é recusado por
        // isso, e não por um campo em falta que manda quem depura ao sítio errado.
        [$nativeKey, $field] = self::NATIVE[$protocol]
            ?? throw new \InvalidArgumentException("Unsupported protocol {$protocol} for sos_sms_alert");

        return [$nativeKey => [$field => self::requireBoolLikeField($value, 'enabled')]];
    }

    public function fromNative(string $protocol, string $nativeKey, array $desired): mixed
    {
        return ['enabled' => (bool)($desired['enabled'] ?? $desired['switchState'] ?? false)];
    }

    public function defaultValue(string $protocol): mixed
    {
        return ['enabled' => true];
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        return $accumulatedMeta;
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return self::mergeAssociativeValues($existing, $incoming);
    }

    public function responseEntry(
        string $protocol,
        string $nativeKey,
        mixed $value,
        array $meta
    ): array {
        return ['value' => $value, '_meta' => $meta];
    }
}
