<?php

namespace Hub\Device\Watch;

use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\DeviceAdapterInterface;

interface WatchProtocolInterface extends DeviceAdapterInterface
{
    public function handleIncoming(DeviceSession $session, string $raw): ?WatchMessage;

    /**
     * Se esta trama é o aparelho a dizer que aceitou ou recusou uma configuração.
     *
     * `null` não é «recusou», é «não disse» — a esmagadora maioria das tramas não comenta
     * configuração nenhuma, e tratar isso como recusa marcava como falhada uma escrita que o
     * aparelho nem chegou a mencionar.
     *
     * @param array<string, mixed> $decoded
     */
    public function replyAccepted(array $decoded): ?bool;

    /**
     * @return array{nativeType?: string, protocol?: string, ident?: string|int}|null
     */
    public function commandMetadata(string $bytes): ?array;
}
