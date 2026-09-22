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
        string $nativeType,
        array $payload
    ): void {
        if ($this->db === null) {
            return;
        }

        // Uma leitura que traz várias configurações de uma vez guarda-se uma a uma. Resolver
        // a chave pelo tipo da resposta serve quando cada resposta confirma uma configuração
        // -- é assim nos relógios --, mas o dispensador responde ao `0x05` com todas, e o
        // bloco inteiro ficava debaixo de uma chave só.
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

        // Um tipo de resposta que identifica **uma** configuração nomeia-a. Um que várias
        // declarem não nomeia nenhuma: ficar pela primeira do catálogo era escolher à sorte, e
        // o valor de uma escrita ia parar à linha de outra configuração qualquer, que passava
        // a mostrar um reportado que nunca foi dela. Melhor não guardar do que guardar errado.
        $candidatas = [];
        foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
            if (in_array($nativeType, $entry['expectedReplyTypes'] ?? [], true)) {
                $candidatas[(string)$entry['key']] = true;
            }
        }
        if (count($candidatas) !== 1) {
            return;
        }

        $this->db->deviceConfigurations->saveReported(
            $imei,
            (string)array_key_first($candidatas),
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
