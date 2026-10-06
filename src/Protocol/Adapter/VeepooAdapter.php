<?php

declare(strict_types=1);

namespace Hub\Protocol\Adapter;

/**
 * Pulseiras Veepoo, através de um gateway BLE. Só desce: a subida é do `VeepooBridge`, e o que
 * desce é o nome de uma operação para a caixa com a sessão BLE, e não bytes.
 */
final class VeepooAdapter implements DeviceAdapterInterface
{
    public function protocol(): string
    {
        return 'veepoo-ble';
    }

    public function canDecode(string $raw): bool
    {
        return false;
    }

    public function decodeIncoming(string $raw, array $context = []): ?array
    {
        return null;
    }

    public function encodeOutgoing(array $payload, array $context = []): string
    {
        $operation = trim((string)($payload['command'] ?? $payload['operation'] ?? ''));

        return $operation;
    }
}
