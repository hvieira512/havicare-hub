<?php

namespace Hub\Domain\Capability\Contacts;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Generic call whitelist capability.
 *
 * Public API shape:
 * - GET /api/devices/{imei}: value is a list of phone numbers, with optional _meta.limit
 * - PATCH /api/devices/{imei}/configurations: send { numbers: [...] }
 *
 * The hub translates that generic contract to each protocol's native command(s).
 */
final class CallWhitelistCapability implements CapabilityContract
{
    use CapabilityHelpers;

    private FourPTouchCallWhitelistHandler $fourPTouch;

    public function __construct(?FourPTouchCallWhitelistHandler $fourPTouch = null)
    {
        $this->fourPTouch = $fourPTouch ?? new FourPTouchCallWhitelistHandler();
    }

    public function key(): string
    {
        return 'call_whitelist';
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
        return true;
    }

    public function supportedProtocols(): array
    {
        return ['vivistar-iw', 'four-p-touch'];
    }

    public function toNative(string $protocol, mixed $value): array
    {
        return match ($protocol) {
            'vivistar-iw' => ['whitelistSwitch' => ['enabled' => self::requireBoolLikeField($value, 'enabled')]],
            'four-p-touch' => $this->fourPTouch->toNative($value),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol} for call_whitelist"),
        };
    }

    public function fromNative(string $nativeKey, array $desired): mixed
    {
        if ($nativeKey === 'whitelistSwitch') {
            return ['enabled' => (bool)($desired['enabled'] ?? false)];
        }

        if ($nativeKey === 'whitelistGroup1' || $nativeKey === 'whitelistGroup2') {
            return $this->fourPTouch->fromNative($desired);
        }

        return ['numbers' => self::stringList($desired['numbers'] ?? [])];
    }

    public function defaultValue(string $protocol): mixed
    {
        return match ($protocol) {
            'vivistar-iw' => ['enabled' => true],
            'four-p-touch' => $this->fourPTouch->defaultValue(),
            default => ['enabled' => true],
        };
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        return $protocol === 'four-p-touch'
            ? $this->fourPTouch->meta($accumulatedMeta)
            : $accumulatedMeta;
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return is_array($existing) && is_array($incoming) && array_key_exists('numbers', $incoming)
            ? $this->fourPTouch->merge($existing, $incoming)
            : self::mergeAssociativeValues($existing, $incoming, ['numbers']);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        if ($protocol === 'four-p-touch') {
            return $this->fourPTouch->responseEntry($protocol, $nativeKey, $value, $meta);
        }

        return ['value' => $value, '_meta' => $meta, '_type' => $this->key()];
    }

    public function nativeKeyForProtocol(string $protocol): ?string
    {
        return match ($protocol) {
            'vivistar-iw' => 'whitelistSwitch',
            'four-p-touch' => null, // splits into whitelistGroup1/2
            default => null,
        };
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }

}
