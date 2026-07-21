<?php

namespace Hub\Domain\Capability\Contacts;

use Hub\Domain\Capability\CapabilityHelpers;
use Hub\Domain\Capability\CapabilityProtocolHandler;

/**
 * 4P Touch strategy for SOS contacts.
 */
final class FourPTouchSosContactsHandler implements CapabilityProtocolHandler
{
    use CapabilityHelpers;

    public function nativeKey(): string
    {
        return 'sosNumber1';
    }

    public function toNative(mixed $value): array
    {
        $numbers = is_array($value) ? ($value['numbers'] ?? []) : [];
        return $this->split(self::requireUniqueStringListValue($numbers, 'numbers'));
    }

    public function fromNative(array $desired): mixed
    {
        if (isset($desired['numbers']) && is_array($desired['numbers'])) {
            return ['numbers' => self::stringList($desired['numbers'])];
        }
        if (isset($desired['phone'])) {
            return ['numbers' => self::stringList([$desired['phone']])];
        }

        return ['numbers' => []];
    }

    public function defaultValue(): mixed
    {
        return ['numbers' => ['', '', '']];
    }

    public function meta(array $accumulatedMeta = []): array
    {
        $accumulatedMeta['limit'] = max((int)($accumulatedMeta['limit'] ?? 0), 3);
        $accumulatedMeta['phone'] = array_merge(
            ['maxLength' => 20, 'asciiOnly' => true],
            is_array($accumulatedMeta['phone'] ?? null) ? $accumulatedMeta['phone'] : []
        );

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
            '_meta' => $this->meta($meta),
            '_type' => 'sos_contacts',
        ];
    }

    /**
     * @param list<string> $numbers
     * @return array<string, array<string, mixed>>
     */
    private function split(array $numbers): array
    {
        if ($numbers === []) {
            return [
                'sosNumber1' => ['phone' => ''],
                'sosNumber2' => ['phone' => ''],
                'sosNumber3' => ['phone' => ''],
            ];
        }

        $updates = [];
        foreach (array_slice($numbers, 0, 3) as $index => $phone) {
            if (trim($phone) !== '') {
                $updates['sosNumber' . ($index + 1)] = ['phone' => $phone];
            }
        }

        return $updates;
    }
}
