<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * A sensibilidade dos alertas passa a ser uma capacidade como as outras.
 *
 * Dois passos, e a ordem importa.
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
 * **Sem cópia de dados.** A `diaper_sensor_settings` de produção tem um sensor, no preset
 * normal, e a ausência de linha JÁ significa normal -- copiá-la escreveria uma linha a
 * dizer o que o silêncio já diz. Um sensor configurado à mão que existisse voltaria ao
 * preset e nada mais; não é o caso, e é por isso que a tabela se larga a seguir sem rede.
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
    }
}
