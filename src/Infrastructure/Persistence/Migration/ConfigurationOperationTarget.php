<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A quem uma operação se refere, quando uma configuração se entrega em várias: o utilizador da
 * balança que cada registo ou remoção escreve.
 */
final class ConfigurationOperationTarget implements Migration
{
    public function version(): string
    {
        return '2026_10_15_configuration_operation_target';
    }

    public function up(PDO $pdo): void
    {
        $exists = $pdo->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_configuration_operations' AND COLUMN_NAME = 'target'
        ")->fetchColumn();
        if ((int)$exists > 0) {
            return;
        }

        $pdo->exec("ALTER TABLE device_configuration_operations ADD COLUMN target VARCHAR(64) NOT NULL DEFAULT '' AFTER native_type");
    }
}
