<?php

namespace Tests\Unit\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Domain\SupplierCapabilityTemplate;
use Tests\Support\MysqlDashboardTestCase;

final class DatabaseStoreTest extends MysqlDashboardTestCase
{
    public function testSeedsDefaultModelsWhenSuppliersAlreadyExist(): void
    {
        $database = $this->createDashboardDatabase();
        $db = ApiDataAccess::fromDatabase($database);
        $catalog = $db->genericCapabilities->all('watch');
        self::assertNotEmpty($catalog);
        self::assertSame('watch', $catalog[0]['device_type'] ?? null);
        self::assertSame(6, count($db->models->all()));
        $model = $db->models->find('Vivistar', 'L08 PRO');
        self::assertIsArray($model);
        self::assertSame('L08 Pro', $model['commercial_name'] ?? null);
        self::assertSame('watch', $model['device_type'] ?? null);
        $expected = SupplierCapabilityTemplate::keysForSupplierDeviceType('Vivistar', 'watch');
        $actual = $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id']);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);

        $db = ApiDataAccess::fromDatabase($database);
        self::assertSame(6, count($db->models->all()));
        $model = $db->models->find('Vivistar', 'L08 PRO');
        self::assertIsArray($model);
        self::assertSame('L08 Pro', $model['commercial_name'] ?? null);
        self::assertSame('watch', $model['device_type'] ?? null);
        $expected = SupplierCapabilityTemplate::keysForSupplierDeviceType('Vivistar', 'watch');
        $actual = $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id']);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);

        $qinglanst = $db->models->find('Qinglanst', 'RD-V1');
        self::assertIsArray($qinglanst);
        self::assertSame('radar', $qinglanst['device_type'] ?? null);
        $expectedRadar = ['positions', 'vitals', 'position_minute_stats', 'vitals_minute_stats'];
        $actualRadar = $db->modelCapabilities->enabledFeaturesForModelId((int)$qinglanst['id']);
        sort($expectedRadar);
        sort($actualRadar);
        self::assertSame($expectedRadar, $actualRadar);
    }

    public function testBootstrapDropsLegacyGenericCapabilitiesTable(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $pdo->exec('CREATE TABLE generic_capabilities (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');

        $databaseNameRow = $pdo->query('SELECT DATABASE() AS db_name')->fetch();
        self::assertIsArray($databaseNameRow);
        $databaseName = (string)($databaseNameRow['db_name'] ?? '');
        self::assertNotSame('', $databaseName);

        $normalizedDatabase = new \Hub\Infrastructure\Persistence\DashboardDatabase([
            'driver' => 'mysql',
            'host' => (string)(getenv('TEST_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => (int)(getenv('TEST_DB_PORT') ?: getenv('DB_PORT') ?: 3306),
            'name' => $databaseName,
            'username' => (string)(getenv('TEST_DB_ADMIN_USER') ?: getenv('DB_ROOT_USER') ?: 'root'),
            'password' => (string)(getenv('TEST_DB_ADMIN_PASSWORD') ?: getenv('DB_ROOT_PASSWORD') ?: 'root_pass'),
            'charset' => (string)(getenv('TEST_DB_CHARSET') ?: getenv('DB_CHARSET') ?: 'utf8mb4'),
        ]);

        $check = $normalizedDatabase->pdo()->query("SHOW TABLES LIKE 'generic_capabilities'");
        self::assertFalse((bool)$check->fetchColumn());
    }

    public function testModelCapabilitiesCanBeReplacedPerModel(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $model = $db->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);

        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['blood_pressure', 'heart_rate']);

        self::assertSame(
            ['blood_pressure', 'heart_rate'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );
    }

    public function testVivistarModelCapabilitiesIgnoreWatchOnlyPhonebookRows(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $db = ApiDataAccess::fromDatabase($database);
        $model = $db->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);

        $capabilityStmt = $pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $capabilityStmt->execute(['watch', 'phonebook']);
        $phonebookCapabilityId = (int)($capabilityStmt->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $phonebookCapabilityId);

        $insert = $pdo->prepare('INSERT INTO model_capabilities (model_id, capability_id, enabled) VALUES (?, ?, 1)');
        $insert->execute([(int)$model['id'], $phonebookCapabilityId]);

        self::assertNotContains(
            'phonebook',
            $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );

        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['phonebook']);
        self::assertSame([], $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id']));
    }

