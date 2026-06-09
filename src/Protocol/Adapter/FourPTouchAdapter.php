<?php

namespace App\Protocol\Adapter;

class FourPTouchAdapter implements DeviceAdapterInterface
{
    public function protocol(): string
    {
        return 'four-p-touch';
    }

    public function canDecode(string $raw): bool
    {
        return $this->parseFrame($raw) !== null;
    }

    public function decodeIncoming(string $raw, array $context = []): ?array
    {
        $frame = $this->parseFrame($raw);
        if ($frame === null) {
            return null;
        }

        $fields = $frame['content'] === '' ? [] : explode(',', $frame['content']);
        $type = (string)($fields[0] ?? '');
        if ($type === '') {
            return null;
        }

        return [
            'type' => $type,
            'ident' => $frame['deviceId'],
            'ref' => 'w:update',
            'imei' => $frame['deviceId'],
            'data' => [
                'manufacturer' => $frame['manufacturer'],
                'deviceId' => $frame['deviceId'],
                'length' => $frame['length'],
                'raw' => $frame['content'],
                'fields' => array_slice($fields, 1),
            ],
            'timestamp' => (int)round(microtime(true) * 1000),
        ];
    }

    public function encodeOutgoing(array $payload, array $context = []): string
    {
        $manufacturer = $this->frameValue((string)($payload['manufacturer'] ?? $context['manufacturer'] ?? '3G'));
        $deviceId = $this->frameValue((string)($payload['deviceId'] ?? $payload['imei'] ?? $context['deviceId'] ?? ''));
        $type = $this->frameValue((string)($payload['type'] ?? ''));
        if ($deviceId === '' || $type === '') {
            return '';
        }

        $fields = isset($payload['data']['fields']) && is_array($payload['data']['fields'])
            ? $payload['data']['fields']
            : [];
        $content = $type;
        if ($fields !== []) {
            $content .= ',' . implode(',', array_map(static fn (mixed $value): string => (string)$value, $fields));
        }

        return sprintf('[%s*%s*%04X*%s]', $manufacturer, $deviceId, strlen($content), $content);
    }

    private function parseFrame(string $raw): ?array
    {
        $message = trim($raw);
        if (preg_match('/^\[(CS|3G)\*(\d{10})\*([0-9A-Fa-f]{4})\*(.*)\]$/', $message, $matches) !== 1) {
            return null;
        }

        $content = $matches[4];
        $length = hexdec($matches[3]);
        if ($length !== strlen($content)) {
            return null;
        }

        return [
            'manufacturer' => $matches[1],
            'deviceId' => $matches[2],
            'length' => strtoupper($matches[3]),
            'content' => $content,
        ];
    }

    private function frameValue(string $value): string
    {
        return str_replace(['[', ']', '*'], '', trim($value));
    }
}
