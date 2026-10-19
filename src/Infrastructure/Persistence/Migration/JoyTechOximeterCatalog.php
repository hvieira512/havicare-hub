<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * O oxímetro de dedo JoyTech HC-101, nas bases que já existiam. O tipo entra aqui porque o modelo
 * o refere; as capacidades liga-as o semeador a seguir.
 */
final class JoyTechOximeterCatalog implements Migration
{
    public function version(): string
    {
        return '2026_10_19_joytech_oximeter_catalog';
    }

    public function up(PDO $pdo): void
    {
        // Numa base por semear, o semeador escreve o mesmo a partir do código.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("INSERT IGNORE INTO device_types (device_type) VALUES ('pulse_oximeter')");
        $pdo->exec("INSERT IGNORE INTO suppliers (name) VALUES ('JoyTech')");
        $pdo->exec("
            INSERT IGNORE INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            SELECT id, 'HC-101', 'HC-101', 'pulse_oximeter', '905f5c5c9c5d86b12794db17a4aa682b.jpg'
            FROM suppliers WHERE name = 'JoyTech'
        ");
    }
}
