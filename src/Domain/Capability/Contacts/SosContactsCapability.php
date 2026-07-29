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
            'wonlex-json' => $this->wonlexNative($value),
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
        if ($nativeKey === 'SOSNumber' && isset($desired['sosNumbers']) && is_array($desired['sosNumbers'])) {
            return self::stringList(array_map(
                static fn(mixed $item): string => is_array($item)
                    ? WonlexContactCodec::publicPhone($item)
                    : trim((string)$item),
                $desired['sosNumbers']
            ));
        }
        if ($nativeKey === 'SOSNumber' && isset($desired['contacts']) && is_array($desired['contacts'])) {
            return self::stringList(array_map(
                static fn(mixed $item): string => is_array($item)
                    ? WonlexContactCodec::publicPhone($item)
                    : trim((string)$item),
                $desired['contacts']
            ));
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
            default => [],
        };
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        if ($protocol === 'four-p-touch') {
            return $this->fourPTouch->meta($accumulatedMeta);
        }
        if ($protocol === 'wonlex-json') {
            return array_replace_recursive([
                'limit' => 10,
                'sourceCapability' => 'phonebook',
                'selectionMode' => 'subset',
            ], $accumulatedMeta);
        }

        return $accumulatedMeta;
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
            'value' => is_array($value) ? self::stringList($value) : [],
            '_meta' => $this->meta($protocol, $meta),
        ];
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function wonlexNative(mixed $value): array
    {
        $phonebook = is_array($value) ? ($value['phonebookContacts'] ?? null) : null;
        $selected = is_array($value)
            ? ($value['selectedNumbers'] ?? $value['numbers'] ?? null)
            : $value;
        $numbers = self::requireUniqueStringListValue($selected, 'numbers');
        if (!is_array($phonebook) || !array_is_list($phonebook)) {
            throw new \InvalidArgumentException('Wonlex SOS contacts must be selected from the phonebook');
        }

        $selectedSet = array_fill_keys(array_map(
            static fn(string $phone): string => WonlexContactCodec::normalizePhone($phone),
            $numbers
        ), true);
        $familyContacts = [];
        $sosContacts = [];
        foreach ($phonebook as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $phone = WonlexContactCodec::publicPhone($contact);
            if ($phone === '') {
                continue;
            }
            $isSelected = isset($selectedSet[WonlexContactCodec::normalizePhone($phone)]);
            $family = WonlexContactCodec::familyContact($contact, $isSelected);
            $familyContacts[] = $family;
            if ($isSelected) {
                $sosContacts[] = WonlexContactCodec::sosContact($family);
                unset($selectedSet[WonlexContactCodec::normalizePhone($phone)]);
            }
        }

        if ($selectedSet !== []) {
            throw new \InvalidArgumentException('Wonlex SOS contacts must exist in the phonebook');
        }

        return [
            'familyNumber' => ['contacts' => $familyContacts],
            'SOSNumber' => ['contacts' => $sosContacts],
        ];
    }
}
