<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Gateway;

/**
 * Se uma observação já foi vista antes, com prazo, para gateways que repetem o que já enviaram.
 */
interface ObservationStateStore
{
    public function acceptObservation(string $deviceKey, string $fingerprint, int $ttlSeconds): bool;

    /**
     * O `$observedBy` restringe o estrangulamento a cada gateway, que é uma medição distinta;
     * vazio para um dispositivo que reporta sobre si próprio.
     *
     * @param array<string, mixed> $payload
     */
    public function shouldPublish(string $deviceKey, string $capability, array $payload, int $refreshSeconds, string $observedBy = ''): bool;

    /**
     * Null quando a condição não mudou, e a transição caso contrário; a primeira observação é
     * uma transição, com `previous` null.
     *
     * @return array{previous: ?string}|null
     */
    public function transitionCondition(string $deviceKey, string $condition): ?array;

    /**
     * Avança um contador cumulativo e devolve o valor de onde se contam os toques novos: o
     * anterior quando sobe, zero num reinício. Null na primeira observação, quando não mexe, e
     * quando desce sem ser reinício.
     */
    public function advanceCounter(string $deviceKey, int $count): ?int;
}
