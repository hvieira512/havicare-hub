<?php

namespace Hub\Protocol\Adapter;

/**
 * Pulseiras Veepoo, alcançadas através de um gateway BLE.
 *
 * Só existe pelo lado do downlink: a subida é do `Ingress\Mqtt\Veepoo\Bridge`, e por isso o
 * `canDecode` recusa sempre. E o que desce não são bytes para a pulseira, é o nome de uma
 * operação para a caixa que tem a sessão BLE.
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
