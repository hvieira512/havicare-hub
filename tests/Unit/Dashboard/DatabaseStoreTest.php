<?php

namespace Tests\Unit\Dashboard;

use Hub\Command\DeviceCommandCatalog;
use Hub\Dashboard\DashboardDataAccess;
use Tests\Support\MysqlDashboardTestCase;

final class DatabaseStoreTest extends MysqlDashboardTestCase
{
    public function testSeedsDefaultModelsWhenSuppliersAlreadyExist(): void
    {
        $database = $this->createDashboardDatabase();
        $db = DashboardDataAccess::fromDatabase($database);
        self::assertSame(8, count($db->models->all()));
        self::assertSame('vivistar-iw', $db->models->protocolForModel('Vivistar', 'L08 PRO'));
        self::assertSame('four-p-touch', $db->models->protocolForModel('4P Touch', 'D46'));
        $model = $db->models->find('Vivistar', 'L08 PRO');
        self::assertIsArray($model);
        self::assertSame('L08 Pro', $model['commercial_name'] ?? null);
        self::assertSame('watch', $model['device_type'] ?? null);
        self::assertSame(
            ['blood_oxygen', 'blood_pressure', 'heart_rate', 'location', 'temperature'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );

        $db = DashboardDataAccess::fromDatabase($database);
        self::assertSame(8, count($db->models->all()));
        self::assertSame('vivistar-iw', $db->models->protocolForModel('Vivistar', 'L08 PRO'));
        self::assertSame('four-p-touch', $db->models->protocolForModel('4P Touch', 'D46'));
        $model = $db->models->find('Vivistar', 'L08 PRO');
        self::assertIsArray($model);
        self::assertSame('L08 Pro', $model['commercial_name'] ?? null);
        self::assertSame('watch', $model['device_type'] ?? null);
        self::assertSame(
            ['blood_oxygen', 'blood_pressure', 'heart_rate', 'location', 'temperature'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );
    }

    public function testModelCapabilitiesCanBeReplacedPerModel(): void
    {
        $db = DashboardDataAccess::fromDatabase($this->createDashboardDatabase());
        $model = $db->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);

        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['blood_pressure', 'heart_rate']);

        self::assertSame(
            ['blood_pressure', 'heart_rate'],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );
    }

    public function testModelImagePathIsStoredAndPreservedWhenNoReplacementIsProvided(): void
    {
        $db = DashboardDataAccess::fromDatabase($this->createDashboardDatabase());
        $supplier = $db->suppliers->findByName('Wonlex');
        self::assertIsArray($supplier);

        $db->models->add((int)$supplier['id'], 'HW20PRO', 'HW20PRO', 'watch', 'wonlex-json', '/model-images/example.jpg');
        $model = $db->models->find('Wonlex', 'HW20PRO');
        self::assertSame('/model-images/example.jpg', $model['image_path'] ?? null);
        self::assertSame('HW20PRO', $model['commercial_name'] ?? null);
        self::assertSame('watch', $model['device_type'] ?? null);

        $db->models->add((int)$supplier['id'], 'HW20PRO', 'HW20PRO', 'watch', 'wonlex-json-v2');
        $model = $db->models->find('Wonlex', 'HW20PRO');
        self::assertSame('wonlex-json-v2', $model['protocol'] ?? null);
        self::assertSame('/model-images/example.jpg', $model['image_path'] ?? null);
    }

    public function testExistingModelCanBeUpdatedById(): void
    {
        $db = DashboardDataAccess::fromDatabase($this->createDashboardDatabase());
        $supplier = $db->suppliers->findByName('Vivistar');
        self::assertIsArray($supplier);
        $model = $db->models->find('Wonlex', 'HW20PRO');
        self::assertIsArray($model);

        $updated = $db->models->update((int)$model['id'], (int)$supplier['id'], 'VIVISTAR-PRO', 'Vivistar Pro', 'radar', 'vivistar-iw', '/model-images/new.jpg');
        self::assertTrue($updated);

        self::assertNull($db->models->find('Wonlex', 'HW20PRO'));
        $model = $db->models->find('Vivistar', 'VIVISTAR-PRO');
        self::assertIsArray($model);
        self::assertSame('vivistar-iw', $model['protocol'] ?? null);
        self::assertSame('Vivistar Pro', $model['commercial_name'] ?? null);
        self::assertSame('radar', $model['device_type'] ?? null);
        self::assertSame('/model-images/new.jpg', $model['image_path'] ?? null);
        self::assertTrue($db->models->existsForDifferentId((int)$model['id'] + 100, (int)$supplier['id'], 'VIVISTAR-PRO'));
        self::assertFalse($db->models->existsForDifferentId((int)$model['id'], (int)$supplier['id'], 'VIVISTAR-PRO'));
    }

    public function testDeviceConfigurationStoresDesiredAndReportedStateSeparately(): void
    {
        $db = DashboardDataAccess::fromDatabase($this->createDashboardDatabase());
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
        $db = DashboardDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro', 'watch', '0', '351912345678901');

        $row = $db->whitelist->get('861265061009822');
        self::assertIsArray($row);
        self::assertSame('351912345678901', $row['sim_number'] ?? null);
        self::assertSame('watch', $row['device_type'] ?? null);
        self::assertSame('0', $row['license_id'] ?? null);
    }

    public function testWhitelistDefaultsLegacyDeviceTypeAndLicenseId(): void
    {
        $db = DashboardDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro');

        $row = $db->whitelist->get('861265061009822');
        self::assertIsArray($row);
        self::assertSame('watch', $row['device_type'] ?? null);
        self::assertSame('0', $row['license_id'] ?? null);
    }
}
