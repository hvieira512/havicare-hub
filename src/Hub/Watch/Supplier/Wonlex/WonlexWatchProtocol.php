<?php

namespace Hub\Watch\Supplier\Wonlex;

use Hub\DeviceEventDecoder;
use Hub\DeviceSession;
use Hub\Protocol\Adapter\DeviceAdapterInterface;
use Hub\Watch\AbstractWatchProtocol;
use Hub\Watch\WatchResponse;

final class WonlexWatchProtocol extends AbstractWatchProtocol
{
    public function __construct(
        DeviceAdapterInterface $adapter,
        DeviceEventDecoder $eventDecoder,
    ) {
        parent::__construct($adapter, $eventDecoder);
    }

    /**
     * @return array<int, WatchResponse>
     */
    protected function responsesForDecoded(DeviceSession $session, array $decoded): array
    {
        $type = (string)($decoded['type'] ?? '');
        $responses = [];
        $timestamp = (int) round(microtime(true) * 1000);

        if ($type === 'login') {
            $responses[] = new WatchResponse($this->encodeOutgoing([
                'type' => 'login',
                'ident' => $this->replyIdent($decoded['ident'] ?? null),
                'ref' => 's:reply',
                'imei' => $session->imei,
                'data' => [
                    'type' => 'login',
                    'imei' => $session->imei,
                    'bindStatus' => 1,
                    'timestamp' => $timestamp,
                ],
                'timestamp' => $timestamp,
            ]));

            return $responses;
        }

        if ($type === 'heartbeat') {
            $responses[] = new WatchResponse($this->encodeOutgoing([
                'type' => 'heartbeat',
                'ident' => $this->replyIdent($decoded['ident'] ?? null),
                'ref' => 's:reply',
                'imei' => $session->imei,
                'data' => [
                    'type' => 'heartbeat',
                    'imei' => $session->imei,
                    'deviceModel' => $session->model,
                    'timestamp' => $timestamp,
                ],
                'timestamp' => $timestamp,
            ]));

            return $responses;
        }

        if (($decoded['ref'] ?? '') !== 'w:update') {
            return $responses;
        }

        if ($type === '' || in_array($type, ['login', 'heartbeat'], true)) {
            return $responses;
        }

        $responses[] = new WatchResponse($this->encodeOutgoing([
            'type' => $type,
            'ident' => $this->replyIdent($decoded['ident'] ?? null),
            'ref' => 's:reply',
            'imei' => $session->imei,
            'data' => [
                'type' => $type,
                'imei' => $session->imei,
                'timestamp' => $timestamp,
            ],
            'timestamp' => $timestamp,
        ]), true);

        return $responses;
    }

    public function commandMetadata(string $bytes): ?array
    {
        $decoded = $this->decodeIncoming($bytes);
        if (!is_array($decoded)) {
            return null;
        }

        $metadata = array_filter([
            'nativeType' => (string)($decoded['type'] ?? ''),
            'protocol' => $this->protocol(),
            'ident' => $decoded['ident'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return $metadata !== [] ? $metadata : null;
    }

    private function replyIdent(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && is_numeric($value) && (int)$value > 0) {
            return (int)$value;
        }

        return random_int(100000, 999999);
    }
}
