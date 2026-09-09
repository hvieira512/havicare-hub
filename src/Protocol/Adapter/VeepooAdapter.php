<?php

namespace Hub\Protocol\Adapter;

/**
 * Pulseiras Veepoo, alcançadas através de um gateway BLE.
 *
 * Só existe pelo lado do downlink. A subida não passa por aqui: o gateway já entrega os
 * valores estruturados pelo SDK do fabricante, e quem lhes dá os nomes do hub é o
 * `Ingress\Mqtt\Veepoo\Bridge`. Por isso `canDecode` recusa sempre -- não há trama crua
 * que este adaptador saiba reclamar, e dizer que sim tirava mensagens a quem as sabe ler.
 *
 * E o que desce não são bytes para a pulseira: é o nome de uma operação para a caixa que
 * tem a sessão BLE. É ela que fala com o SDK; o hub não sabe montar uma trama Veepoo nem
 * precisa de saber.
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
