<?php

namespace Hub\Domain\Capability\Contacts;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * 4P Touch phonebook capability.
 *
 * Public API shape:
 * - GET /api/devices/{imei}: value is a list of contacts, with optional _meta.limit
 * - PATCH /api/devices/{imei}/configurations: send { contacts: [...] }
 *   An empty array is valid and clears the saved phonebook.
 *
 * The hub translates that contract to the 4P Touch native command(s).
 */
final class PhonebookCapability implements CapabilityContract
{
    use CapabilityHelpers;

    public function key(): string
    {
        return 'phonebook';
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
        return ['wonlex-json', 'four-p-touch'];
    }

    public function toNative(string $protocol, mixed $value): array
    {
        $contacts = is_array($value) && array_key_exists('contacts', $value) ? $value['contacts'] : $value;

        return match ($protocol) {
            'wonlex-json', 'four-p-touch' => [
                'phonebook' => ['contacts' => self::requireListValue($contacts, 'contacts')],
            ],
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol} for phonebook"),
        };
    }

    public function fromNative(string $nativeKey, array $desired): mixed
    {
        if (array_key_exists('contacts', $desired) && is_array($desired['contacts'])) {
            return self::requireListValue($desired['contacts'], 'contacts');
        }

        if (array_is_list($desired)) {
            return self::requireListValue($desired, 'contacts');
        }

        return null;
    }

    public function defaultValue(string $protocol): mixed
    {
        return [['name' => '', 'phone' => '']];
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        if ($protocol !== 'four-p-touch') {
            return $accumulatedMeta;
        }

        $meta = $accumulatedMeta;
        $meta['limit'] = max((int)($meta['limit'] ?? 0), 5);
        $meta['name'] = array_merge(
            ['maxLength' => 10],
            is_array($meta['name'] ?? null) ? $meta['name'] : []
        );
        $meta['phone'] = array_merge(
            ['maxLength' => 20, 'asciiOnly' => true],
            is_array($meta['phone'] ?? null) ? $meta['phone'] : []
        );

        return $meta;
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return self::mergeListValues($existing, $incoming);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return [
            'value' => $value,
            '_meta' => $this->meta($protocol, $meta),
            '_type' => $this->key(),
        ];
    }

    public function nativeKeyForProtocol(string $protocol): ?string
    {
        return 'phonebook';
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }
}
