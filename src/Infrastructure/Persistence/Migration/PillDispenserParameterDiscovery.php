<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * As três acções que perguntam ao aparelho que parâmetros ele serve.
 *
 * Sem elas, a única maneira de saber se um firmware suporta uma TAG era mandá-la e ler a
 * recusa, que não escala para uma frota com firmwares diferentes. São três porque o aparelho
 * separa configuração, estado e controlo em pacotes próprios: `0x0A`, `0x0B` e `0x0C`.
 */
final class PillDispenserParameterDiscovery implements Migration
{
    private const ACTIONS = [
        'supported_configuration' => 'Parâmetros de configuração',
        'supported_status' => 'Parâmetros de estado',
        'supported_control' => 'Parâmetros de controlo',
    ];

    public function version(): string
    {
        return '2026_09_22_pill_dispenser_parameter_discovery';
    }

    public function up(PDO $pdo): void
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $capability = $pdo->prepare("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('pill_dispenser', 'settings_system', ?, ?, 0, 1)
            ON DUPLICATE KEY UPDATE
                section = VALUES(section),
                label = VALUES(label),
                is_configurable = VALUES(is_configurable),
                is_requestable = VALUES(is_requestable)
        ");
        $model = $pdo->prepare("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT m.id, 'pill_dispenser', ?, 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ");

        foreach (self::ACTIONS as $key => $label) {
            $capability->execute([$key, $label]);
            $model->execute([$key]);
        }
    }
}
