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
        return $this->split(self::requireUniqueStringListValue(is_array($value) ? ($value['numbers'] ?? []) : [], 'numbers'));
    }

    public function fromNative(array $desired): mixed
    {
        return ['numbers' => self::normalizeNumbersValue($desired)];
    }

    public function defaultValue(): mixed
    {
        return ['numbers' => ['', '', '', '', '', '', '', '', '', '']];
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
        return [
            'value' => $value,
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
}
