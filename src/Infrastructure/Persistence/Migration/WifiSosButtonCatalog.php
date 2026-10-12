<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Põe a pulseira SOS Wi-Fi da PST no catálogo das bases que já existiam; as capacidades do
 * modelo liga-as o semeador a seguir, pelo protocolo.
 */
final class WifiSosButtonCatalog implements Migration
{
    public function version(): string
    {
        return '2026_10_12_wifi_sos_button_catalog';
    }

    public function up(PDO $pdo): void
    {
        // Numa base por semear, o semeador escreve o mesmo a partir do código.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->exec("INSERT IGNORE INTO suppliers (name) VALUES ('PST')");
        $pdo->exec("
            INSERT IGNORE INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            SELECT id, 'CF103W', 'CF103W', 'bracelet', ''
            FROM suppliers WHERE name = 'PST'
        ");
    }
}
