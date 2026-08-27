<?php

namespace Hub\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Command\DeviceConfigurationCatalog;

final class DeviceConfigurationProjection
{
    private ?ApiDataAccess $db = null;

    public function setDataAccess(?ApiDataAccess $db): void
    {
        $this->db = $db;
    }

    public function saveReported(
        string $imei,
        string $protocol,
        string $supplier,
        string $model,
        string $nativeType,
        array $payload
    ): void {
        if ($this->db === null) {
            return;
        }
        $key = $nativeType;
        foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
            if (in_array($nativeType, $entry['expectedReplyTypes'] ?? [], true)) {
                $key = (string)$entry['key'];
                break;
            }
        }
        $this->db->deviceConfigurations->saveReported(
            $imei,
            $key,
            $protocol,
            $supplier,
            $model,
            $nativeType,
            $payload
        );
    }

    public function markApplyStatus(
        string $imei,
        string $key,
        string $status,
        string $commandId = '',
        string $error = ''
    ): void {
        if ($this->db === null) {
            return;
        }
        if ($commandId !== '' && $this->db->configurationLifecycle->isCurrentOperation($commandId)) {
            $this->db->configurationLifecycle->updateOperation($commandId, $status, $error);
            return;
        }
        if ($commandId === '') {
            $this->db->deviceConfigurations->markApplyStatus($imei, $key, $status, $commandId);
        }
    }

    public function isCurrentOperation(string $operationId): bool
    {
        return $this->db === null || $this->db->configurationLifecycle->isCurrentOperation($operationId);
    }
}
