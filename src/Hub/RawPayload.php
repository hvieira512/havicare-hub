<?php

namespace App\Hub;

class RawPayload
{
    public static function envelope(
        string $imei,
        string $transport,
        string $protocol,
        string $raw,
        string $direction,
        ?string $connectionId = null,
    ): array {
        $payload = [
            'event' => [
                'type' => 'device.raw.' . $direction,
                'id' => 'raw_' . bin2hex(random_bytes(8)),
            ],
            'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'device' => [
                'imei' => $imei,
            ],
            'transport' => $transport,
            'protocol' => $protocol,
            'encoding' => 'base64',
            'payload' => base64_encode($raw),
            'size' => strlen($raw),
        ];

        if ($connectionId !== null && $connectionId !== '') {
            $payload['connectionId'] = $connectionId;
        }

        if (self::isText($raw)) {
            $payload['text'] = $raw;
        }

        return $payload;
    }

    public static function bytesFromDownlink(mixed $message): ?string
    {
        if (is_string($message)) {
            return $message;
        }

        if (!is_array($message)) {
            return null;
        }

        $encoding = strtolower((string)($message['encoding'] ?? 'text'));
        $payload = $message['payload'] ?? ($message['text'] ?? null);
        if (!is_string($payload)) {
            return null;
        }

        if ($encoding === 'base64') {
            $decoded = base64_decode($payload, true);
            return $decoded === false ? null : $decoded;
        }

        if ($encoding === 'text' || $encoding === 'raw') {
            return $payload;
        }

        return null;
    }

    private static function isText(string $raw): bool
    {
        if ($raw === '') {
            return true;
        }

        if (preg_match('//u', $raw) !== 1) {
            return false;
        }

        return preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', $raw) !== 1;
    }
}
