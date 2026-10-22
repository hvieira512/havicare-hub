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

    /**
     * A mediana, e num número par a de cima: é sempre um dos valores, e não uma média deles.
     *
     * @param non-empty-list<int> $values
     */
    public static function median(array $values): int
    {
        sort($values);

        return $values[intdiv(count($values), 2)];
    }
}
