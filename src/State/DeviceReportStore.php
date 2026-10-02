<?php

declare(strict_types=1);

namespace Hub\State;

/**
 * O que um dispositivo disse: que está vivo, o que mediu, e quem bateu à porta sem ser
 * reconhecido. É a interface de quem recebe do fio, e nenhuma ingestão precisa de mais.
 */
interface DeviceReportStore
{
    public function deviceSeen(string $imei, array $fields): void;

    public function deviceOffline(string $imei): void;

    public function append(string $imei, string $list, array $payload): void;

    /**
     * A intensidade de sinal pertence ao par (dispositivo, gateway), e por isso é registada
     * contra o dispositivo retransmitido e lida pelos dois lados da ligação.
     */
    public function recordGatewaySighting(string $deviceKey, string $gatewayKey, ?int $rssiDbm): void;

    /** O dono fica vazio para os protocolos que não o sabem: as duas juntas ou nenhuma. */
    public function recordRejectedDevice(
        string $imei,
        string $protocol,
        string $model,
        string $ident,
        string $reason,
        int|string $licenseId = 0,
        ?string $company = null
    ): void;
}
