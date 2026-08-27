<?php

namespace Hub\Dashboard;

interface DashboardStoreContract
{
    public function registerDevice(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        int $licenseId = 0,
        string $simNumber = '',
        string $deviceId = '',
        string $company = 'null'
    ): void;

    /**
     * Os streams subscrevem aqui para saber quando o histórico de um dispositivo muda.
     *
     * Vive no contrato para quem consome o tirar do store que já tem: um notificador
     * injectado à parte pode ser a instância errada em silêncio, e nada dispararia nunca.
     */
    public function updates(): DeviceUpdateNotifier;

    public function deleteDevice(string $imei): void;

    public function updateDeviceAssociation(string $imei, string $company, int $licenseId): void;

    /** O dono fica vazio para os protocolos que não o sabem: as duas juntas ou nenhuma. */
    public function recordRejectedDevice(
        string $imei,
        string $protocol,
        string $model,
        string $ident,
        string $reason,
        int $licenseId = 0,
        ?string $company = null
    ): void;

    public function deviceSeen(string $imei, array $fields): void;

    /**
     * A intensidade de sinal pertence ao par (dispositivo, gateway), e por isso é registada
     * contra o dispositivo retransmitido e lida pelos dois lados da ligação.
     */
    public function recordGatewaySighting(string $deviceKey, string $gatewayKey, ?int $rssiDbm): void;

    /** @return array<string, array<string, mixed>> gateway key => sighting */
    public function gatewaySightings(string $deviceKey): array;

    public function deviceOffline(string $imei): void;

    public function append(string $imei, string $list, array $payload): void;

    public function recordCommand(string $imei, string $id, array $record): void;

    public function markLatestCommand(string $imei, string $nativeType, array $fields): void;

    public function markCommand(string $imei, string $id, array $fields): void;

    public function isCurrentOperation(string $operationId): bool;

    public function markCommandReply(
        string $imei,
        string $replyNativeType,
        string|int|null $ident = null,
        string $ref = '',
        ?bool $accepted = null,
    ): void;

    public function expireWaitingCommands(int $timeoutSeconds): void;

    public function expireStaleDevices(int $timeoutSeconds): void;

    /**
     * Reenvia os comandos de configuração em fila e repete os enviados que ainda não foram
     * confirmados.
     *
     * @param callable(string, string, array): string $dispatch
     */
    public function retryWaitingCommands(
        int $retryAfterSeconds,
        int $timeoutSeconds,
        int $maxAttempts,
        callable $dispatch
    ): void;

    public function devices(): array;

    public function device(string $imei): array;

    public function runtimeStates(array $imeis): array;

    /**
     * Os IMEI dos dispositivos ligados, para filtrar a listagem por estado.
     *
     * @return list<string>
     */
    public function onlineDeviceImeis(): array;

    public function recent(string $imei, string $list): array;

    public function commands(string $imei): array;

    public function findCommand(string $id): ?array;

    /**
     * Os comandos construídos por ordem de envio, e não um mapa por chave: o hub encaminha
     * isto directamente para o estado do dispositivo Wonlex, logo a forma está no fio.
     *
     * @return list<array<string, mixed>>
     */
    public function desiredConfigurations(string $imei): array;
}
