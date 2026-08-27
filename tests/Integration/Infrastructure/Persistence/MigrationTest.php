<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Infrastructure\Persistence\DashboardDatabase;
use Hub\Infrastructure\Persistence\DatabaseSchemaGuard;
use Hub\Infrastructure\Persistence\Migration\Version2026080502RemoveWeatherCapability;
use Hub\Infrastructure\Persistence\Migration\Version2026080503NormalizeCapabilityLabelsPtPt;
use Hub\Infrastructure\Persistence\Migration\Version2026080702EnableMkgw4GatewayCapabilities;
use Tests\Support\MysqlDashboardTestCase;

final class MigrationTest extends MysqlDashboardTestCase
{
    public function testDatabaseConnectionDoesNotApplySchemaOrMigrations(): void
    {
        $databaseName = $this->createEmptyDatabase();
        $database = new DashboardDatabase($this->dashboardDatabaseConfig($databaseName));

        self::assertSame([], $database->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('php bin/migrate.php');
        (new DatabaseSchemaGuard($database->pdo()))->assertCurrent();
    }

    public function testApplicationDatabaseSessionUsesUtc(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        self::assertSame('+00:00', $pdo->query('SELECT @@session.time_zone')->fetchColumn());
        self::assertSame(
            $pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn(),
            $pdo->query('SELECT NOW()')->fetchColumn(),
        );
    }

    public function testWeatherRemovalMigrationCleansCatalogConfigurationsAndLifecycle(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("
            INSERT INTO capabilities (
                device_type, section, capability_key, label, is_configurable
            ) VALUES ('watch', 'settings_system', 'weather_data', 'Weather', 1)
        ");
        $capabilityId = (int)$pdo->lastInsertId();
        $modelId = (int)$pdo->query("SELECT id FROM models WHERE device_type = 'watch' LIMIT 1")->fetchColumn();
        $pdo->exec("INSERT INTO model_capabilities (model_id, capability_id) VALUES ({$modelId}, {$capabilityId})");
        $pdo->exec("
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, command, desired_payload, reported_payload
            ) VALUES ('weather-test', 'weather_data', 'weatherData', 'wonlex-json', 'dnWeather', '{}', '{}')
        ");
        $pdo->exec("
            INSERT INTO device_configuration_changes (
                change_id, imei, config_key, desired_revision, desired_payload, sync_status, created_at, updated_at
            ) VALUES ('weather-change', 'weather-test', 'weather_data', 1, '{}', 'pending_delivery', 'now', 'now')
        ");
        $pdo->exec("
            INSERT INTO device_configuration_operations (
                operation_id, change_id, imei, config_key, native_key, native_type,
                protocol, command_bytes, expected_reply_types, created_at, updated_at
            ) VALUES (
                'weather-operation', 'weather-change', 'weather-test', 'weather_data',
                'weatherData', 'dnWeather', 'wonlex-json', '{}', '[]', 'now', 'now'
            )
        ");

        (new Version2026080502RemoveWeatherCapability())->up($pdo);

        foreach (['capabilities', 'model_capabilities', 'device_configurations',
            'device_configuration_changes', 'device_configuration_operations'] as $table) {
            self::assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE " . match ($table) {
                'capabilities' => "capability_key = 'weather_data'",
                'model_capabilities' => "capability_id = {$capabilityId}",
                'device_configuration_operations' => "operation_id = 'weather-operation'",
                default => "config_key = 'weather_data'",
            })->fetchColumn(), $table);
        }
    }

    public function testCapabilityLabelMigrationSynchronizesPortugueseCatalogLabels(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("UPDATE capabilities SET label = 'Heart rate' WHERE device_type = 'watch' AND capability_key = 'heart_rate'");
        $pdo->exec("UPDATE capabilities SET label = 'Positions' WHERE device_type = 'radar' AND capability_key = 'positions'");

        (new Version2026080503NormalizeCapabilityLabelsPtPt())->up($pdo);

        $labels = $pdo->query("
            SELECT CONCAT(device_type, ':', capability_key), label
            FROM capabilities
        ")->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame('Frequência cardíaca', $labels['watch:heart_rate'] ?? null);
        self::assertSame('Presença', $labels['radar:presence'] ?? null);
        self::assertSame('Chamada de ajuda', $labels['ncs:pager_call'] ?? null);
    }

    public function testMkgw4CapabilityMigrationEnablesRowsThatWereAlreadyDisabled(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $select = "
            SELECT c.capability_key, mc.enabled
            FROM model_capabilities mc
            JOIN models m ON m.id = mc.model_id AND m.internal_model = 'MKGW4'
            JOIN suppliers s ON s.id = m.supplier_id AND s.name = 'MOKO'
            JOIN capabilities c ON c.id = mc.capability_id AND c.device_type = 'gateway'
            ORDER BY c.capability_key
        ";

        // Reproduce the production state: the rows exist but are disabled, which
        // is exactly the case the original INSERT IGNORE could not repair.
        $pdo->exec("
            UPDATE model_capabilities mc
            JOIN models m ON m.id = mc.model_id AND m.internal_model = 'MKGW4'
            JOIN suppliers s ON s.id = m.supplier_id AND s.name = 'MOKO'
            SET mc.enabled = 0
        ");
        self::assertSame(
            ['battery' => 0, 'connectivity' => 0, 'location' => 0],
            array_map('intval', $pdo->query($select)->fetchAll(\PDO::FETCH_KEY_PAIR)),
        );

        (new Version2026080702EnableMkgw4GatewayCapabilities())->up($pdo);

        self::assertSame(
            ['battery' => 1, 'connectivity' => 1, 'location' => 1],
            array_map('intval', $pdo->query($select)->fetchAll(\PDO::FETCH_KEY_PAIR)),
        );
    }

    public function testMkgw3KeepsOnlyConnectivityAfterTheMkgw4Repair(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        (new Version2026080702EnableMkgw4GatewayCapabilities())->up($pdo);

        // MKGW3 is PoE powered with no GPS, so the repair must not spill over.
        $rows = $pdo->query("
            SELECT c.capability_key, mc.enabled
            FROM model_capabilities mc
            JOIN models m ON m.id = mc.model_id AND m.internal_model = 'MKGW3'
            JOIN suppliers s ON s.id = m.supplier_id AND s.name = 'MOKO'
            JOIN capabilities c ON c.id = mc.capability_id AND c.device_type = 'gateway'
            ORDER BY c.capability_key
        ")->fetchAll(\PDO::FETCH_KEY_PAIR);

        self::assertSame(['connectivity' => 1], array_map('intval', $rows));
    }

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
            '2026072902_enum_capability_sections',
            '2026072903_restrict_hw20pro_health_requests',
            '2026072904_remove_unsupported_wonlex_reports',
            '2026072905_normalize_contact_capabilities',
            '2026073001_rename_four_p_touch_whitelist_switch',
            '2026073002_canonicalize_four_p_touch_contact_slots',
            '2026073003_configuration_lifecycle',
            '2026073101_private_radio_map',
            '2026080301_enable_wonlex_push_message',
            '2026080501_scope_api_users_by_license',
            '2026080502_remove_weather_capability',
            '2026080503_normalize_capability_labels_pt_pt',
            '2026080601_gateway_diaper_devices',
            '2026080602_diaper_telemetry_sections',
            '2026080701_add_mkgw4_gateway',
            '2026080702_enable_mkgw4_gateway_capabilities',
            '2026081001_bracelet_devices',
            '2026081002_unify_help_call_label',
            '2026081101_migrate_vivistar_phonebook_rows',
            '2026081102_backfill_missing_model_capabilities',
            '2026081401_diaper_moisture_level_capability',
            '2026081901_remove_bracelet_motion_capability',
            '2026081902_restore_bracelet_motion_capability',
            '2026082101_diaper_sensor_settings',
            '2026082601_drop_supplier_enabled',
            '2026082701_drop_supplier_device_type_enabled',
            '2026082801_diaper_sensitivity_as_capability',
            '2026082802_drop_diaper_sensor_settings',
            '2026082803_add_notification_license',
            '2026082804_radar_capability_vocabulary',
            '2026082805_drop_diaper_sensor_settings_again',
        ], $versions);

        $this->reopenDashboardDatabase($this->databaseName($pdo));
        self::assertSame(39, (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        self::assertSame(
            0,
            (int)$pdo->query("SELECT COUNT(*) FROM capabilities WHERE capability_key = 'weather_data'")->fetchColumn()
        );
        $sectionType = $pdo->query("
            SELECT COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'capabilities'
              AND COLUMN_NAME = 'section'
        ")->fetchColumn();
        self::assertSame(
            "enum('telemetry','health','contacts','alarms','settings_system')",
            strtolower((string)$sectionType)
        );
    }

    public function testGatewayDiaperMigrationSeedsCatalogAndLinkTable(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $db = ApiDataAccess::fromDatabase($database);

        $gatewayModel = $db->models->find('MOKO', 'MKGW3');
        $cellularGatewayModel = $db->models->find('MOKO', 'MKGW4');
        $sensorModel = $db->models->find('MONIT', 'MECS-PRO');
        self::assertIsArray($gatewayModel);
        self::assertIsArray($cellularGatewayModel);
        self::assertIsArray($sensorModel);
        self::assertSame(['connectivity'], $db->modelCapabilities->enabledFeaturesForModelId((int)$gatewayModel['id']));
        self::assertSame(
            ['battery', 'connectivity', 'location'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$cellularGatewayModel['id'])
        );
        self::assertSame(
            ['battery', 'change_required', 'diaper_condition', 'diaper_moisture', 'diaper_moisture_level', 'diaper_sensitivity'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$sensorModel['id'])
        );
        self::assertContains('gateway_device_links', $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(
            ['battery', 'connectivity', 'location'],
            array_values(array_unique(array_map('strval', $pdo->query("SELECT capability_key FROM capabilities WHERE device_type = 'gateway' ORDER BY capability_key")->fetchAll(\PDO::FETCH_COLUMN))))
        );
        self::assertSame(
            ['battery', 'change_required', 'diaper_condition', 'diaper_moisture', 'diaper_moisture_level', 'diaper_sensitivity'],
            array_values(array_unique(array_map('strval', $pdo->query("SELECT capability_key FROM capabilities WHERE device_type = 'diaper_sensor' ORDER BY capability_key")->fetchAll(\PDO::FETCH_COLUMN))))
        );
        self::assertSame(
            ['diaper_condition', 'diaper_moisture', 'diaper_moisture_level'],
            $pdo->query("SELECT capability_key FROM capabilities WHERE device_type = 'diaper_sensor' AND section = 'telemetry' AND capability_key LIKE 'diaper_%' ORDER BY capability_key")->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    public function testHw20ProHealthRequestabilityMatchesVerifiedFirmwareSupport(): void
    {
        $database = $this->createDashboardDatabase();
        $db = ApiDataAccess::fromDatabase($database);
        $model = $db->models->find('Wonlex', 'HW20PRO');

        self::assertIsArray($model);
        $supported = $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id']);
        $requestable = $db->modelCapabilities->requestableFeaturesForModelId((int)$model['id']);

        foreach (['heart_rate', 'temperature', 'breath_rate', 'ecg', 'hrv', 'ppg', 'rr_interval'] as $feature) {
            self::assertContains($feature, $supported);
            self::assertNotContains($feature, $requestable);
        }
        foreach (['blood_pressure', 'blood_oxygen', 'location'] as $feature) {
            self::assertContains($feature, $supported);
            self::assertContains($feature, $requestable);
        }
        self::assertContains('push_message', $supported);
        self::assertContains('push_message', $requestable);
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
        self::assertArrayNotHasKey('call_log', $rows);
        self::assertArrayNotHasKey('sms', $rows);
        self::assertArrayNotHasKey('ecg_analysis', $rows);
        self::assertSame('settings_system', $rows['device_state']['section'] ?? null);
        self::assertArrayNotHasKey('call_in_restriction', $rows);
        self::assertSame('contacts', $rows['whitelist_enabled']['section'] ?? null);
        self::assertSame('Alerta de remoção do relógio', $rows['remove_watch_alarm']['label'] ?? null);
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

    public function testFourPTouchContactSlotMigrationKeepsNewestCanonicalRow(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $insert = $pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, supplier, model, command,
                desired_payload, reported_payload, last_status, last_command_id,
                desired_updated_at, reported_at, applied_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $insert->execute([
            '868017032159118', 'sos_contacts', 'sosNumber1', 'four-p-touch', '4P Touch', 'D46', 'SOS1',
            '{"phone":"123456789"}', '{}', 'acked', 'old', '2026-07-01T08:00:00Z', '', '2026-07-01T08:00:01Z',
        ]);
        $insert->execute([
            '868017032159118', 'sosNumber1', 'sosNumber1', 'four-p-touch', '4P Touch', 'D46', 'SOS1',
            '{"phone":""}', '{}', 'acked', 'new', '2026-07-02T08:00:00Z', '', '2026-07-02T08:00:01Z',
        ]);
        $pdo->exec("
            DELETE FROM schema_migrations
            WHERE version = '2026073002_canonicalize_four_p_touch_contact_slots'
        ");

        $this->reopenDashboardDatabase($this->databaseName($pdo));
        $rows = $pdo->query("
            SELECT config_key, native_key, desired_payload, last_command_id
            FROM device_configurations
            WHERE imei = '868017032159118'
              AND native_key = 'sosNumber1'
        ")->fetchAll();

        self::assertSame([[
            'config_key' => 'sos_contacts',
            'native_key' => 'sosNumber1',
            'desired_payload' => '{"phone":""}',
            'last_command_id' => 'new',
        ]], $rows);
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
            'private_radio_map_access_points',
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

}
