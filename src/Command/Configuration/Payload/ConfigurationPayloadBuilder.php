<?php

namespace Hub\Command\Configuration\Payload;

abstract class ConfigurationPayloadBuilder
{
    protected static function arrayField(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an object or array");
        }

        return $value;
    }

    protected static function stringList(mixed $value, int $max, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an array");
        }
        if (count($value) > $max) {
            throw new \InvalidArgumentException("{$field} must contain at most {$max} values");
        }

        return array_pad(array_map(static function (mixed $item) use ($field): string {
            if (is_array($item)) {
                throw new \InvalidArgumentException("{$field} items must be strings");
            }

            return trim((string)$item);
        }, array_slice($value, 0, $max)), $max, '');
    }

    protected static function requiredString(mixed $value, string $field): string
    {
        if (is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be a string");
        }
        $value = trim((string)$value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$field} is required");
        }

        return $value;
    }

    protected static function boolInt(mixed $value, string $field): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (int)$value;
        }

        throw new \InvalidArgumentException("{$field} must be boolean or 0/1");
    }

    protected static function nonNegativeInt(mixed $value, string $field): int
    {
        if (!is_numeric((string)$value) || (int)$value < 0) {
            throw new \InvalidArgumentException("{$field} must be a non-negative integer");
        }

        return (int)$value;
    }

    protected static function nonNegativeFloat(mixed $value, string $field): int|float
    {
        if (!is_numeric((string)$value) || (float)$value < 0) {
            throw new \InvalidArgumentException("{$field} must be a non-negative number");
        }

        return str_contains((string)$value, '.') ? (float)$value : (int)$value;
    }

    protected static function positiveInt(mixed $value, string $field): int
    {
        if (!is_numeric((string)$value) || (int)$value <= 0) {
            throw new \InvalidArgumentException("{$field} must be a positive integer");
        }

        return (int)$value;
    }

    protected static function rangeInt(mixed $value, int $min, int $max, string $field): int
    {
        $value = self::positiveInt($value, $field);
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("{$field} must be between {$min} and {$max}");
        }

        return $value;
    }

    protected static function zeroBasedRangeInt(mixed $value, int $min, int $max, string $field): int
    {
        if (!is_numeric((string)$value)) {
            throw new \InvalidArgumentException("{$field} must be an integer");
        }

        $value = (int)$value;
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("{$field} must be between {$min} and {$max}");
        }

        return $value;
    }

    protected static function utf16Hex(string $value): string
    {
        $encoded = iconv('UTF-8', 'UTF-16BE//IGNORE', $value);

        return strtoupper(bin2hex($encoded !== false ? $encoded : $value));
    }
}
