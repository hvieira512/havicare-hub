<?php

namespace Hub\Domain\Capability\Contacts;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Generic SOS contacts capability.
 *
 * Public API shape:
 * - GET /api/devices/{imei}: value is a list of phone numbers, with optional _meta.limit
 * - PATCH /api/devices/{imei}/configurations: send a flat list of phone numbers.
 *   An empty array is valid and clears all saved SOS contacts.
 *
 * The hub translates that generic contract to each protocol's wire command(s).
 */
final class SosContactsCapability implements CapabilityContract
{
    use CapabilityHelpers;

    private FourPTouchSosContactsHandler $fourPTouch;

    public function __construct(?FourPTouchSosContactsHandler $fourPTouch = null)
    {
        $this->fourPTouch = $fourPTouch ?? new FourPTouchSosContactsHandler();
    }

    public function key(): string
    {
        return 'sos_contacts';
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
        return ['vivistar-iw', 'wonlex-json', 'four-p-touch'];
    }

    public function toNative(string $protocol, mixed $value): array
    {
        $numbers = is_array($value) && array_key_exists('numbers', $value)
            ? $value['numbers']
            : $value;
        return match ($protocol) {
            'vivistar-iw' => ['sosContacts' => ['numbers' => self::requireUniqueStringListValue($numbers, 'numbers')]],
            'wonlex-json' => ['SOSNumber' => ['numbers' => self::requireUniqueStringListValue($numbers, 'numbers')]],
            'four-p-touch' => $this->fourPTouch->toNative($value),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol} for sos_contacts"),
        };
    }

    public function fromNative(string $nativeKey, array $desired): mixed
    {
        if (isset($desired['numbers']) && is_array($desired['numbers'])) {
            return self::stringList($desired['numbers']);
        }
        if (isset($desired['phone'])) {
            return self::stringList([$desired['phone']]);
        }
        if ($nativeKey === 'SOSNumber' && isset($desired['SOSNumber']) && is_array($desired['SOSNumber'])) {
            return self::stringList($desired['SOSNumber']);
        }
        if ($nativeKey === 'sosNumber1' || $nativeKey === 'sosNumber2' || $nativeKey === 'sosNumber3') {
            return $this->fourPTouch->fromNative($desired);
        }

        return [];
    }

    public function defaultValue(string $protocol): mixed
    {
        return match ($protocol) {
            'four-p-touch' => $this->fourPTouch->defaultValue(),
            default => ['', '', ''],
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
        return self::mergeListValues($existing, $incoming);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        if ($protocol === 'four-p-touch') {
            return $this->fourPTouch->responseEntry($protocol, $nativeKey, $value, $meta);
        }

        return ['value' => is_array($value) ? self::stringList($value) : [], '_meta' => $meta];
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }

}
