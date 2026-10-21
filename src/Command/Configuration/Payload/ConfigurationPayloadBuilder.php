<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Payload;

abstract class ConfigurationPayloadBuilder
{
    /** @return list<string> */
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

    /** Uma hora `HH:MM` de 24 horas. */
    public static function hhmm(mixed $value, string $field): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw new \InvalidArgumentException("{$field} must use 24-hour HH:MM format");
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

    protected static function nonNegativeFloat(mixed $value, string $field): int|float
    {
        if (!is_numeric((string)$value) || (float)$value < 0) {
            throw new \InvalidArgumentException("{$field} must be a non-negative number");
        }

        return str_contains((string)$value, '.') ? (float)$value : (int)$value;
    }

    /** Um inteiro dentro dos limites, os mesmos que a definição publica em `bounds`. */
    protected static function intInRange(mixed $value, string $field, int $min = 0, int $max = PHP_INT_MAX): int
    {
        $int = is_scalar($value) && !is_bool($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if ($int === false || $int < $min || $int > $max) {
            throw new \InvalidArgumentException($max === PHP_INT_MAX
                ? "{$field} must be an integer from {$min}"
                : "{$field} must be an integer between {$min} and {$max}");
        }

        return $int;
    }

    /** O texto em UTF-16BE e hexadecimal maiúsculo, como o 4P Touch e a Vivistar o querem. */
    public static function utf16Hex(string $value): string
    {
        $encoded = iconv('UTF-8', 'UTF-16BE', $value);
        if ($encoded === false) {
            throw new \InvalidArgumentException('text must be valid UTF-8');
        }

        return strtoupper(bin2hex($encoded));
    }
}
