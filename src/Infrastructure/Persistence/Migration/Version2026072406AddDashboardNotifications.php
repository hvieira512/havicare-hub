<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class Version2026072406AddDashboardNotifications implements Migration
{
    public function version(): string
    {
        return '2026072406_add_dashboard_notifications';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS dashboard_notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(64) NOT NULL,
                imei VARCHAR(64) NOT NULL,
                protocol VARCHAR(64) NOT NULL DEFAULT \'\',
                model VARCHAR(191) NOT NULL DEFAULT \'\',
                ident VARCHAR(191) NOT NULL DEFAULT \'\',
                reason VARCHAR(191) NOT NULL DEFAULT \'\',
                occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME NULL DEFAULT NULL,
                UNIQUE KEY uq_dashboard_notifications_identity (type, imei, protocol),
                KEY idx_dashboard_notifications_unread (read_at, last_seen_at),
                KEY idx_dashboard_notifications_latest (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}
