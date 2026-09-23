<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A tampa e o ambiente de armazenamento saem do estado do dispositivo.
 *
 * O `device_status` tinha virado uma gaveta, e na lista de eventos saía tudo numa linha só.
 * Cada uma foi para onde alguém a procura: a corrente juntou-se à bateria, que é a mesma
 * pergunta feita de dois lados, e a tampa e o ambiente ganham capacidade própria.
 */
final class PillDispenserLidAndEnvironment implements Migration
{
    private const ADDED = [
        ['lid_state', 'Tampa'],
        ['storage_environment', 'Ambiente de armazenamento'],
    ];

    public function version(): string
    {
        return '2026_09_23_pill_dispenser_lid_and_environment';
    }

    public function up(PDO $pdo): void
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $capability = $pdo->prepare("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('pill_dispenser', 'telemetry', ?, ?, 0, 0)
            ON DUPLICATE KEY UPDATE section = VALUES(section), label = VALUES(label)
        ");
        $model = $pdo->prepare("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT m.id, 'pill_dispenser', ?, 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ");

        foreach (self::ADDED as [$key, $label]) {
            $capability->execute([$key, $label]);
            $model->execute([$key]);
        }
    }
}
