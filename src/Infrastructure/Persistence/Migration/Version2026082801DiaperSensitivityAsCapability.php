<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * A sensibilidade dos alertas passa a ser uma capacidade como as outras.
 *
 * Três passos, e a ordem importa.
 *
 * O `syncCapabilities` cria a linha em `capabilities`, que é o que faz a capacidade
 * existir. Sem ela o `INSERT` seguinte não encontra o que ligar.
 *
 * Depois liga-a a todos os modelos de medidor de fraldas. Sem isto o pipeline rejeita-a
 * com `capability_not_enabled_for_model` -- é o segundo passo do
 * `DeviceConfigurationUpdateService`, e sem ele a sensibilidade dos sensores que já
 * existem deixava simplesmente de gravar. `INSERT IGNORE` e sem `UPDATE`: se alguém a
 * desligar à mão um dia, fica desligada.
 *
 * Por fim, os valores que estavam na `diaper_sensor_settings` viram linhas do ciclo de
 * vida das configurações. `last_status = 'acked'` com `reported_payload` vazio é o que o
 * `ConfigurationSyncStatus` lê como `applied`, que é a verdade: está guardado e a ingestão
 * já o usa. O `confirmation_mode = 'local'` é o que distingue estas linhas de uma que foi
 * mesmo confirmada por um dispositivo.
 *
 * A tabela antiga não é apagada aqui. Fica para um passo à parte, depois de isto correr em
 * produção e de se confirmar que a ingestão lê os valores novos: uma migração que apaga
 * dados não deve ser a mesma que os copia.
 */
final class Version2026082801DiaperSensitivityAsCapability implements Migration
{
    public function version(): string
    {
        return '2026082801_diaper_sensitivity_as_capability';
    }

    public function up(PDO $pdo): void
    {
        (new ReferenceCatalogSeeder())->syncCapabilities($pdo);

        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, capability_id, enabled)
            SELECT m.id, c.id, 1
            FROM models m
            JOIN capabilities c
              ON c.device_type = m.device_type
             AND c.capability_key = 'diaper_sensitivity'
            WHERE m.device_type = 'diaper_sensor'
        ");

        if (!(new MysqlSchema($pdo))->hasTable('diaper_sensor_settings')) {
            return;
        }

        $pdo->exec("
            INSERT IGNORE INTO device_configurations (
                imei, config_key, native_key, protocol, supplier, model, command,
                desired_payload, reported_payload, desired_revision, confirmed_revision,
                current_change_id, confirmation_mode, last_status, last_error,
                last_command_id, desired_updated_at, reported_at, applied_at
            )
            SELECT
                settings.imei, 'diaper_sensitivity', 'diaper_sensitivity',
                'monit-mecs-pro-ble',
                COALESCE(whitelist.supplier, ''), COALESCE(whitelist.model, ''), '',
                JSON_OBJECT(
                    'pollutionRange', settings.pollution_range,
                    'pollutionValue', settings.pollution_value
                ),
                '{}', 1, 0, '', 'local', 'acked', '', '',
                DATE_FORMAT(settings.updated_at, '%Y-%m-%dT%H:%i:%sZ'), '', ''
            FROM diaper_sensor_settings settings
            LEFT JOIN whitelist ON whitelist.imei = settings.imei
        ");
    }
}