    public function testBootstrapNormalizesPersistedModelCapabilitiesByRemovingInvalidEntries(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $db = ApiDataAccess::fromDatabase($database);
        $model = $db->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);

        $capabilityStmt = $pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $capabilityStmt->execute(['watch', 'ecg']);
        $invalidCapabilityId = (int)($capabilityStmt->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $invalidCapabilityId);

        $insert = $pdo->prepare('INSERT INTO model_capabilities (model_id, capability_id, enabled) VALUES (?, ?, 1)');
        $insert->execute([(int)$model['id'], $invalidCapabilityId]);

        $before = $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id']);
        self::assertContains('ecg', $before);
        self::assertContains('heart_rate', $before);

        $databaseNameRow = $pdo->query('SELECT DATABASE() AS db_name')->fetch();
        self::assertIsArray($databaseNameRow);
        $databaseName = (string)($databaseNameRow['db_name'] ?? '');
        self::assertNotSame('', $databaseName);

        $normalizedDatabase = new \Hub\Infrastructure\Persistence\DashboardDatabase([
            'driver' => 'mysql',
            'host' => (string)(getenv('TEST_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => (int)(getenv('TEST_DB_PORT') ?: getenv('DB_PORT') ?: 3306),
            'name' => $databaseName,
            'username' => (string)(getenv('TEST_DB_ADMIN_USER') ?: getenv('DB_ROOT_USER') ?: 'root'),
            'password' => (string)(getenv('TEST_DB_ADMIN_PASSWORD') ?: getenv('DB_ROOT_PASSWORD') ?: 'root_pass'),
            'charset' => (string)(getenv('TEST_DB_CHARSET') ?: getenv('DB_CHARSET') ?: 'utf8mb4'),
        ]);
        $normalizedDb = ApiDataAccess::fromDatabase($normalizedDatabase);
        $normalizedModel = $normalizedDb->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($normalizedModel);

        $after = $normalizedDb->modelCapabilities->enabledFeaturesForModelId((int)$normalizedModel['id']);
        self::assertNotContains('ecg', $after);
        self::assertContains('heart_rate', $after);
    }

    public function testBootstrapNormalizesPersistedFourPTouchModelCapabilitiesByRemovingInvalidEntries(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $db = ApiDataAccess::fromDatabase($database);
        $model = $db->models->find('4P Touch', 'D46');
        self::assertIsArray($model);

        $capabilityStmt = $pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $capabilityStmt->execute(['watch', 'ecg']);
        $invalidCapabilityId = (int)($capabilityStmt->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $invalidCapabilityId);

        $insert = $pdo->prepare('INSERT INTO model_capabilities (model_id, capability_id, enabled) VALUES (?, ?, 1)');
        $insert->execute([(int)$model['id'], $invalidCapabilityId]);

        $before = $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id']);
        self::assertContains('ecg', $before);
        self::assertContains('heart_rate', $before);

        $databaseNameRow = $pdo->query('SELECT DATABASE() AS db_name')->fetch();
        self::assertIsArray($databaseNameRow);
        $databaseName = (string)($databaseNameRow['db_name'] ?? '');
        self::assertNotSame('', $databaseName);

        $normalizedDatabase = new \Hub\Infrastructure\Persistence\DashboardDatabase([
            'driver' => 'mysql',
            'host' => (string)(getenv('TEST_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => (int)(getenv('TEST_DB_PORT') ?: getenv('DB_PORT') ?: 3306),
            'name' => $databaseName,
            'username' => (string)(getenv('TEST_DB_ADMIN_USER') ?: getenv('DB_ROOT_USER') ?: 'root'),
            'password' => (string)(getenv('TEST_DB_ADMIN_PASSWORD') ?: getenv('DB_ROOT_PASSWORD') ?: 'root_pass'),
            'charset' => (string)(getenv('TEST_DB_CHARSET') ?: getenv('DB_CHARSET') ?: 'utf8mb4'),
        ]);
        $normalizedDb = ApiDataAccess::fromDatabase($normalizedDatabase);
        $normalizedModel = $normalizedDb->models->find('4P Touch', 'D46');
        self::assertIsArray($normalizedModel);

        $after = $normalizedDb->modelCapabilities->enabledFeaturesForModelId((int)$normalizedModel['id']);
        self::assertNotContains('ecg', $after);
        self::assertContains('heart_rate', $after);
    }

    public function testBootstrapBackfillsRadarDefaultsWhenMissing(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();

        $pdo->exec("DELETE FROM model_capabilities WHERE model_id IN (SELECT id FROM models WHERE device_type = 'radar')");
        $pdo->exec("DELETE FROM whitelist WHERE supplier = 'Qinglanst' OR device_type = 'radar'");
        $pdo->exec("DELETE FROM supplier_device_types WHERE device_type = 'radar'");
        $pdo->exec("DELETE FROM capabilities WHERE device_type = 'radar'");
        $pdo->exec("DELETE FROM models WHERE device_type = 'radar'");
        $pdo->exec("DELETE FROM suppliers WHERE name = 'Qinglanst'");

        $databaseNameRow = $pdo->query('SELECT DATABASE() AS db_name')->fetch();
        self::assertIsArray($databaseNameRow);
        $databaseName = (string)($databaseNameRow['db_name'] ?? '');
        self::assertNotSame('', $databaseName);

        $normalizedDatabase = new \Hub\Infrastructure\Persistence\DashboardDatabase([
            'driver' => 'mysql',
            'host' => (string)(getenv('TEST_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => (int)(getenv('TEST_DB_PORT') ?: getenv('DB_PORT') ?: 3306),
            'name' => $databaseName,
            'username' => (string)(getenv('TEST_DB_ADMIN_USER') ?: getenv('DB_ROOT_USER') ?: 'root'),
            'password' => (string)(getenv('TEST_DB_ADMIN_PASSWORD') ?: getenv('DB_ROOT_PASSWORD') ?: 'root_pass'),
            'charset' => (string)(getenv('TEST_DB_CHARSET') ?: getenv('DB_CHARSET') ?: 'utf8mb4'),
        ]);
        $normalizedDb = ApiDataAccess::fromDatabase($normalizedDatabase);

        self::assertNotNull($normalizedDb->suppliers->findByName('Qinglanst'));
        $model = $normalizedDb->models->find('Qinglanst', 'RD-V1');
        self::assertIsArray($model);
        self::assertSame('radar', $model['device_type'] ?? null);
        $expectedRadar = ['positions', 'vitals', 'position_minute_stats', 'vitals_minute_stats'];
        $actualRadar = $normalizedDb->modelCapabilities->enabledFeaturesForModelId((int)$model['id']);
        sort($expectedRadar);
        sort($actualRadar);
        self::assertSame($expectedRadar, $actualRadar);
        $expectedCatalog = ['positions', 'vitals', 'position_minute_stats', 'vitals_minute_stats'];
        $actualCatalog = $normalizedDb->genericCapabilities->keysForDeviceType('radar');
        sort($expectedCatalog);
        sort($actualCatalog);
        self::assertSame($expectedCatalog, $actualCatalog);
        self::assertNotEmpty(array_values(array_filter(
            $normalizedDb->supplierDeviceTypes->all(),
            static fn (array $row): bool => ($row['device_type'] ?? '') === 'radar' && ($row['supplier'] ?? '') === 'Qinglanst'
        )));
    }

    public function testModelImagePathIsStoredAndPreservedWhenNoReplacementIsProvided(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $supplier = $db->suppliers->findByName('Wonlex');
        self::assertIsArray($supplier);

        $db->models->add((int)$supplier['id'], 'HW20PRO', 'HW20PRO', 'watch', '/model-images/example.jpg');
        $model = $db->models->find('Wonlex', 'HW20PRO');
        self::assertSame('/model-images/example.jpg', $model['image_path'] ?? null);
        self::assertSame('HW20PRO', $model['commercial_name'] ?? null);
        self::assertSame('watch', $model['device_type'] ?? null);

        $db->models->add((int)$supplier['id'], 'HW20PRO', 'HW20PRO', 'watch');
        $model = $db->models->find('Wonlex', 'HW20PRO');
        self::assertSame('/model-images/example.jpg', $model['image_path'] ?? null);
    }

    public function testExistingModelCanBeUpdatedById(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $supplier = $db->suppliers->findByName('Vivistar');
        self::assertIsArray($supplier);
        $model = $db->models->find('Wonlex', 'HW20PRO');
        self::assertIsArray($model);

        $updated = $db->models->update((int)$model['id'], (int)$supplier['id'], 'VIVISTAR-PRO', 'Vivistar Pro', 'radar', '/model-images/new.jpg');
        self::assertTrue($updated);

        self::assertNull($db->models->find('Wonlex', 'HW20PRO'));
        $model = $db->models->find('Vivistar', 'VIVISTAR-PRO');
        self::assertIsArray($model);
        self::assertSame('Vivistar Pro', $model['commercial_name'] ?? null);
        self::assertSame('radar', $model['device_type'] ?? null);
        self::assertSame('/model-images/new.jpg', $model['image_path'] ?? null);
        self::assertTrue($db->models->existsForDifferentId((int)$model['id'] + 100, (int)$supplier['id'], 'VIVISTAR-PRO'));
        self::assertFalse($db->models->existsForDifferentId((int)$model['id'], (int)$supplier['id'], 'VIVISTAR-PRO'));
    }

    public function testModelWritesBackfillSupplierDeviceTypes(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $supplier = $db->suppliers->findByName('Wonlex');
        self::assertIsArray($supplier);

        $db->models->add((int)$supplier['id'], 'RADAR-1', 'Radar 1', 'radar');

        $rows = array_values(array_filter(
            $db->supplierDeviceTypes->all(),
            static fn (array $row): bool => ($row['supplier'] ?? '') === 'Wonlex' && ($row['device_type'] ?? '') === 'radar'
        ));

        self::assertNotEmpty($rows);
    }

    public function testTimestampColumnsAreAutoPopulatedAndReturnedAsIso8601(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $supplierName = 'AutoTimestamp ' . bin2hex(random_bytes(3));

        $pdo->prepare('INSERT INTO suppliers (name, enabled) VALUES (?, 1)')->execute([$supplierName]);
        $created = $pdo->prepare('SELECT created_at, updated_at FROM suppliers WHERE name = ?');
        $created->execute([$supplierName]);
        $row = $created->fetch();

        self::assertIsArray($row);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($row['created_at'] ?? ''));
        self::assertSame($row['created_at'] ?? null, $row['updated_at'] ?? null);

        sleep(1);
        $pdo->prepare('UPDATE suppliers SET enabled = 0 WHERE name = ?')->execute([$supplierName]);

        $updated = $pdo->prepare('SELECT created_at, updated_at FROM suppliers WHERE name = ?');
        $updated->execute([$supplierName]);
        $rowAfterUpdate = $updated->fetch();

        self::assertIsArray($rowAfterUpdate);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($rowAfterUpdate['updated_at'] ?? ''));
        self::assertNotSame($row['updated_at'] ?? null, $rowAfterUpdate['updated_at'] ?? null);

        $db = ApiDataAccess::fromDatabase($database);
        $supplier = $db->suppliers->findByName($supplierName);
        self::assertIsArray($supplier);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($supplier['created_at'] ?? ''));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($supplier['updated_at'] ?? ''));
    }

    public function testDeviceConfigurationStoresDesiredAndReportedStateSeparately(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'fallDetection',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP76',
            ['enabled' => true],
            'queued',
            'abc123'
        );
        $db->deviceConfigurations->saveReported(
            '861265061009822',
            'fallDetection',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'AP76',
            ['data' => ['fields' => ['1']]]
        );

        $rows = $db->deviceConfigurations->allForImei('861265061009822');
        self::assertCount(1, $rows);
        self::assertSame(['enabled' => true], $rows[0]['desired_payload']);
        self::assertSame(['data' => ['fields' => ['1']]], $rows[0]['reported_payload']);
        self::assertSame('queued', $rows[0]['last_status']);
        self::assertSame('abc123', $rows[0]['last_command_id']);
    }

    public function testWhitelistStoresSimNumber(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro', 'watch', '0', '351912345678901');

        $row = $db->whitelist->get('861265061009822');
        self::assertIsArray($row);
        self::assertSame('351912345678901', $row['sim_number'] ?? null);
        self::assertSame('watch', $row['device_type'] ?? null);
        self::assertSame(0, $row['license_id'] ?? null);
    }

    public function testWhitelistDefaultsLegacyDeviceTypeAndLicenseId(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro');

        $row = $db->whitelist->get('861265061009822');
        self::assertIsArray($row);
        self::assertSame('watch', $row['device_type'] ?? null);
        self::assertSame(0, $row['license_id'] ?? null);
    }
}
