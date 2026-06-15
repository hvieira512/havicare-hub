<?php

namespace Hub\Protocol\Adapter;

class WonlexAdapter implements DeviceAdapterInterface
{
    public function protocol(): string
    {
        return 'wonlex-json';
    }

    public function canDecode(string $raw): bool
    {
        if (strlen($raw) < 4) {
            return false;
        }

        $header = @unpack('nstart/nlength', substr($raw, 0, 4));
        return ($header['start'] ?? null) === 0xFCAF;
    }

    public function decodeIncoming(string $raw, array $context = []): ?array
    {
        if (!$this->canDecode($raw)) {
            return null;
        }

        $header = unpack('nstart/nlength', substr($raw, 0, 4));
        $length = $header['length'] ?? 0;
        $json = substr($raw, 4, $length);
        $payload = json_decode($json, true);

        if (!is_array($payload) || !isset($payload['type'])) {
            return null;
        }

        if (($context['attachRawJson'] ?? false) === true) {
            $payload['_rawJson'] = $json;
        }

        return $payload;
    }

    public function encodeOutgoing(array $payload, array $context = []): string
    {
        $jsonOptions = isset($context['jsonOptions']) && is_int($context['jsonOptions'])
            ? $context['jsonOptions']
            : JSON_UNESCAPED_UNICODE;
        $json = json_encode($payload, $jsonOptions);
        if ($json === false) {
            $json = '{}';
        }

        return pack('nn', 0xFCAF, strlen($json)) . $json;
    }
}
