<?php

namespace Hub\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Predis\ClientInterface;

final class DashboardStore implements DashboardStoreContract
{
    private DeviceRuntimeStore $runtime;
    private DeviceEventStore $events;
    private DeviceCommandStore $commands;
    private DeviceConfigurationProjection $projection;

    public function __construct(
        ClientInterface $redis,
        private int $limit = 100,
        private string $prefix = 'hub:dashboard',
    ) {
        $this->runtime = new DeviceRuntimeStore($redis, $this->limit, $this->prefix);
        $this->projection = new DeviceConfigurationProjection();
        $this->events = new DeviceEventStore($redis, $this->limit, $this->prefix, $this->projection);
        $this->commands = new DeviceCommandStore($redis, $this->runtime, $this->limit, $this->prefix, $this->projection);
    }

    public function setDataAccess(?ApiDataAccess $db): void
    {
        $this->projection->setDataAccess($db);
    }

    public function registerDevice(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        int $licenseId = 0,
        string $simNumber = '',
        string $deviceId = '',
        string $company = 'null'
    ): void {
        $this->runtime->registerDevice($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
    }

    public function deleteDevice(string $imei): void
    {
        $this->runtime->deleteDevice($imei);
    }

    public function updateDeviceAssociation(string $imei, string $company, int $licenseId): void
    {
        $this->runtime->updateDeviceAssociation($imei, $company, $licenseId);
    }

    public function deviceSeen(string $imei, array $fields): void
    {
        $this->runtime->deviceSeen($imei, $fields);
    }

    public function deviceOffline(string $imei): void
    {
        $this->runtime->deviceOffline($imei);
    }

    public function append(string $imei, string $list, array $payload): void
    {
        $this->events->append($imei, $list, $payload);
    }

    public function recordCommand(string $imei, string $id, array $record): void
    {
        $this->commands->recordCommand($imei, $id, $record);
    }

    public function retryWaitingCommands(int $retryAfterSeconds, int $timeoutSeconds, int $maxAttempts, callable $dispatch): void
    {
        $this->commands->retryWaitingCommands($retryAfterSeconds, $timeoutSeconds, $maxAttempts, $dispatch);
    }

    public function markLatestCommand(string $imei, string $nativeType, array $fields): void
    {
        $this->commands->markLatestCommand($imei, $nativeType, $fields);
    }

    public function markCommandReply(string $imei, string $replyNativeType): void
    {
        $this->commands->markCommandReply($imei, $replyNativeType);
    }

    public function expireWaitingCommands(int $timeoutSeconds): void
    {
        $this->commands->expireWaitingCommands($timeoutSeconds);
    }

    public function expireStaleDevices(int $timeoutSeconds): void
    {
        $this->runtime->expireStaleDevices($timeoutSeconds);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function devices(): array
    {
        return $this->runtime->devices();
    }

    public function device(string $imei): array
    {
        return $this->runtime->device($imei);
    }

    /**
     * @param list<string> $imeis
     * @return array<string, array<string, mixed>>
     */
    public function runtimeStates(array $imeis): array
    {
        return $this->runtime->runtimeStates($imeis);
    }

    public function recent(string $imei, string $list): array
    {
        return $this->events->recent($imei, $list);
    }

    public function commands(string $imei): array
    {
        return $this->commands->commands($imei);
    }

    public function findCommand(string $id): ?array
    {
        return $this->commands->findCommand($id);
    }
}
