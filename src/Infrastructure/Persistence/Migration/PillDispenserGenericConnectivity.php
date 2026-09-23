<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * O sinal do dispensador passa a ser a `connectivity` que o hub já tem, e o ambiente vira
 * alerta.
 *
 * A ligação à rede já tem forma no contrato — os gateways publicam `interface` e
 * `signalStrengthDbm` —, e um `gsmSignalDbm` próprio obrigava quem integra a conhecer mais um
 * formato. O ambiente de armazenamento sai da telemetria para os alarmes, e passa a falar só
 * quando a temperatura ou a humidade saem da gama.
 */
final class PillDispenserGenericConnectivity implements Migration
{
    public function version(): string
    {
        return '2026_09_23_pill_dispenser_generic_connectivity';
    }

    public function up(PDO $pdo): void
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('pill_dispenser', 'telemetry', 'connectivity', 'Conectividade', 0, 0)
            ON DUPLICATE KEY UPDATE section = VALUES(section), label = VALUES(label)
        ");
        $pdo->exec("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT m.id, 'pill_dispenser', 'connectivity', 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ");

        $pdo->exec("
            UPDATE capabilities
            SET section = 'alarms', label = 'Medicação mal conservada'
            WHERE device_type = 'pill_dispenser' AND capability_key = 'storage_environment'
        ");
    }
}
