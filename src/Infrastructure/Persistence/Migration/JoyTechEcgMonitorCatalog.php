<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/** O tensiómetro com ECG JoyTech HC-6679B, nas bases que já existiam. */
final class JoyTechEcgMonitorCatalog implements Migration
{
    public function version(): string
    {
        return '2026_10_16_joytech_ecg_monitor_catalog';
    }

    public function up(PDO $pdo): void
    {
        // Numa base por semear, o semeador escreve o mesmo a partir do código.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("INSERT IGNORE INTO suppliers (name) VALUES ('JoyTech')");
        $pdo->exec("
            INSERT IGNORE INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            SELECT id, 'HC-6679B', 'HC-6679B', 'blood_pressure_monitor', '596253ec02242209fd77291087cc18f6.jpg'
            FROM suppliers WHERE name = 'JoyTech'
        ");
    }
}
