<?php

namespace Hub\Domain\Capability\Alarms;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Generic on/off switch for sending an SMS when the watch raises an SOS alarm.
 *
 * Public API shape: {"enabled": boolean}.
 */
final class SosSmsAlertCapability implements CapabilityContract
{
    use CapabilityHelpers;

    public function key(): string
    {
        return 'sos_sms_alert';
    }

    public function section(): string
    {
        return 'alarms';
    }

    public function isList(): bool
    {
        return false;
    }

    public function supportsMultipleNativeKeys(): bool
    {
        return false;
    }

    public function supportedProtocols(): array
    {
        return ['wonlex-json', 'four-p-touch'];
    }

    public function toNative(string $protocol, mixed $value): array
    {
        $enabled = self::requireBoolLikeField($value, 'enabled');

        return match ($protocol) {
            'wonlex-json' => ['wonlexSOSSwitch' => ['switchState' => $enabled]],
            'four-p-touch' => ['sosSmsAlerts' => ['enabled' => $enabled]],
            default => throw new \InvalidArgumentException(
                "Unsupported protocol {$protocol} for sos_sms_alert"
            ),
        };
    }

    public function fromNative(string $nativeKey, array $desired): mixed
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

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }
}
