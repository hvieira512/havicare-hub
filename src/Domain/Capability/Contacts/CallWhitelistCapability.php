<?php

namespace Hub\Domain\Capability\Contacts;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Generic call whitelist capability.
 *
 * Public API shape:
 * - GET /api/devices/{imei}: Vivistar returns contacts as [{name, phone}], 4P Touch returns a list of phone numbers.
 * - PATCH /api/devices/{imei}/configurations:
 *   - Vivistar accepts {contacts:[{name, phone}]}
 *   - 4P Touch accepts a flat list of phone numbers
 *
 * The enable/disable switch is exposed separately as whitelist_enabled.
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
        return ['vivistar-iw', 'wonlex-json', 'four-p-touch'];
    }

    public function toNative(string $protocol, mixed $value): array
    {
        return match ($protocol) {
            'vivistar-iw' => ['call_whitelist' => self::encodeVivistarContacts($value)],
            'wonlex-json' => ['familyNumber' => ['contacts' => self::normalizeWonlexContactsList(
                is_array($value) ? ($value['contacts'] ?? $value) : []
            )]],
            'four-p-touch' => $this->fourPTouch->toNative($value),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol} for call_whitelist"),
        };
    }

    public function fromNative(string $nativeKey, array $desired): mixed
    {
        if ($nativeKey === 'call_whitelist') {
            if (isset($desired['fields']) && is_array($desired['fields'])) {
                $contacts = [];
                foreach ($desired['fields'] as $field) {
                    $field = trim((string)$field);
                    if ($field === '') {
                        continue;
                    }
                    $separator = strpos($field, '|');
                    $name = $separator !== false ? substr($field, 0, $separator) : '';
                    $phone = $separator !== false ? substr($field, $separator + 1) : $field;
                    $name = trim((string)$name);
                    $phone = trim((string)$phone);
                    if ($phone !== '') {
                        $contacts[] = [
                            'name' => $name,
                            'phone' => $phone,
                        ];
                    }
                }

                return self::normalizeContactsList($contacts);
            }

            if (isset($desired['contacts']) && is_array($desired['contacts'])) {
                return self::normalizeContactsList($desired['contacts']);
            }

            if (isset($desired['numbers']) && is_array($desired['numbers'])) {
                return self::normalizeContactsList(array_map(
                    static fn(mixed $phone): array => ['name' => '', 'phone' => $phone],
                    $desired['numbers']
                ));
            }

            if (array_is_list($desired)) {
                return self::normalizeContactsList($desired);
            }
        }

        if ($nativeKey === 'familyNumber') {
            return self::normalizeWonlexContactsList($desired['contacts'] ?? $desired['familyNumbers'] ?? $desired);
        }

        if ($nativeKey === 'whitelistGroup1' || $nativeKey === 'whitelistGroup2') {
            return $this->fourPTouch->fromNative($desired);
        }

        return [];
    }

    public function defaultValue(string $protocol): mixed
    {
        return match ($protocol) {
            'vivistar-iw' => [['name' => '', 'phone' => '']],
            'wonlex-json' => [['name' => '', 'phone' => '', 'areaCode' => '', 'sosSwitch' => false]],
            'four-p-touch' => $this->fourPTouch->defaultValue(),
            default => [],
        };
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        if ($protocol === 'four-p-touch') {
            return $this->fourPTouch->meta($accumulatedMeta);
        }

        return array_replace_recursive(
            ['limit' => 10, 'name' => ['maxLength' => 10], 'phone' => ['maxLength' => 20, 'asciiOnly' => true]],
            $accumulatedMeta,
        );
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

        return [
            'value' => $protocol === 'wonlex-json'
                ? self::normalizeWonlexContactsList($value)
                : self::normalizeContactsList($value),
            '_meta' => $this->meta($protocol, $meta),
        ];
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }

    /**
     * @return list<array{name: string, phone: string}>
     */
    private static function normalizeContactsList(mixed $value): array
    {
        $items = [];
        if (is_array($value)) {
            if (isset($value['contacts']) && is_array($value['contacts'])) {
                $items = $value['contacts'];
            } elseif (isset($value['numbers']) && is_array($value['numbers'])) {
                $items = $value['numbers'];
            } elseif (array_is_list($value)) {
                $items = $value;
            }
        }

        $contacts = [];
        $seenPhones = [];
        foreach ($items as $item) {
            $contact = self::normalizeContactItem($item);
            if ($contact === null) {
                continue;
            }
            if (in_array($contact['phone'], $seenPhones, true)) {
                throw new \InvalidArgumentException('contacts must not contain repeated phone values');
            }
            $seenPhones[] = $contact['phone'];
            $contacts[] = $contact;
        }

        return $contacts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function normalizeWonlexContactsList(mixed $value): array
    {
        $items = is_array($value) && isset($value['contacts']) && is_array($value['contacts'])
            ? $value['contacts']
            : $value;
        if (!is_array($items) || !array_is_list($items)) {
            return [];
        }

        $contacts = [];
        $seenPhones = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $item = ['phone' => $item];
            }
            $phone = trim((string)($item['phone'] ?? ''));
            if ($phone === '') {
                $hasValue = false;
                foreach ($item as $field) {
                    if (is_scalar($field) && trim((string)$field) !== '') {
                        $hasValue = true;
                        break;
                    }
                }
                if (!$hasValue) {
                    continue;
                }
                throw new \InvalidArgumentException('phone is required');
            }
            if (in_array($phone, $seenPhones, true)) {
                throw new \InvalidArgumentException('contacts must not contain repeated phone values');
            }
            $seenPhones[] = $phone;
            $contacts[] = array_filter([
                'familyNumberId' => trim((string)($item['familyNumberId'] ?? '')),
                'name' => trim((string)($item['name'] ?? '')),
                'phone' => $phone,
                'sosSwitch' => isset($item['sosSwitch']) ? (bool)$item['sosSwitch'] : false,
                'areaCode' => trim((string)($item['areaCode'] ?? '')),
            ], static fn(mixed $field, string $key): bool => $key === 'sosSwitch' || $field !== '', ARRAY_FILTER_USE_BOTH);
        }

        return $contacts;
    }

    /**
     * @return array{name: string, phone: string}|null
     */
    private static function normalizeContactItem(mixed $item): ?array
    {
        if (is_array($item)) {
            $name = trim((string)($item['name'] ?? ''));
            $phone = trim((string)($item['phone'] ?? ''));
            if ($name === '' && $phone === '') {
                return null;
            }
            if ($phone === '') {
                throw new \InvalidArgumentException('phone is required');
            }

            return ['name' => $name, 'phone' => $phone];
        }

        $field = trim((string)$item);
        if ($field === '') {
            return null;
        }

        $separator = strpos($field, '|');
        $name = $separator !== false ? trim((string)substr($field, 0, $separator)) : '';
        $phone = $separator !== false ? trim((string)substr($field, $separator + 1)) : $field;
        if ($phone === '') {
            return null;
        }

        return ['name' => $name, 'phone' => $phone];
    }

    /**
     * @return list<string>
     */
    private static function encodeVivistarContacts(mixed $value): array
    {
        $contacts = self::normalizeContactsList($value);
        $fields = [];
        foreach (array_slice($contacts, 0, 10) as $contact) {
            $name = trim((string)($contact['name'] ?? ''));
            $phone = trim((string)($contact['phone'] ?? ''));
            if ($phone === '') {
                continue;
            }
            $fields[] = $name !== '' ? "{$name}|{$phone}" : "|{$phone}";
        }

        return array_pad($fields, 10, '');
    }

}
