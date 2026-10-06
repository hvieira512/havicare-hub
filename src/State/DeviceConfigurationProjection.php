<?php

declare(strict_types=1);

namespace Hub\State;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Command\DeviceConfigurationCatalog;

final class DeviceConfigurationProjection
{
    private ?ApiDataAccess $db = null;

    public function setDataAccess(?ApiDataAccess $db): void
    {
        $this->db = $db;
    }

    /** @param array<string, mixed> $payload */
    public function saveReported(
        string $imei,
        string $protocol,
        string $nativeType,
        array $payload
    ): void {
        if ($this->db === null) {
            return;
        }

        // Uma leitura com várias configurações guarda-se uma a uma: o dispensador responde ao `0x05`
        // com todas, e a chave pelo tipo da resposta só serve quando ela confirma uma.
        $settings = $payload['data']['settings'] ?? null;
        if (is_array($settings) && $settings !== []) {
            foreach ($settings as $settingKey => $value) {
                if (!is_array($value)) {
                    continue;
                }
                $this->db->deviceConfigurations->saveReported(
                    $imei,
                    (string)$settingKey,
                    $protocol,
                    $nativeType,
                    ['type' => 'device_config', 'data' => $value] + $payload,
                );
            }

            return;
        }

        // Um tipo de resposta que identifica uma configuração nomeia-a; um que várias declarem não
        // nomeia nenhuma, porque guardar na errada é pior do que não guardar.
        $candidates = [];
        foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
            if (in_array($nativeType, $entry['expectedReplyTypes'] ?? [], true)) {
                $candidates[(string)$entry['key']] = true;
            }
        }
        if (count($candidates) !== 1) {
            return;
        }

        $this->db->deviceConfigurations->saveReported(
            $imei,
            (string)array_key_first($candidates),
            $protocol,
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
