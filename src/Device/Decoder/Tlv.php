<?php

declare(strict_types=1);

namespace Hub\Device\Decoder;

/** Os leitores do corpo TFLV do dispensador, um por tipo que a especificação usa. */
final class Tlv
{
    /**
     * O valor de uma TAG, ou `null` sem valor a ler: uma TAG recusada volta com os zeros que lhe
     * mandámos e um estado diferente de `000` nos bits 5--7 do Flag.
     *
     * @param array<int, array{value?: string, state?: int}> $tlv
     */
    public static function value(array $tlv, int $tag): ?string
    {
        $entry = $tlv[$tag] ?? null;
        if (!is_array($entry) || (int)($entry['state'] ?? 0) !== 0) {
            return null;
        }

        $value = $entry['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<int, array{value?: string, state?: int}> $tlv */
    public static function u8(array $tlv, int $tag): ?int
    {
        $value = self::value($tlv, $tag);
        return $value === null ? null : ord($value[0]);
    }

    /** @param array<int, array{value?: string, state?: int}> $tlv */
    public static function i8(array $tlv, int $tag): ?int
    {
        $value = self::value($tlv, $tag);
        return $value === null ? null : unpack('c', $value[0])[1];
    }

    /** @param array<int, array{value?: string, state?: int}> $tlv */
    public static function i16(array $tlv, int $tag): ?int
    {
        $value = self::value($tlv, $tag);
        return $value === null || strlen($value) < 2 ? null : unpack('s', substr($value, 0, 2))[1];
    }

    /** @param array<int, array{value?: string, state?: int}> $tlv */
    public static function u16(array $tlv, int $tag): ?int
    {
        $value = self::value($tlv, $tag);
        return $value === null || strlen($value) < 2 ? null : unpack('v', substr($value, 0, 2))[1];
    }

    /** @param array<int, array{value?: string, state?: int}> $tlv */
    public static function text(array $tlv, int $tag): ?string
    {
        $value = self::value($tlv, $tag);
        return $value === null ? null : rtrim($value, "\0");
    }
}
