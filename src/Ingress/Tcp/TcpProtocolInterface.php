<?php

declare(strict_types=1);

namespace Hub\Ingress\Tcp;

use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\DeviceAdapterInterface;

interface TcpProtocolInterface extends DeviceAdapterInterface
{
    public function handleIncoming(DeviceSession $session, string $raw): ?TcpMessage;

    /**
     * Se esta trama é o aparelho a dizer que aceitou ou recusou uma configuração; `null` é
     * «não disse», e não «recusou».
     *
     * @param array<string, mixed> $decoded
     */
    public function replyAccepted(array $decoded): ?bool;

    /**
     * @return array{nativeType?: string, protocol?: string, ident?: string|int}|null
     */
    public function commandMetadata(string $bytes): ?array;
}
