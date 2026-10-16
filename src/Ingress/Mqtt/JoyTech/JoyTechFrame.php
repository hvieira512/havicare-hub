<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\JoyTech;

/**
 * As tramas dos aparelhos JoyTech, tensiómetro e termómetro: `FA AA AA AF`, o tamanho da trama
 * inteira em 2 B, a função, os dados, a soma do tamanho aos dados (módulo 256) e `F5 5F`.
 */
final class JoyTechFrame
{
    private const HEAD = 'faaaaaaf';

    private const TAIL = 'f55f';

    /** @return array{function: int, data: string}|null os dados em binário */
    public static function decode(string $hex): ?array
    {
        $hex = strtolower(preg_replace('/\s+/', '', $hex) ?? '');
        if (!ctype_xdigit($hex) || strlen($hex) < 20 || !str_starts_with($hex, self::HEAD) || !str_ends_with($hex, self::TAIL)) {
            return null;
        }

        $bytes = (string)hex2bin($hex);
        $length = unpack('n', substr($bytes, 4, 2))[1];
        if ($length !== strlen($bytes)) {
            return null;
        }
        $body = substr($bytes, 4, $length - 7);
        if (array_sum(array_map('ord', str_split($body))) % 256 !== ord($bytes[$length - 3])) {
            return null;
        }

        return ['function' => ord($body[2]), 'data' => substr($body, 3)];
    }

    /** A hora local que o aparelho guarda, com o ano em dois dígitos, em unix. */
    public static function unixTime(int $year, int $month, int $day, int $hour, int $minute, int $offsetMinutes): ?int
    {
        if (!checkdate($month, $day, 2000 + $year) || $hour > 23 || $minute > 59) {
            return null;
        }

        return gmmktime($hour, $minute, 0, $month, $day, 2000 + $year) - $offsetMinutes * 60;
    }

    /** @param string $data em binário */
    public static function encode(int $function, string $data = ''): string
    {
        $body = pack('n', strlen($data) + 10) . chr($function) . $data;

        return self::HEAD . bin2hex($body . chr(array_sum(array_map('ord', str_split($body))) % 256)) . self::TAIL;
    }
}
