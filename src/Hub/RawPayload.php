<?php

namespace Hub;

class RawPayload
{
    public static function raw(
        string $imei,
        string $supplier,
        string $model,
        string $transport,
        string $protocol,
        string $raw,
        string $direction,
        ?string $connectionId = null,
    ): array {
        $encoding = self::isText($raw) ? 'text' : 'base64';

        $payload = [
            'schemaVersion' => 1,
            'direction' => $direction,
            'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'device' => self::device($imei, $supplier, $model),
            'debug' => [
                'protocol' => $protocol,
                'transport' => $transport,
                'encoding' => $encoding,
                'payload' => $encoding === 'text' ? $raw : base64_encode($raw),
                'size' => strlen($raw),
            ],
        ];

        if ($connectionId !== null && $connectionId !== '') {
            $payload['debug']['connectionId'] = $connectionId;
        }

        return $payload;
    }

    public static function status(string $imei, string $supplier, string $model, string $state, ?array $error = null): array
    {
        $payload = [
            'schemaVersion' => 1,
            'state' => $state,
            'updatedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'device' => self::device($imei, $supplier, $model),
        ];

        if ($error !== null) {
            $payload['error'] = $error;
        }

        return $payload;
    }

    public static function event(
        string $imei,
        string $supplier,
        string $model,
        string $type,
        ?array $error = null,
        ?array $command = null,
    ): array {
        $payload = [
            'schemaVersion' => 1,
            'type' => $type,
            'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'device' => self::device($imei, $supplier, $model),
        ];

        if ($error !== null) {
            $payload['error'] = $error;
        }
        if ($command !== null && $command !== []) {
            $payload['command'] = $command;
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

    private static function device(string $imei, string $supplier, string $model): array
    {
        $device = ['id' => $imei];
        if ($supplier !== '') {
            $device['supplier'] = $supplier;
        }
        if ($model !== '') {
            $device['model'] = $model;
        }

        return $device;
    }
}
