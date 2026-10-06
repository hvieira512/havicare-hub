<?php

declare(strict_types=1);

namespace Hub\State;

/** Que dispositivos existem, de quem são, e em que estado estão. */
interface DeviceRegistry
{
    public function registerDevice(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        int|string $licenseId = 0,
        string $simNumber = '',
        string $deviceId = '',
        string $company = 'null'
    ): void;

    public function deleteDevice(string $imei): void;

    public function updateDeviceAssociation(string $imei, string $company, int $licenseId): void;

    public function expireStaleDevices(int $timeoutSeconds): void;

    /** @return list<array<string, mixed>> */
    public function devices(): array;

    /** @return array<string, mixed> */
    public function device(string $imei): array;

    /**
     * @param list<string> $imeis
     * @return array<string, array<string, mixed>>
     */
    public function runtimeStates(array $imeis): array;

    /**
     * Os IMEI dos dispositivos ligados, para filtrar a listagem por estado.
     *
     * @return list<string>
     */
    public function onlineDeviceImeis(): array;

    /** @return array<string, array<string, mixed>> chave do gateway => avistamento */
    public function gatewaySightings(string $deviceKey): array;
}
