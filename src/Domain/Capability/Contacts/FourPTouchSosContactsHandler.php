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
    use FourPTouchContactSupport;

    public function nativeKey(): string
    {
        return 'sosNumber1';
    }

    public function toNative(mixed $value): array
    {
        $numbers = is_array($value) && array_key_exists('numbers', $value)
            ? $value['numbers']
            : $value;
        return $this->split(self::requireUniqueStringListValue($numbers, 'numbers'));
    }

    public function fromNative(array $desired): mixed
    {
        $numbers = self::normalizeNumbersValue($desired);
        if ($numbers !== []) {
            return $numbers;
        }

        return [];
    }

    public function defaultValue(): mixed
    {
        return ['', '', ''];
    }

    public function meta(array $accumulatedMeta = []): array
    {
        return self::mergeFourPTouchMeta($accumulatedMeta, 3, [
            'phone' => ['maxLength' => 20, 'asciiOnly' => true],
        ]);
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
            return $this->emptySplit();
        }

        $updates = [];
        foreach (array_slice($numbers, 0, 3) as $index => $phone) {
            if (trim($phone) !== '') {
                $updates['sosNumber' . ($index + 1)] = ['phone' => $phone];
            }
        }

        return $updates;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function emptySplit(): array
    {
        return [
            'sosNumber1' => ['phone' => ''],
            'sosNumber2' => ['phone' => ''],
            'sosNumber3' => ['phone' => ''],
        ];
    }
}
