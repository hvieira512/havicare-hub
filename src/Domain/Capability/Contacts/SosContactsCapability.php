<?php

namespace Hub\Domain\Capability\Contacts;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Capability for SOS contacts.
 *
 * Maps to different native shapes per protocol:
 * - vivistar-iw: { sosContacts: { numbers: [...] } }
 * - wonlex-json: { SOSNumber: { numbers: [...] } }
 * - four-p-touch: { sosNumber1: { phone: ... }, sosNumber2: ..., sosNumber3: ... }
 */
final class SosContactsCapability implements CapabilityContract
{
    use CapabilityHelpers;

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

    public function supportedProtocols(): array
    {
        return ['vivistar-iw', 'wonlex-json', 'four-p-touch'];
    }

    public function toNative(string $protocol, mixed $value): array
    {
        $numbers = is_array($value) ? ($value['numbers'] ?? []) : [];
        return match ($protocol) {
            'vivistar-iw' => ['sosContacts' => ['numbers' => self::requireUniqueStringListValue($numbers, 'numbers')]],
            'wonlex-json' => ['SOSNumber' => ['numbers' => self::requireUniqueStringListValue($numbers, 'numbers')]],
            'four-p-touch' => $this->fourPTouchSplit(self::requireUniqueStringListValue($numbers, 'numbers')),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol} for sos_contacts"),
        };
    }

    public function fromNative(string $nativeKey, array $desired): mixed
    {
        if (isset($desired['numbers']) && is_array($desired['numbers'])) {
            return ['numbers' => self::stringList($desired['numbers'])];
        }
        if (isset($desired['phone'])) {
            return ['numbers' => self::stringList([$desired['phone']])];
        }
        if ($nativeKey === 'SOSNumber' && isset($desired['SOSNumber']) && is_array($desired['SOSNumber'])) {
            return ['numbers' => self::stringList($desired['SOSNumber'])];
        }

        return ['numbers' => []];
    }

    public function defaultValue(string $protocol): mixed
    {
        return match ($protocol) {
            'four-p-touch' => ['numbers' => ['', '', '']],
            default => ['numbers' => ['', '', '']],
        };
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        return $accumulatedMeta;
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return self::mergeAssociativeValues($existing, $incoming, ['numbers']);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        $normalizedValue = $value;
        if (is_array($value) && array_key_exists('numbers', $value)) {
            $normalizedValue = self::stringList(is_array($value['numbers']) ? $value['numbers'] : []);
        }

        return [
            'value' => $normalizedValue,
            '_meta' => $meta,
            '_type' => $this->key(),
        ];
    }

    public function nativeKeyForProtocol(string $protocol): ?string
    {
        return match ($protocol) {
            'vivistar-iw' => 'sosContacts',
            'wonlex-json' => 'SOSNumber',
            'four-p-touch' => null, // splits into sosNumber1/2/3
            default => null,
        };
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }

    // ------------------------------------------------------------------
    // 4P Touch splitting
    // ------------------------------------------------------------------

    /** @param list<string> $numbers */
    private function fourPTouchSplit(array $numbers): array
    {
        $updates = [];
        foreach (array_slice($numbers, 0, 3) as $index => $phone) {
            if (trim($phone) !== '') {
                $updates['sosNumber' . ($index + 1)] = ['phone' => $phone];
            }
        }

        return $updates;
    }
}
