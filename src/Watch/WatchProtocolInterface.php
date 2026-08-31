<?php

namespace Hub\Watch;

use Hub\DeviceSession;
use Hub\Protocol\Adapter\DeviceAdapterInterface;

interface WatchProtocolInterface extends DeviceAdapterInterface
{
    public function handleIncoming(DeviceSession $session, string $raw): ?WatchMessage;

    /**
     * @return array{nativeType?: string, protocol?: string, ident?: string|int}|null
     */
    public function commandMetadata(string $bytes): ?array;
}
