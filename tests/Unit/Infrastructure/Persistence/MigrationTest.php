<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Persistence;

use Hub\Api\Repository\ApiDataAccess;
use Tests\Support\MysqlDashboardTestCase;

final class MigrationTest extends MysqlDashboardTestCase
{
    public function testAllMigrationsAreRecordedAndDoNotRerun(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $versions = $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame([
            '2026072401_upgrade_legacy_schema',
            '2026072402_seed_reference_catalog',
            '2026072403_rebuild_model_capabilities',
            '2026072404_seed_model_capabilities',
            '2026072405_normalize_configuration_keys',
            '2026072406_add_dashboard_notifications',
            '2026072801_sync_wonlex_adult_health_capabilities',
            '2026072901_clean_watch_capability_taxonomy',
        ], $versions);

        $this->reopenDashboardDatabase($this->databaseName($pdo));
        self::assertSame(8, (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    }

    public function testWatchCapabilityTaxonomyMigrationMovesActionsAndRemovesInternalSyncEntries(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $rows = $pdo->query("
            SELECT capability_key, section, label, is_configurable, is_requestable
            FROM capabilities
            WHERE device_type = 'watch'
        ")->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC);

        self::assertArrayNotHasKey('device_binding', $rows);
        self::assertArrayNotHasKey('device_settings_sync', $rows);
        self::assertSame('contacts', $rows['call_in_restriction']['section'] ?? null);
        self::assertSame('Wrist removal alert', $rows['remove_watch_alarm']['label'] ?? null);
        self::assertSame(0, (int)($rows['push_message']['is_configurable'] ?? -1));
        self::assertSame(1, (int)($rows['push_message']['is_requestable'] ?? -1));
        self::assertSame(0, (int)($rows['make_call']['is_configurable'] ?? -1));
        self::assertSame(1, (int)($rows['make_call']['is_requestable'] ?? -1));
    }

    public function testLegacyModelCapabilityFeaturesMigrateToCapabilityIds(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $db = ApiDataAccess::fromDatabase($database);
        $model = $db->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DROP TABLE model_capabilities');
        $pdo->exec('
            CREATE TABLE model_capabilities (
                model_id BIGINT UNSIGNED NOT NULL,
                feature VARCHAR(191) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at VARCHAR(32) NOT NULL DEFAULT \'\',
                updated_at VARCHAR(32) NOT NULL DEFAULT \'\',
                PRIMARY KEY (model_id, feature)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        $stmt = $pdo->prepare('
            INSERT INTO model_capabilities (model_id, feature, enabled)
            VALUES (?, ?, 1)
        ');
        $stmt->execute([(int)$model['id'], 'heart_rate']);
        $pdo->exec("DELETE FROM schema_migrations WHERE version = '2026072403_rebuild_model_capabilities'");
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $migrated = $this->reopenDashboardDatabase($this->databaseName($pdo));
        $migratedDb = ApiDataAccess::fromDatabase($migrated);
        self::assertContains(
            'heart_rate',
            $migratedDb->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );
        self::assertFalse((new \Hub\Infrastructure\Persistence\Migration\MysqlSchema($pdo))->hasColumn(
            'model_capabilities',
            'feature'
        ));
    }

    public function testStoredNativeConfigurationKeysMigrateToGenericIdentity(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();

        $pdo->exec('ALTER TABLE device_configurations DROP PRIMARY KEY');
        $pdo->exec('ALTER TABLE device_configurations DROP COLUMN native_key');
        $pdo->exec('ALTER TABLE device_configurations ADD PRIMARY KEY (imei, config_key)');
        $insert = $pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, protocol, supplier, model, command,
                desired_payload, reported_payload
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $insert->execute([
            '861265061009822',
            'fallDetection',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP76',
            '{"enabled":true}',
            '{}',
        ]);
        $insert->execute([
            '868017032159118',
            'alarmClock',
            'four-p-touch',
            '4P Touch',
            'D46',
            'REMIND',
            '{"alarms":[]}',
            '{}',
        ]);
        $insert->execute([
            '861265061009823',
            'alarm_clock',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP85',
            '{"items":[{"time":"08:10"}]}',
            '{}',
        ]);
        $insert->execute([
            '861265061009823',
            'reminders',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP85',
            '{"items":[{"time":"10:30"}]}',
            '{}',
        ]);
        $insert->execute([
            '868017032159119',
            'alarm_clock',
            'four-p-touch',
            '4P Touch',
            'D46',
            'REMIND',
            '{"items":[{"time":"09:15"}]}',
            '{}',
        ]);
        $pdo->exec("
            UPDATE device_configurations
            SET desired_updated_at = CASE
                WHEN config_key = 'alarm_clock' THEN '2026-07-01T08:00:00Z'
                WHEN config_key = 'reminders' THEN '2026-07-02T08:00:00Z'
                ELSE desired_updated_at
            END
            WHERE imei = '861265061009823'
        ");
        $pdo->exec("DELETE FROM schema_migrations WHERE version = '2026072405_normalize_configuration_keys'");

        $this->reopenDashboardDatabase($this->databaseName($pdo));
        $rows = $pdo->query('
            SELECT config_key, native_key
            FROM device_configurations
            ORDER BY imei
        ')->fetchAll();

        self::assertSame([
            ['config_key' => 'fall_detection', 'native_key' => 'fallDetection'],
            ['config_key' => 'alarm_clock', 'native_key' => 'reminders'],
            ['config_key' => 'alarm_clock', 'native_key' => 'alarmClock'],
            ['config_key' => 'alarm_clock', 'native_key' => 'alarmClock'],
        ], $rows);
        self::assertSame(
            '{"items":[{"time":"10:30"}]}',
            $pdo->query("
                SELECT desired_payload
                FROM device_configurations
                WHERE imei = '861265061009823'
                  AND config_key = 'alarm_clock'
                  AND native_key = 'reminders'
            ")->fetchColumn()
        );
        self::assertSame(
            ['imei', 'config_key', 'native_key'],
            $this->primaryKeyColumns($pdo, 'device_configurations')
        );
    }

    public function testMigratedLegacySchemaMatchesFreshSchemaStructure(): void
    {
        $fresh = $this->createDashboardDatabase()->pdo();
        $legacyName = $this->createEmptyDatabase();
        $legacyPdo = $this->pdoForDatabase($legacyName);
        $fixture = file_get_contents(__DIR__ . '/../../../Fixtures/database/legacy_schema.sql');
        self::assertIsString($fixture);
        $legacyPdo->exec($fixture);

        $migrated = $this->reopenDashboardDatabase($legacyName)->pdo();
        foreach ([
            'suppliers',
            'models',
            'supplier_device_types',
            'capabilities',
            'model_capabilities',
            'whitelist',
            'device_configurations',
            'api_users',
            'companies',
            'licenses',
        ] as $table) {
            self::assertSame(
                $this->columnStructure($fresh, $table),
                $this->columnStructure($migrated, $table),
                "Column structure differs for {$table}"
            );
            self::assertSame(
                $this->indexStructure($fresh, $table),
                $this->indexStructure($migrated, $table),
                "Index structure differs for {$table}"
            );
            self::assertSame(
                $this->foreignKeyStructure($fresh, $table),
                $this->foreignKeyStructure($migrated, $table),
                "Foreign key structure differs for {$table}"
            );
        }
    }

    /**
     * @return list<string>
     */
    private function primaryKeyColumns(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('
            SELECT COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND CONSTRAINT_NAME = \'PRIMARY\'
            ORDER BY ORDINAL_POSITION
        ');
        $stmt->execute([$table]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function columnStructure(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('
            SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ');
        $stmt->execute([$table]);
        return $stmt->fetchAll();
    }

    private function indexStructure(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('
            SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ');
        $stmt->execute([$table]);
        $indexes = [];
        foreach ($stmt->fetchAll() as $row) {
            $name = (string)$row['INDEX_NAME'];
            $indexes[$name]['unique'] = (int)$row['NON_UNIQUE'] === 0;
            $indexes[$name]['columns'][] = (string)$row['COLUMN_NAME'];
        }

        $normalized = [];
        foreach ($indexes as $name => $index) {
            $key = $name === 'PRIMARY'
                ? 'PRIMARY'
                : (($index['unique'] ? 'UNIQUE:' : 'INDEX:') . implode(',', $index['columns']));
            $normalized[$key] = $index;
        }
        ksort($normalized);
        return $normalized;
    }

    private function foreignKeyStructure(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('
            SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        ');
        $stmt->execute([$table]);
        return $stmt->fetchAll();
    }
}
