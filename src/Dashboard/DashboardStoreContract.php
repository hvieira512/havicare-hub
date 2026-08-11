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
     * Streams subscribe here to be told when a device's history changes.
     *
     * It lives on the contract so consumers take it from the store they
     * already hold: a separately injected notifier can silently be the
     * wrong instance, and nothing would ever fire.
     */
    public function updates(): DeviceUpdateNotifier;

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

    /**
     * Built commands in send order, not a keyed map: the hub forwards this
     * straight into the Wonlex device state, so the shape is on the wire.
     *
     * @return list<array<string, mixed>>
     */
    public function desiredConfigurations(string $imei): array;
}
