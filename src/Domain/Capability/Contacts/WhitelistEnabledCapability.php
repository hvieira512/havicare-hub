<?php

namespace Hub\Domain\Capability\Contacts;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Generic whitelist enable/disable capability.
 *
 * Public API shape:
 * - GET /api/devices/{imei}: value is an object with enabled => bool
 * - PATCH /api/devices/{imei}/configurations: send { enabled: bool }
 *
 * This is the transport-side switch that controls the whitelist list.
 */
final class WhitelistEnabledCapability implements CapabilityContract
{
    use CapabilityHelpers;

    public function key(): string
    {
        return 'whitelist_enabled';
    }

    public function section(): string
    {
        return 'contacts';
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
        return ['vivistar-iw', 'four-p-touch'];
    }

    public function toNative(string $protocol, mixed $value): array
    {
        return match ($protocol) {
            'vivistar-iw' => [
                'whitelist_enabled' => [
                    'enabled' => self::requireBoolLikeField($value, 'enabled'),
                ],
            ],
            'four-p-touch' => [
                'whitelistSwitch' => [
                    'enabled' => self::requireBoolLikeField($value, 'enabled'),
                ],
            ],
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol} for whitelist_enabled"),
        };
    }

    public function fromNative(string $nativeKey, array $desired): mixed
    {
        return ['enabled' => (bool)($desired['enabled'] ?? false)];
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

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return ['value' => $value, '_meta' => $meta];
    }

    public function nativeKeyForProtocol(string $protocol): ?string
    {
        return match ($protocol) {
            'vivistar-iw' => 'BP84',
            'four-p-touch' => 'whitelistSwitch',
            default => null,
        };
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }
}
