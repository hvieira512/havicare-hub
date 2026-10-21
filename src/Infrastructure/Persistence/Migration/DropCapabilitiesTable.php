<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/** Larga a `capabilities`, que repetia o `CapabilityCatalog`; o tipo da ligação passa a apontar à `device_types`. */
final class DropCapabilitiesTable implements Migration
{
    public function version(): string
    {
        return '2026_10_21_drop_capabilities_table';
    }

    public function up(PDO $pdo): void
    {
        $exists = static fn(string $sql): bool => (int)$pdo->query($sql)->fetchColumn() > 0;

        if (
            $exists("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'model_capabilities'
              AND CONSTRAINT_NAME = 'fk_model_capabilities_capability_v3'
        ")
        ) {
            $pdo->exec('ALTER TABLE model_capabilities DROP FOREIGN KEY fk_model_capabilities_capability_v3');
        }
        if (
            !$exists("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'model_capabilities'
              AND CONSTRAINT_NAME = 'fk_model_capabilities_device_type'
        ")
        ) {
            $pdo->exec('
                ALTER TABLE model_capabilities ADD CONSTRAINT fk_model_capabilities_device_type
                    FOREIGN KEY (device_type) REFERENCES device_types(device_type)
            ');
        }
        $pdo->exec('DROP TABLE IF EXISTS capabilities');
    }
}
