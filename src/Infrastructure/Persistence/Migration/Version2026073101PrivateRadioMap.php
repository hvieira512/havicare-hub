<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class Version2026073101PrivateRadioMap implements Migration
{
    public function version(): string
    {
        return '2026073101_private_radio_map';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS private_radio_map_access_points (
                bssid_hash CHAR(64) NOT NULL PRIMARY KEY,
                latitude DECIMAL(10,7) NOT NULL,
                longitude DECIMAL(10,7) NOT NULL,
                accuracy_meters DECIMAL(8,2) NOT NULL,
                observation_count INT UNSIGNED NOT NULL DEFAULT 1,
                source ENUM('learned', 'manual') NOT NULL DEFAULT 'learned',
                conflicted TINYINT(1) NOT NULL DEFAULT 0,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_private_radio_map_usable (conflicted, last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
