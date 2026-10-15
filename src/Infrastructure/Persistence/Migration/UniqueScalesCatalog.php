<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A balança Unique Scales e o gateway BLE da Havicare, nas bases que já existiam. O tipo entra
 * aqui porque os modelos o referem; as capacidades liga-as o semeador a seguir.
 */
final class UniqueScalesCatalog implements Migration
{
    public function version(): string
    {
        return '2026_10_15_unique_scales_catalog';
    }

    public function up(PDO $pdo): void
    {
        // Numa base por semear, o semeador escreve o mesmo a partir do código.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("INSERT IGNORE INTO device_types (device_type) VALUES ('scale')");
        $pdo->exec("INSERT IGNORE INTO suppliers (name) VALUES ('Unique Scales'), ('Havicare')");
        $pdo->exec("
            INSERT IGNORE INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            SELECT id, model.internal, model.internal, 'scale', ''
            FROM suppliers
            JOIN (SELECT 'HC-CF632BLE' AS internal UNION ALL SELECT 'HC-CF621BLE') model
            WHERE name = 'Unique Scales'
        ");
        $pdo->exec("
            INSERT IGNORE INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            SELECT id, 'BLE Gateway', 'Gateway BLE', 'gateway', ''
            FROM suppliers WHERE name = 'Havicare'
        ");
    }
}
