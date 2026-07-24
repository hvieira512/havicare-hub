<?php

namespace Hub\Domain\Capability\Contacts;

use Hub\Domain\Capability\CapabilityHelpers;
use Hub\Domain\Capability\CapabilityProtocolHandler;

/**
 * 4P Touch strategy for call whitelist.
 */
final class FourPTouchCallWhitelistHandler implements CapabilityProtocolHandler
{
    use CapabilityHelpers;
    use FourPTouchContactSupport;

    public function nativeKey(): string
    {
        return 'whitelistGroup1';
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
        return self::normalizeNumbersValue($desired);
    }

    public function defaultValue(): mixed
    {
        return [];
    }

    public function meta(array $accumulatedMeta = []): array
    {
        return self::mergeFourPTouchMeta($accumulatedMeta, 10);
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return self::mergeAssociativeValues($existing, $incoming, ['numbers']);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        $normalizedValue = $this->normalizeValueForResponse($value);

        return [
            'value' => $normalizedValue,
            '_meta' => $this->meta($meta),
            '_type' => 'call_whitelist',
        ];
    }

    /**
     * @param list<string> $numbers
     * @return array<string, array<string, mixed>>
     */
    private function split(array $numbers): array
    {
        return [
            'whitelistGroup1' => ['numbers' => array_slice($numbers, 0, 5)],
            'whitelistGroup2' => ['numbers' => array_slice($numbers, 5, 5)],
        ];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeValueForResponse(mixed $value): array
    {
        if (is_array($value) && array_key_exists('numbers', $value)) {
            return self::stringList(is_array($value['numbers']) ? $value['numbers'] : []);
        }

        if (is_array($value) && array_key_exists('contacts', $value) && is_array($value['contacts'])) {
            return self::stringList(array_map(
                static fn(mixed $contact): string => is_array($contact)
                    ? trim((string)($contact['phone'] ?? ''))
                    : trim((string)$contact),
                $value['contacts']
            ));
        }

        if (is_array($value) && array_is_list($value)) {
            if ($value !== [] && is_array($value[0] ?? null)) {
                return self::stringList(array_map(
                    static fn(mixed $contact): string => trim((string)($contact['phone'] ?? '')),
                    $value
                ));
            }

            return self::stringList($value);
        }

        if (is_array($value) && array_key_exists('phone', $value)) {
            return self::stringList([(string)$value['phone']]);
        }

        return [];
    }
}
