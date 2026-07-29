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
 * The hub translates that contract to the 4P Touch wire command(s).
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
            'wonlex-json' => [
                'familyNumber' => ['contacts' => $this->wonlexContacts(
                    $contacts,
                    is_array($value) ? ($value['sosNumbers'] ?? []) : []
                )],
            ],
            'four-p-touch' => [
                'phonebook' => ['contacts' => self::requireListValue($contacts, 'contacts')],
            ],
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol} for phonebook"),
        };
    }

    public function fromNative(string $nativeKey, array $desired): mixed
    {
        if ($nativeKey === 'familyNumber') {
            $contacts = $desired['contacts'] ?? $desired['familyNumbers'] ?? $desired;
            if (!is_array($contacts) || !array_is_list($contacts)) {
                return [];
            }

            return array_values(array_filter(array_map(
                static fn(mixed $contact): ?array => is_array($contact)
                    ? WonlexContactCodec::publicContact($contact)
                    : null,
                $contacts
            )));
        }

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
        return [];
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        $meta = $accumulatedMeta;
        $meta['limit'] = max((int)($meta['limit'] ?? 0), $protocol === 'wonlex-json' ? 10 : 5);
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
        ];
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wonlexContacts(mixed $contacts, mixed $sosNumbers): array
    {
        $contacts = self::requireListValue($contacts, 'contacts');
        $selected = array_fill_keys(
            is_array($sosNumbers) ? array_map(
                static fn(mixed $phone): string => WonlexContactCodec::normalizePhone((string)$phone),
                $sosNumbers
            ) : [],
            true
        );
        $result = [];
        $seen = [];
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                throw new \InvalidArgumentException('contacts items must be objects');
            }
            $phone = WonlexContactCodec::publicPhone($contact);
            if ($phone === '') {
                continue;
            }
            if (isset($seen[$phone])) {
                throw new \InvalidArgumentException('contacts must not contain repeated phone values');
            }
            $seen[$phone] = true;
            $result[] = WonlexContactCodec::familyContact(
                $contact,
                isset($selected[WonlexContactCodec::normalizePhone($phone)])
            );
        }

        if (count($result) > 10) {
            throw new \InvalidArgumentException('contacts must contain at most 10 values');
        }

        return $result;
    }
}
