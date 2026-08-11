<?php

namespace Hub\Domain\Capability;

/**
 * Shared validation and normalization helpers for capability implementations.
 */
trait CapabilityHelpers
{
    /** @return list<string> */
    public static function stringList(array $values): array
    {
        $normalized = [];
        $seen = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                throw new \InvalidArgumentException('list items must be strings');
            }
            $item = trim((string)$value);
            if ($item !== '') {
                if (!isset($seen[$item])) {
                    $seen[$item] = true;
                    $normalized[] = $item;
                }
            }
        }

        return $normalized;
    }

    /** @return list<string> */
    public static function requireStringListValue(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an array");
        }

        return self::stringList($value);
    }

    /**
     * @return list<string>
     */
    public static function requireUniqueStringListValue(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an array");
        }

        $normalized = [];
        $seen = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                throw new \InvalidArgumentException("{$field} items must be strings");
            }
            $string = trim((string)$item);
            if ($string === '') {
                continue;
            }
            if (isset($seen[$string])) {
                throw new \InvalidArgumentException("{$field} must not contain repeated values");
            }
            $seen[$string] = true;
            $normalized[] = $string;
        }

        return $normalized;
    }

    /** @return list<mixed> */
    public static function requireListValue(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("{$field} must be an array");
        }

        return array_values($value);
    }

    /** @return array<string, mixed> */
    public static function requireObjectValue(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an object");
        }

        return $value;
    }

    public static function requireStringValue(mixed $value, string $field): string
    {
        if (is_array($value)) {
            if (!array_key_exists($field, $value)) {
                throw new \InvalidArgumentException("{$field} is required");
            }
            $value = $value[$field];
        }

        $string = trim((string)$value);
        if ($string === '') {
            throw new \InvalidArgumentException("{$field} is required");
        }

        return $string;
    }

    public static function requireStringField(mixed $value, string $field): string
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} is required");
        }

        return self::requireStringValue($value[$field] ?? null, $field);
    }

    public static function requireIntField(mixed $value, string $field): int
    {
        if (!is_array($value) || !is_numeric((string)($value[$field] ?? null))) {
            throw new \InvalidArgumentException("{$field} must be an integer");
        }

        return (int)$value[$field];
    }

    public static function requireBoolLikeField(mixed $value, string $field): bool|int|string
    {
        if (!is_array($value) || !array_key_exists($field, $value)) {
            throw new \InvalidArgumentException("{$field} is required");
        }

        return $value[$field];
    }

    public static function requireBoolLikeValue(mixed $value, string $field): bool|int|string
    {
        if (is_array($value)) {
            return self::requireBoolLikeField($value, $field);
        }
        if (is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return $value;
        }

        throw new \InvalidArgumentException("{$field} must be boolean or 0/1");
    }

    // ------------------------------------------------------------------
    // Merge helpers
    // ------------------------------------------------------------------

    /** @param list<string> $listKeys */
    public static function mergeAssociativeValues(mixed $existing, mixed $incoming, array $listKeys = []): mixed
    {
        if (!is_array($existing) || !is_array($incoming)) {
            return $incoming;
        }

        $merged = $existing;
        foreach ($incoming as $key => $value) {
            if (in_array((string)$key, $listKeys, true)) {
                $merged[$key] = self::stringList(array_merge(
                    is_array($existing[$key] ?? null) ? $existing[$key] : [],
                    is_array($value) ? $value : [],
                ));
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    public static function mergeListValues(mixed $existing, mixed $incoming): array
    {
        $existingList = is_array($existing) ? array_values($existing) : [];
        $incomingList = is_array($incoming) ? array_values($incoming) : [];

        return array_values(array_merge($existingList, $incomingList));
    }

    public static function mergeStringLists(mixed $existing, mixed $incoming): array
    {
        return self::stringList(array_merge(
            is_array($existing) ? $existing : [],
            is_array($incoming) ? $incoming : [],
        ));
    }

    protected function stringifyPhoneList(array $value): mixed
    {
        if (array_key_exists('numbers', $value) && is_array($value['numbers'])) {
            return self::stringList($value['numbers']);
        }

        if (!array_is_list($value)) {
            return $value;
        }

        return self::stringList($value);
    }

    protected function normalizeComparableValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->normalizeComparableValue($item), $value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string)$key] = $this->normalizeComparableValue($item);
        }
        ksort($normalized);

        return $normalized;
    }
}
