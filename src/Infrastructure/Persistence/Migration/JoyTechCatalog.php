<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * O tensiómetro JoyTech HC-6292B, nas bases que já existiam. O tipo entra aqui porque o modelo o
 * refere; as capacidades liga-as o semeador a seguir.
 */
final class JoyTechCatalog implements Migration
{
    public function version(): string
    {
        return '2026_10_16_joytech_catalog';
    }

    public function up(PDO $pdo): void
    {
        // Numa base por semear, o semeador escreve o mesmo a partir do código.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("INSERT IGNORE INTO device_types (device_type) VALUES ('blood_pressure_monitor')");
        $pdo->exec("INSERT IGNORE INTO suppliers (name) VALUES ('JoyTech')");
        $pdo->exec("
            INSERT IGNORE INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            SELECT id, 'HC-6292B', 'HC-6292B', 'blood_pressure_monitor', 'cbae0ad7a44f97f1d4993e551e03a28b.jpg'
            FROM suppliers WHERE name = 'JoyTech'
        ");
    }
}
