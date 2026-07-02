<?php

namespace Hub\Watch;

use Hub\DeviceEventDecoder;
use Hub\DeviceEventPayloadBuilder;
use Hub\DeviceSession;
use Hub\Protocol\Adapter\DeviceAdapterInterface;

abstract class AbstractWatchProtocol implements WatchProtocolInterface
{
    public function __construct(
        protected readonly DeviceAdapterInterface $adapter,
        protected readonly DeviceEventDecoder $eventDecoder,
    ) {
    }

    public function protocol(): string
    {
        return $this->adapter->protocol();
    }

    public function canDecode(string $raw): bool
    {
        return $this->adapter->canDecode($raw);
    }

    public function decodeIncoming(string $raw, array $context = []): ?array
    {
        return $this->adapter->decodeIncoming($raw, $context);
    }

    public function encodeOutgoing(array $payload, array $context = []): string
    {
        return $this->adapter->encodeOutgoing($payload, $context);
    }

    public function handleIncoming(DeviceSession $session, string $raw): ?WatchMessage
    {
        $decoded = $this->decodeIncoming($raw, ['session' => $session->identityContext()]);
        if (!is_array($decoded)) {
            return null;
        }

        $telemetry = [];
        foreach ($this->eventDecoder->decode($session, $decoded) as $event) {
            $telemetry[] = DeviceEventPayloadBuilder::decoded($session, $event);
        }

        return new WatchMessage(
            decoded: $decoded,
            telemetry: $telemetry,
            responses: $this->responsesForDecoded($session, $decoded),
        );
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

    /**
     * @return array<int, WatchResponse>
     */
    abstract protected function responsesForDecoded(DeviceSession $session, array $decoded): array;
}
