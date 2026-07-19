<?php

namespace Hub\Domain\Capability\Contacts;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Capability for phonebook contacts.
 *
 * All protocols store contacts as a list, but with different shapes:
 * - vivistar-iw: { phonebook: { contacts: [...] } }
 * - wonlex-json: { phonebook: { contacts: [...] } }
 * - four-p-touch: { phonebook: { contacts: [...] } }
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

    public function supportedProtocols(): array
    {
        return ['vivistar-iw', 'wonlex-json', 'four-p-touch'];
    }

    public function toNative(string $protocol, mixed $value): array
    {
        return match ($protocol) {
            'vivistar-iw', 'wonlex-json', 'four-p-touch' => [
                'phonebook' => ['contacts' => self::requireListValue($value, 'contacts')],
            ],
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol} for phonebook"),
        };
    }

    public function fromNative(string $nativeKey, array $desired): mixed
    {
        return $desired['contacts'] ?? null;
    }

    public function defaultValue(string $protocol): mixed
    {
        return [['name' => '', 'phone' => '']];
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        return $accumulatedMeta;
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return self::mergeListValues($existing, $incoming);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return [
            'value' => $value,
            '_meta' => $meta,
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
