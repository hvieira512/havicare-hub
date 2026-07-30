<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class Version2026073003ConfigurationLifecycle implements Migration
{
    public function version(): string
    {
        return '2026073003_configuration_lifecycle';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE device_configurations MODIFY last_status VARCHAR(32) NOT NULL DEFAULT ''");
        foreach ([
            'desired_revision' => "BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER reported_payload",
            'confirmed_revision' => "BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER desired_revision",
            'current_change_id' => "VARCHAR(64) NOT NULL DEFAULT '' AFTER confirmed_revision",
            'confirmation_mode' => "VARCHAR(32) NOT NULL DEFAULT 'execution_ack' AFTER current_change_id",
            'last_error' => "VARCHAR(64) NOT NULL DEFAULT '' AFTER last_status",
        ] as $column => $definition) {
            if (!$this->hasColumn($pdo, 'device_configurations', $column)) {
                $pdo->exec("ALTER TABLE device_configurations ADD COLUMN {$column} {$definition}");
            }
        }
        if (!$this->hasIndex($pdo, 'device_configurations', 'idx_device_config_current_change')) {
            $pdo->exec('ALTER TABLE device_configurations ADD KEY idx_device_config_current_change (imei, current_change_id)');
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS device_configuration_changes (
                change_id VARCHAR(64) NOT NULL PRIMARY KEY,
                imei VARCHAR(64) NOT NULL,
                config_key VARCHAR(191) NOT NULL,
                desired_revision BIGINT UNSIGNED NOT NULL,
                desired_payload LONGTEXT NOT NULL,
                effective_payload LONGTEXT NULL,
                sync_status VARCHAR(32) NOT NULL DEFAULT 'pending_delivery',
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                confirmed_at VARCHAR(32) NOT NULL DEFAULT '',
                superseded_at VARCHAR(32) NOT NULL DEFAULT '',
                UNIQUE KEY uq_configuration_change_revision (imei, config_key, desired_revision),
                KEY idx_configuration_change_current (imei, config_key, superseded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS device_configuration_operations (
                operation_id VARCHAR(64) NOT NULL PRIMARY KEY,
                change_id VARCHAR(64) NOT NULL,
                imei VARCHAR(64) NOT NULL,
                config_key VARCHAR(191) NOT NULL,
                native_key VARCHAR(191) NOT NULL,
                native_type VARCHAR(191) NOT NULL,
                protocol VARCHAR(64) NOT NULL,
                command_bytes LONGTEXT NOT NULL,
                expected_reply_types LONGTEXT NOT NULL,
                confirmation_mode VARCHAR(32) NOT NULL DEFAULT 'execution_ack',
                delivery_status VARCHAR(32) NOT NULL DEFAULT 'created',
                error_code VARCHAR(64) NOT NULL DEFAULT '',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
                retry_delay_seconds INT UNSIGNED NOT NULL DEFAULT 60,
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                sent_at VARCHAR(32) NOT NULL DEFAULT '',
                acknowledged_at VARCHAR(32) NOT NULL DEFAULT '',
                sequence_number INT UNSIGNED NOT NULL DEFAULT 0,
                KEY idx_configuration_operation_change (change_id, sequence_number),
                KEY idx_configuration_operation_dispatch (delivery_status, updated_at),
                CONSTRAINT fk_configuration_operation_change
                    FOREIGN KEY (change_id) REFERENCES device_configuration_changes(change_id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ');
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasIndex(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
        ');
        $stmt->execute([$table, $index]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
