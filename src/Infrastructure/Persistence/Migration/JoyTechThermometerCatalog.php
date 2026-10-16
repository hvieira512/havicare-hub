<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * O termómetro de testa JoyTech HC-3018B, nas bases que já existiam. O tipo entra aqui porque o
 * modelo o refere; as capacidades liga-as o semeador a seguir.
 */
final class JoyTechThermometerCatalog implements Migration
{
    public function version(): string
    {
        return '2026_10_16_joytech_thermometer_catalog';
    }

    public function up(PDO $pdo): void
    {
        // Numa base por semear, o semeador escreve o mesmo a partir do código.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("INSERT IGNORE INTO device_types (device_type) VALUES ('thermometer')");
        $pdo->exec("INSERT IGNORE INTO suppliers (name) VALUES ('JoyTech')");
        $pdo->exec("
            INSERT IGNORE INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            SELECT id, 'HC-3018B', 'HC-3018B', 'thermometer', '82f686a2fa076ea0bebc7e7cb7a9d676.jpg'
            FROM suppliers WHERE name = 'JoyTech'
        ");
    }
}
