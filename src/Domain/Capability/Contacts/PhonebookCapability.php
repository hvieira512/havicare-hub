<?php

namespace Hub\Domain\Capability\Contacts;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;
use Hub\Domain\Capability\CapabilityInputSanitizer;

/**
 * A lista telefónica de um 4P Touch.
 *
 * Forma pública:
 * - GET /api/devices/{imei}: o valor é uma lista de contactos, com `_meta.limit` opcional
 * - PATCH /api/devices/{imei}/configurations: envia-se `{ contacts: [...] }`. Uma lista vazia
 *   é válida e limpa a lista guardada.
 *
 * O hub traduz esse contrato nos comandos de fio do 4P Touch.
 */
final class PhonebookCapability implements CapabilityContract, CapabilityInputSanitizer
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
        $value = $this->sanitizeInput($protocol, $value);
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

    public function sanitizeInput(string $protocol, mixed $value): mixed
    {
        $maxLength = (int)($this->meta($protocol)['name']['maxLength'] ?? 0);
        if ($maxLength <= 0 || !is_array($value)) {
            return $value;
        }

        $wrapped = array_key_exists('contacts', $value);
        $contacts = $wrapped ? $value['contacts'] : $value;
        if (!is_array($contacts) || !array_is_list($contacts)) {
            return $value;
        }

        foreach ($contacts as $index => $contact) {
            if (!is_array($contact) || !array_key_exists('name', $contact)) {
                continue;
            }
            $contact['name'] = self::truncateName(trim((string)$contact['name']), $maxLength);
            $contacts[$index] = $contact;
        }

        if ($wrapped) {
            $value['contacts'] = $contacts;
            return $value;
        }

        return $contacts;
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
            ['maxLength' => $protocol === 'wonlex-json' ? WonlexContactCodec::NAME_MAX_LENGTH : 10],
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

    private static function truncateName(string $name, int $maxLength): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($name, 0, $maxLength, 'UTF-8');
        }

        $characters = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return substr($name, 0, $maxLength);
        }

        return implode('', array_slice($characters, 0, $maxLength));
    }
}
