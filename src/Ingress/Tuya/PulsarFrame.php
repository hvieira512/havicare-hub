<?php

declare(strict_types=1);

namespace Hub\Ingress\Tuya;

/**
 * Uma trama do Message Service da Tuya: JSON com a mensagem de negócio cifrada com a chave do
 * projeto, dentro de um envelope em base64.
 */
final class PulsarFrame
{
    public static function password(string $accessId, string $accessSecret): string
    {
        return substr(md5($accessId . md5($accessSecret)), 8, 16);
    }

    /**
     * @param array<string, mixed> $wire a trama já descodificada do JSON
     * @return array<string, mixed> a mensagem de negócio, com o `bizCode` e o `bizData`
     * @throws \RuntimeException quando a trama não se lê
     */
    public static function open(array $wire, string $accessSecret): array
    {
        // O projeto cifra em AES-128-GCM: os 12 primeiros bytes são o nonce, os 16 últimos a etiqueta.
        if (($wire['properties']['em'] ?? '') !== 'aes_gcm') {
            throw new \RuntimeException('Tuya frame is not AES-GCM encrypted');
        }

        $envelope = json_decode((string)base64_decode((string)($wire['payload'] ?? ''), true), true);
        $raw = is_array($envelope) ? base64_decode((string)($envelope['data'] ?? ''), true) : false;
        if (!is_string($raw) || strlen($raw) <= 28) {
            throw new \RuntimeException('Tuya frame without an encrypted payload');
        }

        $plain = openssl_decrypt(substr($raw, 12, -16), 'aes-128-gcm', substr($accessSecret, 8, 16), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, -16));
        $business = is_string($plain) ? json_decode($plain, true) : null;
        if (!is_array($business)) {
            throw new \RuntimeException('Tuya frame did not decrypt with the project key');
        }

        return $business;
    }
}
