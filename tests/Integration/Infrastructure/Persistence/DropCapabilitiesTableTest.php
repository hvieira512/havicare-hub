<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\DatabaseSchemaGuard;
use Hub\Infrastructure\Persistence\Migration\DropCapabilitiesTable;
use Tests\Support\MysqlDashboardTestCase;

/** O catálogo de capacidades vive só no código, e a `model_capabilities` aponta para ele. */
final class DropCapabilitiesTableTest extends MysqlDashboardTestCase
{
    public function testAFreshDatabaseHasNoCapabilitiesTable(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        self::assertFalse($pdo->query("SHOW TABLES LIKE 'capabilities'")->fetchColumn());
    }

    public function testAnOlderDatabaseLosesTheTableAndTheForeignKey(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("
            CREATE TABLE capabilities (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                device_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                capability_key VARCHAR(64) NOT NULL,
                label VARCHAR(96) NOT NULL,
                UNIQUE KEY uq_capabilities_device_type_key (device_type, capability_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            INSERT INTO capabilities (device_type, capability_key, label)
            SELECT DISTINCT device_type, capability_key, capability_key FROM model_capabilities
        ");
        $pdo->exec('ALTER TABLE model_capabilities DROP FOREIGN KEY fk_model_capabilities_device_type');
        $pdo->exec('
            ALTER TABLE model_capabilities
                ADD CONSTRAINT fk_model_capabilities_capability_v3 FOREIGN KEY (device_type, capability_key)
                    REFERENCES capabilities(device_type, capability_key) ON DELETE CASCADE ON UPDATE CASCADE
        ');
        $links = (int)$pdo->query('SELECT COUNT(*) FROM model_capabilities')->fetchColumn();

        (new DropCapabilitiesTable())->up($pdo);
        (new DropCapabilitiesTable())->up($pdo);

        self::assertFalse($pdo->query("SHOW TABLES LIKE 'capabilities'")->fetchColumn());
        self::assertSame('device_types', $pdo->query("
            SELECT GROUP_CONCAT(DISTINCT REFERENCED_TABLE_NAME) FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'model_capabilities' AND COLUMN_NAME = 'device_type'
        ")->fetchColumn());
        self::assertSame($links, (int)$pdo->query('SELECT COUNT(*) FROM model_capabilities')->fetchColumn());
    }

    public function testTheGuardRefusesAModelCapabilityTheCatalogueDoesNotDeclare(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $modelId = (int)$pdo->query("
            SELECT m.id FROM models m JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ")->fetchColumn();
        $pdo->exec("
            INSERT INTO model_capabilities (model_id, device_type, capability_key, enabled)
            VALUES ({$modelId}, 'pill_dispenser', 'invented', 1)
        ");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pill_dispenser:invented');
        (new DatabaseSchemaGuard($pdo))->assertCurrent();
    }
}
