<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\JoyTech;

/**
 * As tramas do protocolo BP da JoyTech: `FA AA AA AF`, o tamanho da trama inteira em 2 B, a
 * função, os dados, a soma do tamanho aos dados (módulo 256) e `F5 5F`.
 */
final class BpFrame
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

    /** @param string $data em binário */
    public static function encode(int $function, string $data = ''): string
    {
        $body = pack('n', strlen($data) + 10) . chr($function) . $data;

        return self::HEAD . bin2hex($body . chr(array_sum(array_map('ord', str_split($body))) % 256)) . self::TAIL;
    }
}
