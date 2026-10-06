<?php

declare(strict_types=1);

namespace Hub\Support;

final class Values
{
    /**
     * Os campos que têm valor: ao contrário do `array_filter` sem callback, o `0`, o `false` e a
     * string vazia ficam. As chaves preservam-se.
     *
     * @template TKey of array-key
     * @template TValue
     * @param array<TKey, TValue|null> $fields
     * @return array<TKey, TValue>
     */
    public static function withoutNulls(array $fields): array
    {
        return array_filter($fields, static fn (mixed $value): bool => $value !== null);
    }
}
