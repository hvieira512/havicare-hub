<?php

namespace Hub\Domain\Capability\Contacts;

use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Ajudantes partilhados pelas capacidades de contactos do 4P Touch.
 */
trait FourPTouchContactSupport
{
    use CapabilityHelpers;

    /**
     * @param array<string, mixed> $accumulatedMeta
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    protected static function mergeFourPTouchMeta(array $accumulatedMeta, int $limit, array $defaults = []): array
    {
        return array_replace_recursive(
            $accumulatedMeta,
            array_merge(['limit' => $limit], $defaults),
        );
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    protected static function normalizeNumbersValue(mixed $value): array
    {
        if (is_array($value) && array_key_exists('numbers', $value)) {
            return self::stringList(is_array($value['numbers']) ? $value['numbers'] : []);
        }

        if (is_array($value) && array_key_exists('phone', $value)) {
            return self::stringList([$value['phone']]);
        }

        return [];
    }
}
