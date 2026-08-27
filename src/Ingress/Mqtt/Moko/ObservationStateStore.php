<?php

namespace Hub\Ingress\Mqtt\Moko;

interface ObservationStateStore
{
    public function acceptObservation(string $deviceKey, string $fingerprint, int $ttlSeconds): bool;

    /**
     * O `$observedBy` restringe o estrangulamento a quem fez a observação. Um dispositivo BLE
     * retransmitido é visto por todos os gateways em alcance, e cada avistamento é uma
     * medição distinta -- em particular o RSSI, que difere por gateway. Sem esse âmbito, o
     * primeiro gateway a publicar suprimia os outros durante `$refreshSeconds`, e qual deles
     * ganhava era uma corrida: o `gatewayId` reportado saía arbitrário.
     *
     * Vazio para um dispositivo que reporta sobre si próprio.
     *
     * @param array<string, mixed> $payload
     */
    public function shouldPublish(string $deviceKey, string $capability, array $payload, int $refreshSeconds, string $observedBy = ''): bool;

    /**
     * Devolve null quando a condição não mudou, e a transição caso contrário.
     *
     * O `previous` é null na primeira observação de um dispositivo, que é uma transição como
     * outra qualquer: um sensor cuja primeira leitura já pede atenção mudou de "desconhecido"
     * para esse estado, e quem chama tem de poder agir. Devolver null nos dois casos engolia
     * em silêncio o alarme de um dispositivo visto pela primeira vez, ou visto pela primeira
     * vez depois de o store perder os dados.
     *
     * @return array{previous: ?string}|null
     */
    public function transitionCondition(string $deviceKey, string $condition): ?array;
}
