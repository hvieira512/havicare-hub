<?php

namespace Tests\Integration\Dashboard;

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
        self::assertSame(10, count($db->models->all()));
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
        self::assertSame(10, count($db->models->all()));
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
        $expectedRadar = [
            'heart_rate',
            'breath_rate',
            'sleep_state',
            'presence',
            'position_minute_stats',
            'vitals_minute_stats',
            'fall',
            'vitals_alarm',
            'presence_event',
        ];
        $actualRadar = $db->modelCapabilities->enabledFeaturesForModelId((int)$qinglanst['id']);
        sort($expectedRadar);
        sort($actualRadar);
        self::assertSame($expectedRadar, $actualRadar);

        $mkgw4 = $db->models->find('MOKO', 'MKGW4');
        self::assertIsArray($mkgw4);
        self::assertSame('gateway', $mkgw4['device_type'] ?? null);
        $expectedGateway = ['battery', 'connectivity', 'location'];
        $actualGateway = $db->modelCapabilities->enabledFeaturesForModelId((int)$mkgw4['id']);
        sort($expectedGateway);
        sort($actualGateway);
        self::assertSame($expectedGateway, $actualGateway);

        $w6b = $db->models->find('MOKO', 'W6B');
        self::assertIsArray($w6b);
        self::assertSame('bracelet', $w6b['device_type'] ?? null);
        $expectedBracelet = ['battery', 'motion', 'help_call'];
        $actualBracelet = $db->modelCapabilities->enabledFeaturesForModelId((int)$w6b['id']);
        sort($expectedBracelet);
        sort($actualBracelet);
        self::assertSame($expectedBracelet, $actualBracelet);
    }

    public function testCompletedMigrationsDoNotRerunOnRestart(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();
        $pdo->exec('CREATE TABLE generic_capabilities (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');

        $normalizedDatabase = $this->reopenDashboardDatabase($this->databaseName($pdo));

        $check = $normalizedDatabase->pdo()->query("SHOW TABLES LIKE 'generic_capabilities'");
        self::assertSame('generic_capabilities', $check->fetchColumn());
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

    public function testEmptyModelCapabilitySelectionSurvivesRestart(): void
    {
        $database = $this->createDashboardDatabase();
        $db = ApiDataAccess::fromDatabase($database);
        $model = $db->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);

        $db->modelCapabilities->replaceForModelId((int)$model['id'], []);
        $reopened = ApiDataAccess::fromDatabase(
            $this->reopenDashboardDatabase($this->databaseName($database->pdo()))
        );

        self::assertSame([], $reopened->modelCapabilities->enabledFeaturesForModelId((int)$model['id']));
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

    public function testRestartPreservesCustomizedModelCapabilities(): void
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

        $stored = $pdo->prepare('SELECT COUNT(*) FROM model_capabilities WHERE model_id = ? AND capability_id = ?');
        $stored->execute([(int)$model['id'], $invalidCapabilityId]);
        self::assertSame(1, (int)$stored->fetchColumn());

        $normalizedDatabase = $this->reopenDashboardDatabase($this->databaseName($pdo));
        $normalizedDb = ApiDataAccess::fromDatabase($normalizedDatabase);
        $normalizedModel = $normalizedDb->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($normalizedModel);

        $stored = $normalizedDatabase->pdo()->prepare(
            'SELECT COUNT(*) FROM model_capabilities WHERE model_id = ? AND capability_id = ?'
        );
        $stored->execute([(int)$normalizedModel['id'], $invalidCapabilityId]);
        self::assertSame(1, (int)$stored->fetchColumn());
    }

    public function testRestartPreservesCustomizedFourPTouchModelCapabilities(): void
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

        $stored = $pdo->prepare('SELECT COUNT(*) FROM model_capabilities WHERE model_id = ? AND capability_id = ?');
        $stored->execute([(int)$model['id'], $invalidCapabilityId]);
        self::assertSame(1, (int)$stored->fetchColumn());

        $normalizedDatabase = $this->reopenDashboardDatabase($this->databaseName($pdo));
        $normalizedDb = ApiDataAccess::fromDatabase($normalizedDatabase);
        $normalizedModel = $normalizedDb->models->find('4P Touch', 'D46');
        self::assertIsArray($normalizedModel);

        $stored = $normalizedDatabase->pdo()->prepare(
            'SELECT COUNT(*) FROM model_capabilities WHERE model_id = ? AND capability_id = ?'
        );
        $stored->execute([(int)$normalizedModel['id'], $invalidCapabilityId]);
        self::assertSame(1, (int)$stored->fetchColumn());
    }

    public function testRestartDoesNotRecreateDeletedReferenceData(): void
    {
        $database = $this->createDashboardDatabase();
        $pdo = $database->pdo();

        $pdo->exec("DELETE FROM model_capabilities WHERE model_id IN (SELECT id FROM models WHERE device_type = 'radar')");
        $pdo->exec("DELETE FROM whitelist WHERE supplier = 'Qinglanst' OR device_type = 'radar'");
        $pdo->exec("DELETE FROM supplier_device_types WHERE device_type = 'radar'");
        $pdo->exec("DELETE FROM capabilities WHERE device_type = 'radar'");
        $pdo->exec("DELETE FROM models WHERE device_type = 'radar'");
        $pdo->exec("DELETE FROM suppliers WHERE name = 'Qinglanst'");

        $normalizedDatabase = $this->reopenDashboardDatabase($this->databaseName($pdo));
        $normalizedDb = ApiDataAccess::fromDatabase($normalizedDatabase);

        self::assertNull($normalizedDb->suppliers->findByName('Qinglanst'));
        self::assertNull($normalizedDb->models->find('Qinglanst', 'RD-V1'));
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

        $pdo->prepare('INSERT INTO suppliers (name) VALUES (?)')->execute([$supplierName]);
        $created = $pdo->prepare('SELECT created_at, updated_at FROM suppliers WHERE name = ?');
        $created->execute([$supplierName]);
        $row = $created->fetch();

        self::assertIsArray($row);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($row['created_at'] ?? ''));
        self::assertSame($row['created_at'] ?? null, $row['updated_at'] ?? null);

        sleep(1);
        // Renomear e não tocar: o `ON UPDATE CURRENT_TIMESTAMP` só dispara se algum valor
        // mudar mesmo, e o nome é a única coluna de um fornecedor que não é uma data.
        $renamed = $supplierName . ' renomeado';
        $pdo->prepare('UPDATE suppliers SET name = ? WHERE name = ?')->execute([$renamed, $supplierName]);

        $updated = $pdo->prepare('SELECT created_at, updated_at FROM suppliers WHERE name = ?');
        $updated->execute([$renamed]);
        $rowAfterUpdate = $updated->fetch();

        self::assertIsArray($rowAfterUpdate);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($rowAfterUpdate['updated_at'] ?? ''));
        self::assertNotSame($row['updated_at'] ?? null, $rowAfterUpdate['updated_at'] ?? null);

        $db = ApiDataAccess::fromDatabase($database);
        $supplier = $db->suppliers->findByName($renamed);
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
        // Sem cliente é NULL na tabela, e as duas colunas juntas: a sentinela do tópico é
        // construída na leitura, não guardada.
        self::assertNull($row['license_id']);
        self::assertNull($row['company']);
    }

    public function testWhitelistDefaultsLegacyDeviceTypeAndLicenseId(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro');

        $row = $db->whitelist->get('861265061009822');
        self::assertIsArray($row);
        self::assertSame('watch', $row['device_type'] ?? null);
        self::assertNull($row['license_id']);
        self::assertNull($row['company']);

        // E o que a API devolve não muda: a sentinela volta na fronteira de leitura.
        $device = $db->whitelist->getDevice('861265061009822');
        self::assertIsArray($device);
        self::assertSame(0, $device['licenseId']);
        self::assertSame('null', $device['company']);
    }
}
