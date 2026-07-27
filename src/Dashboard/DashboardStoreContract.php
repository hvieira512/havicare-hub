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

    public function deleteDevice(string $imei): void;

    public function updateDeviceAssociation(string $imei, string $company, int $licenseId): void;

    public function recordRejectedDevice(
        string $imei,
        string $protocol,
        string $model,
        string $ident,
        string $reason
    ): void;

    public function deviceSeen(string $imei, array $fields): void;

    public function deviceOffline(string $imei): void;

    public function append(string $imei, string $list, array $payload): void;

    public function recordCommand(string $imei, string $id, array $record): void;

    public function markLatestCommand(string $imei, string $nativeType, array $fields): void;

    public function markCommandReply(string $imei, string $replyNativeType): void;

    public function expireWaitingCommands(int $timeoutSeconds): void;

    public function expireStaleDevices(int $timeoutSeconds): void;

    /**
     * Redispatch queued configuration commands and retry sent commands that have
     * not been acknowledged yet.
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

    public function recent(string $imei, string $list): array;

    public function commands(string $imei): array;

    public function findCommand(string $id): ?array;
}
