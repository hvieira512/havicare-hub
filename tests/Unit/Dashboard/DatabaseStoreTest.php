<?php

namespace Tests\Unit\Dashboard;

use Hub\Dashboard\DashboardDataAccess;
use Hub\Dashboard\DashboardDatabase;
use PHPUnit\Framework\TestCase;

final class DatabaseStoreTest extends TestCase
{
    public function testSeedsDefaultModelsWhenSuppliersAlreadyExist(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hub-dashboard-');
        self::assertIsString($path);

        try {
            $db = DashboardDataAccess::fromDatabase(new DashboardDatabase($path));
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

            $db = DashboardDataAccess::fromDatabase(new DashboardDatabase($path));
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
        } finally {
            unlink($path);
        }
    }

    public function testModelCapabilitiesCanBeReplacedPerModel(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hub-dashboard-');
        self::assertIsString($path);

        try {
            $db = DashboardDataAccess::fromDatabase(new DashboardDatabase($path));
            $model = $db->models->find('Vivistar', 'L08 Pro');
            self::assertIsArray($model);

            $db->modelCapabilities->replaceForModelId((int)$model['id'], ['blood_pressure', 'heart_rate']);

            self::assertSame(
                ['blood_pressure', 'heart_rate'],
                $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
            );
        } finally {
            unlink($path);
        }
    }

    public function testModelImagePathIsStoredAndPreservedWhenNoReplacementIsProvided(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hub-dashboard-');
        self::assertIsString($path);

        try {
            $db = DashboardDataAccess::fromDatabase(new DashboardDatabase($path));
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
        } finally {
            unlink($path);
        }
    }

    public function testExistingModelCanBeUpdatedById(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hub-dashboard-');
        self::assertIsString($path);

        try {
            $db = DashboardDataAccess::fromDatabase(new DashboardDatabase($path));
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
        } finally {
            unlink($path);
        }
    }

    public function testDeviceConfigurationStoresDesiredAndReportedStateSeparately(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hub-dashboard-');
        self::assertIsString($path);

        try {
            $db = DashboardDataAccess::fromDatabase(new DashboardDatabase($path));
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
        } finally {
            unlink($path);
        }
    }

    public function testWhitelistStoresSimNumber(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hub-dashboard-');
        self::assertIsString($path);

        try {
            $db = DashboardDataAccess::fromDatabase(new DashboardDatabase($path));
            $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro', 'watch', '0', '351912345678901');

            $row = $db->whitelist->get('861265061009822');
            self::assertIsArray($row);
            self::assertSame('351912345678901', $row['sim_number'] ?? null);
            self::assertSame('watch', $row['device_type'] ?? null);
            self::assertSame('0', $row['license_id'] ?? null);
        } finally {
            unlink($path);
        }
    }

    public function testWhitelistDefaultsLegacyDeviceTypeAndLicenseId(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hub-dashboard-');
        self::assertIsString($path);

        try {
            $db = DashboardDataAccess::fromDatabase(new DashboardDatabase($path));
            $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro');

            $row = $db->whitelist->get('861265061009822');
            self::assertIsArray($row);
            self::assertSame('watch', $row['device_type'] ?? null);
            self::assertSame('0', $row['license_id'] ?? null);
        } finally {
            unlink($path);
        }
    }

    public function testBootstrapDropsLegacyHistoryTables(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hub-dashboard-');
        self::assertIsString($path);

        try {
            $pdo = new \PDO("sqlite:{$path}");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE telemetry (id INTEGER PRIMARY KEY AUTOINCREMENT, imei TEXT NOT NULL, type TEXT NOT NULL, payload TEXT NOT NULL, recorded_at TEXT NOT NULL)');
            $pdo->exec('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, imei TEXT NOT NULL, type TEXT NOT NULL, payload TEXT NOT NULL, recorded_at TEXT NOT NULL)');
            $pdo->exec('CREATE TABLE raw_payloads (id INTEGER PRIMARY KEY AUTOINCREMENT, imei TEXT NOT NULL, payload TEXT NOT NULL, recorded_at TEXT NOT NULL)');
            $pdo->exec('CREATE INDEX idx_telemetry_imei ON telemetry(imei)');
            $pdo->exec('CREATE INDEX idx_telemetry_recorded ON telemetry(recorded_at)');
            $pdo->exec('CREATE INDEX idx_events_imei ON events(imei)');
            $pdo->exec('CREATE INDEX idx_raw_payloads_imei ON raw_payloads(imei)');

            DashboardDataAccess::fromDatabase(new DashboardDatabase($path));

            self::assertFalse($this->sqliteObjectExists($path, 'table', 'telemetry'));
            self::assertFalse($this->sqliteObjectExists($path, 'table', 'events'));
            self::assertFalse($this->sqliteObjectExists($path, 'table', 'raw_payloads'));
            self::assertFalse($this->sqliteObjectExists($path, 'index', 'idx_telemetry_imei'));
            self::assertFalse($this->sqliteObjectExists($path, 'index', 'idx_telemetry_recorded'));
            self::assertFalse($this->sqliteObjectExists($path, 'index', 'idx_events_imei'));
            self::assertFalse($this->sqliteObjectExists($path, 'index', 'idx_raw_payloads_imei'));
        } finally {
            unlink($path);
        }
    }

    private function sqliteObjectExists(string $path, string $type, string $name): bool
    {
        $pdo = new \PDO("sqlite:{$path}");
        $stmt = $pdo->prepare('SELECT 1 FROM sqlite_master WHERE type = ? AND name = ?');
        $stmt->execute([$type, $name]);

        return $stmt->fetchColumn() !== false;
    }
}
