<?php

declare(strict_types=1);

namespace Hub\Support;

final class Values
{
    /**
     * Os campos que têm valor. Um `array_filter` sem callback também deitava fora
     * o `0`, o `false` e a string vazia, que aqui são leituras legítimas.
     *
     * As chaves são preservadas, tal como no `array_filter`: numa lista, quem
     * precisar de índices seguidos envolve a chamada num `array_values`.
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
