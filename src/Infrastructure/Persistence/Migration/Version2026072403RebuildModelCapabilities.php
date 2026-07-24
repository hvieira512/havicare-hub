<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

final class Version2026072403RebuildModelCapabilities implements Migration
{
    public function version(): string
    {
        return '2026072403_rebuild_model_capabilities';
    }

    public function up(PDO $pdo): void
    {
        $schema = new MysqlSchema($pdo);
        if (
            !$schema->hasColumn('model_capabilities', 'feature')
            && !$schema->hasColumn('model_capabilities', 'capability_key')
            && $schema->hasColumn('model_capabilities', 'capability_id')
        ) {
            return;
        }

        $pdo->exec('DROP TABLE IF EXISTS model_capabilities_v2');
        $pdo->exec('
            CREATE TABLE model_capabilities_v2 (
                model_id BIGINT UNSIGNED NOT NULL,
                capability_id BIGINT UNSIGNED NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (model_id, capability_id),
                CONSTRAINT fk_model_capabilities_v2_model
                    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                CONSTRAINT fk_model_capabilities_v2_capability
                    FOREIGN KEY (capability_id) REFERENCES capabilities(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        if ($schema->hasColumn('model_capabilities', 'capability_id')) {
            $pdo->exec('
                INSERT IGNORE INTO model_capabilities_v2 (model_id, capability_id, enabled)
                SELECT mc.model_id, mc.capability_id, mc.enabled
                FROM model_capabilities mc
                JOIN capabilities c ON c.id = mc.capability_id
                WHERE mc.capability_id IS NOT NULL AND mc.capability_id > 0
            ');
        }

        $legacyColumn = $schema->hasColumn('model_capabilities', 'capability_key')
            ? 'capability_key'
            : ($schema->hasColumn('model_capabilities', 'feature') ? 'feature' : null);
        if ($legacyColumn !== null) {
            $pdo->exec("
                INSERT IGNORE INTO model_capabilities_v2 (model_id, capability_id, enabled)
                SELECT mc.model_id, c.id, mc.enabled
                FROM model_capabilities mc
                JOIN models m ON m.id = mc.model_id
                JOIN capabilities c
                    ON c.device_type = m.device_type
                    AND c.capability_key = mc.`{$legacyColumn}`
            ");
        }

        $pdo->exec('DROP TABLE model_capabilities');
        $pdo->exec('RENAME TABLE model_capabilities_v2 TO model_capabilities');
        $schema = new MysqlSchema($pdo);
        $schema->addIndex('model_capabilities', 'idx_model_capabilities_model', 'model_id');
        $schema->addIndex('model_capabilities', 'idx_model_capabilities_capability', 'capability_id');
    }
}
