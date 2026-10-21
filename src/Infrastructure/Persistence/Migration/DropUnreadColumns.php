<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/** Larga as colunas que se escreviam e nenhum código lia. */
final class DropUnreadColumns implements Migration
{
    private const COLUMNS = [
        ['radar_api_credentials', 'access_token'],
        ['radar_api_credentials', 'refresh_token'],
        ['radar_api_credentials', 'token_expires_at'],
        ['device_configuration_operations', 'max_attempts'],
        ['radar_layouts', 'source_payload'],
        ['device_configurations', 'last_error'],
    ];

    public function version(): string
    {
        return '2026_10_21_drop_unread_columns';
    }

    public function up(PDO $pdo): void
    {
        $exists = $pdo->prepare('
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ');
        foreach (self::COLUMNS as [$table, $column]) {
            $exists->execute([$table, $column]);
            if ((int)$exists->fetchColumn() > 0) {
                $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            }
        }
    }
}
