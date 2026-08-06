<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

final class Version2026080601GatewayDiaperDevices implements Migration
{
    public function version(): string
    {
        return '2026080601_gateway_diaper_devices';
    }

    public function up(PDO $pdo): void
    {
        $type = "ENUM('watch', 'ncs', 'radar', 'gateway', 'diaper_sensor')";
        $pdo->exec("ALTER TABLE models MODIFY device_type {$type} NOT NULL DEFAULT 'watch'");
        $pdo->exec("ALTER TABLE supplier_device_types MODIFY device_type {$type} NOT NULL");
        $pdo->exec("ALTER TABLE capabilities MODIFY device_type {$type} NOT NULL DEFAULT 'watch'");
        $pdo->exec("ALTER TABLE whitelist MODIFY device_type {$type} NOT NULL DEFAULT 'watch'");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS gateway_device_links (
                gateway_device_key VARCHAR(64) NOT NULL,
                linked_device_key VARCHAR(64) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (gateway_device_key, linked_device_key),
                KEY idx_gateway_device_links_linked (linked_device_key, enabled),
                CONSTRAINT fk_gateway_device_links_gateway FOREIGN KEY (gateway_device_key)
                    REFERENCES whitelist(imei) ON DELETE CASCADE,
                CONSTRAINT fk_gateway_device_links_device FOREIGN KEY (linked_device_key)
                    REFERENCES whitelist(imei) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        (new ReferenceCatalogSeeder())->seedReferenceData($pdo);
        (new ReferenceCatalogSeeder())->seedMissingModelCapabilities($pdo);
    }
}
