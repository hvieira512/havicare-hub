<?php

namespace Tests\Unit\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
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
        self::assertSame(5, count($db->models->all()));
        $model = $db->models->find('Vivistar', 'L08 PRO');
        self::assertIsArray($model);
        self::assertSame('L08 Pro', $model['commercial_name'] ?? null);
        self::assertSame('watch', $model['device_type'] ?? null);
        self::assertSame(
            [
                'activity',
                'alarm_clock',
                'auto_vitals_interval',
                'battery',
                'blood_oxygen',
                'blood_oxygen_alert',
                'blood_oxygen_continuous',
                'blood_oxygen_measurement_interval',
                'blood_pressure',
                'blood_pressure_alert',
                'blood_pressure_calibration',
                'blood_pressure_measurement_interval',
                'blood_pressure_trend',
                'breath_rate',
                'breath_rate_measurement_interval',
                'call_in_restriction',
                'call_whitelist',
                'center_number',
                'device_binding',
                'device_password',
                'device_settings_sync',
                'device_status',
                'do_not_disturb',
                'ecg',
                'ecg_measurement_interval',
                'fall_detection',
                'fall_sensitivity',
                'find_device',
                'firmware_version',
                'heart_rate',
                'heart_rate_continuous',
                'heart_rate_high_alert',
                'heart_rate_low_alert',
                'heart_rate_measurement_interval',
                'hrv',
                'hrv_measurement_interval',
                'language_timezone',
                'location',
                'location_reporting_interval',
                'low_battery_alert',
                'make_call',
                'medication_reminders',
                'monitor_number',
                'pedometer_schedule',
                'phonebook',
                'power_off',
                'ppg',
                'ppg_measurement_interval',
                'push_message',
                'remove_watch_alarm',
                'remove_watch_sms_alert',
                'reset_device',
                'rr_interval',
                'rr_interval_measurement_interval',
                'sleep',
                'sleep_monitoring',
                'sos_contacts',
                'sos_sms_alert',
                'sound_profile',
                'step_goal',
                'step_reporting_interval',
                'temperature',
                'temperature_continuous',
                'temperature_high_alert',
                'temperature_low_alert',
                'temperature_measurement_interval',
                'working_mode',
            ],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );

        $db = ApiDataAccess::fromDatabase($database);
        self::assertSame(5, count($db->models->all()));
        $model = $db->models->find('Vivistar', 'L08 PRO');
        self::assertIsArray($model);
        self::assertSame('L08 Pro', $model['commercial_name'] ?? null);
        self::assertSame('watch', $model['device_type'] ?? null);
        self::assertSame(
            [
                'activity',
                'alarm_clock',
                'auto_vitals_interval',
                'battery',
                'blood_oxygen',
                'blood_oxygen_alert',
                'blood_oxygen_continuous',
                'blood_oxygen_measurement_interval',
                'blood_pressure',
                'blood_pressure_alert',
                'blood_pressure_calibration',
                'blood_pressure_measurement_interval',
                'blood_pressure_trend',
                'breath_rate',
                'breath_rate_measurement_interval',
                'call_in_restriction',
                'call_whitelist',
                'center_number',
                'device_binding',
                'device_password',
                'device_settings_sync',
                'device_status',
                'do_not_disturb',
                'ecg',
                'ecg_measurement_interval',
                'fall_detection',
                'fall_sensitivity',
                'find_device',
                'firmware_version',
                'heart_rate',
                'heart_rate_continuous',
                'heart_rate_high_alert',
                'heart_rate_low_alert',
                'heart_rate_measurement_interval',
                'hrv',
                'hrv_measurement_interval',
                'language_timezone',
                'location',
                'location_reporting_interval',
                'low_battery_alert',
                'make_call',
                'medication_reminders',
                'monitor_number',
                'pedometer_schedule',
                'phonebook',
                'power_off',
                'ppg',
                'ppg_measurement_interval',
                'push_message',
                'remove_watch_alarm',
                'remove_watch_sms_alert',
                'reset_device',
                'rr_interval',
                'rr_interval_measurement_interval',
                'sleep',
                'sleep_monitoring',
                'sos_contacts',
                'sos_sms_alert',
                'sound_profile',
                'step_goal',
                'step_reporting_interval',
                'temperature',
                'temperature_continuous',
                'temperature_high_alert',
                'temperature_low_alert',
                'temperature_measurement_interval',
                'working_mode',
            ],
            $db->modelCapabilities->enabledFeaturesForModelId((int)$model['id'])
        );
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
