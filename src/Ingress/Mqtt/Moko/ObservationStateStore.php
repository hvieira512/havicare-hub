<?php

namespace Hub\Ingress\Mqtt\Moko;

interface ObservationStateStore
{
    public function acceptObservation(string $deviceKey, string $fingerprint, int $ttlSeconds): bool;

    /**
     * O `$observedBy` restringe o estrangulamento a quem fez a observação: cada gateway em
     * alcance é uma medição distinta, e sem esse âmbito o primeiro a publicar suprimia os
     * outros -- qual deles ganhava era uma corrida.
     *
     * Vazio para um dispositivo que reporta sobre si próprio.
     *
     * @param array<string, mixed> $payload
     */
    public function shouldPublish(string $deviceKey, string $capability, array $payload, int $refreshSeconds, string $observedBy = ''): bool;

    /**
     * Devolve null quando a condição não mudou, e a transição caso contrário. O `previous` é
     * null na primeira observação, que é uma transição como outra qualquer -- devolver null
     * também aí engolia o alarme de um dispositivo visto pela primeira vez.
     *
     * @return array{previous: ?string}|null
     */
    public function transitionCondition(string $deviceKey, string $condition): ?array;
}
