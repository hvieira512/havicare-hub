<?php

namespace Tests\Unit\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\DeviceService;
use Hub\Dashboard\DashboardStore;
use Hub\PendingDownlink;
use Hub\PendingDownlinkQueue;
use Hub\Registry\Whitelist;
use GuzzleHttp\Psr7\ServerRequest;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Tests\Support\MysqlDashboardTestCase;

final class DevicesApiTest extends MysqlDashboardTestCase
{
    private string $whitelistPath;

    protected function setUp(): void
    {
        $this->whitelistPath = sys_get_temp_dir() . '/hub-devices-api-whitelist-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->whitelistPath, json_encode([
            '861265061009822' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro'],
            '868017032159118' => ['supplier' => '4P Touch', 'model' => 'D46'],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (is_file($this->whitelistPath)) {
            unlink($this->whitelistPath);
        }
    }

    public function testRequestFeatureRejectsDisabledModelRequest(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['location']);

        $response = $api->requestFeature('861265061009822', json_encode(['feature' => 'heart_rate'], JSON_THROW_ON_ERROR));

        self::assertSame('unsupported_feature', $response['error']['code'] ?? null);
    }

    public function testCreateDerivesFourPTouchDeviceIdFromImei(): void
    {
        [$api, $db] = $this->makeApi();

        $response = $api->create(json_encode([
            'imei' => '864293000000111',
            'supplier' => '4P Touch',
            'model' => 'D46',
            'deviceType' => 'watch',
            'licenseId' => '0',
            'deviceId' => '',
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('9300000011', $api->show('864293000000111')['device']['deviceId'] ?? null);
    }

    public function testCreateRejectsDuplicateImei(): void
    {
        [$api, $db] = $this->makeApi();

        $response = $api->create(json_encode([
            'imei' => '861265061009822',
            'supplier' => 'Vivistar',
            'model' => 'L08 Pro',
            'deviceType' => 'watch',
            'licenseId' => '0',
        ], JSON_THROW_ON_ERROR));

        self::assertSame('device_exists', $response['error']['code'] ?? null);
        self::assertSame('Device with this IMEI already exists', $response['error']['message'] ?? null);
    }

    public function testListFiltersByPartialModelOnWhitelistRepository(): void
    {
        [$api, $db] = $this->makeApi();
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro');

        $response = $api->list('page=1&limit=5&model=L08');

        self::assertSame(1, $response['pagination']['total'] ?? null);
        self::assertSame('861265061009822', $response['data'][0]['imei'] ?? null);
        self::assertSame('L08 Pro', $response['data'][0]['model'] ?? null);
    }

    public function testListFiltersByPartialModelOnStoreFallback(): void
    {
        [$api] = $this->makeApi();

        $response = $api->list('page=1&limit=5&model=L08');

        self::assertSame(1, $response['pagination']['total'] ?? null);
        self::assertSame('861265061009822', $response['data'][0]['imei'] ?? null);
        self::assertSame('L08 Pro', $response['data'][0]['model'] ?? null);
    }

    public function testListReturnsAbsoluteModelImageUrl(): void
    {
        [$api, $db] = $this->makeApi();
        $supplier = $db->suppliers->findByName('Vivistar');
        self::assertIsArray($supplier);

        $db->models->add((int)$supplier['id'], 'L08 Pro', 'L08 Pro', 'watch', '/model-images/example.jpg');

        $response = $api->list('page=1&limit=5', null, 'http://localhost:8081');

        self::assertSame('http://localhost:8081/model-images/example.jpg', $response['data'][0]['image'] ?? null);
    }

    public function testShowReturnsSparseCapabilitiesWithStoredConfigurationValues(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], [
            'heart_rate',
            'location',
            'call_whitelist',
            'whitelist_enabled',
            'device_password',
        ]);
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'call_whitelist',
            'vivistar',
            'Vivistar',
            'L08 Pro',
            'CALL_WHITELIST',
            ['fields' => ['|+351922222222', '|+351933333333']]
        );
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'whitelist_enabled',
            'vivistar',
            'Vivistar',
            'L08 Pro',
            'WHITELIST_ENABLED',
            ['enabled' => true]
        );
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'devicePassword',
            'four-p-touch',
            'Vivistar',
            'L08 Pro',
            'PASSWORD',
            ['password' => '2468']
        );

        $response = $api->show('861265061009822');

        self::assertSame(
            ['supported' => true, 'requestable' => true],
            $response['capabilities']['telemetry']['heart_rate'] ?? null
        );
        self::assertSame(
            ['supported' => true, 'requestable' => true],
            $response['capabilities']['telemetry']['location'] ?? null
        );
        self::assertArrayNotHasKey('blood_pressure', $response['capabilities']['telemetry'] ?? []);
        self::assertSame(
            [
                ['name' => '', 'phone' => '+351922222222'],
                ['name' => '', 'phone' => '+351933333333'],
            ],
            $response['capabilities']['contacts']['call_whitelist']['value'] ?? null
        );
        self::assertSame(10, $response['capabilities']['contacts']['call_whitelist']['_meta']['limit'] ?? null);
        self::assertSame(10, $response['capabilities']['contacts']['call_whitelist']['_meta']['name']['maxLength'] ?? null);
        self::assertSame(20, $response['capabilities']['contacts']['call_whitelist']['_meta']['phone']['maxLength'] ?? null);
        self::assertTrue($response['capabilities']['contacts']['call_whitelist']['_meta']['phone']['asciiOnly'] ?? false);
        self::assertTrue($response['capabilities']['contacts']['whitelist_enabled']['value']['enabled'] ?? false);
        self::assertSame('BP84', $response['capabilities']['contacts']['whitelist_enabled']['_nativeKey'] ?? null);
        self::assertSame(
            ['password' => '2468'],
            $response['capabilities']['settings_system']['device_password']['value'] ?? null
        );
        self::assertSame([], $response['capabilities']['health'] ?? null);
        self::assertSame([], $response['capabilities']['alarms'] ?? null);
        self::assertSame('never_reported', $response['pending']['contacts']['call_whitelist']['status'] ?? null);
        self::assertSame('never_reported', $response['pending']['contacts']['whitelist_enabled']['status'] ?? null);
        self::assertSame('never_reported', $response['pending']['settings_system']['device_password']['status'] ?? null);
        self::assertSame([], $response['transportPending'] ?? null);
    }

    public function testShowDoesNotExposePhonebookForVivistarDevices(): void
    {
        [$api] = $this->makeApi();

        $response = $api->show('861265061009822');

        self::assertArrayNotHasKey('phonebook', $response['capabilities']['contacts'] ?? []);
        self::assertArrayHasKey('call_whitelist', $response['capabilities']['contacts'] ?? []);
    }

    public function testShowReturnsVoerkaPagerCallCapabilitySupportWithoutStoredConfiguration(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $db->whitelist->register('bea6c3dd8e02', 'Voerka', 'W812', 'ncs', 0, '', 'bea6c3dd8e02');
        $store->registerDevice('bea6c3dd8e02', 'Voerka', 'W812', 'ncs', 1001, '', '', 'hitcare');

        $response = $api->show('bea6c3dd8e02');

        self::assertSame('Voerka', $response['model']['supplier'] ?? null);
        self::assertSame('W812', $response['model']['commercialName'] ?? null);
        self::assertSame('ncs', $response['model']['deviceType'] ?? null);
        self::assertArrayHasKey('pager_call', $response['capabilities']['alarms'] ?? []);
        self::assertSame(
            [
                'value' => [],
                '_meta' => [],
                '_type' => 'pager_call',
            ],
            $response['capabilities']['alarms']['pager_call'] ?? null
        );
    }

    public function testShowExposesModelSupportedCapabilitiesWithoutStoredConfigurationRows(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('864293000000222', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock', 'phonebook']);

        $response = $api->show('864293000000222');

        self::assertSame([], $response['configurations'] ?? []);
        self::assertArrayHasKey('alarm_clock', $response['capabilities']['alarms'] ?? []);
        self::assertSame(3, $response['capabilities']['alarms']['alarm_clock']['_meta']['limit'] ?? null);
        self::assertSame(
            [
                ['value' => 'once', 'label' => 'Uma vez'],
                ['value' => 'daily', 'label' => 'Todos os dias'],
                ['value' => 'custom', 'label' => 'Personalizado'],
            ],
            $response['capabilities']['alarms']['alarm_clock']['_meta']['recurrence']['options'] ?? null
        );
        self::assertSame(
            [
                [
                    'time' => '',
                    'enabled' => true,
                    'recurrence' => ['kind' => 'once'],
                ],
            ],
            $response['capabilities']['alarms']['alarm_clock']['value'] ?? null
        );
        self::assertArrayHasKey('phonebook', $response['capabilities']['contacts'] ?? []);
        self::assertSame(5, $response['capabilities']['contacts']['phonebook']['_meta']['limit'] ?? null);
        self::assertSame(10, $response['capabilities']['contacts']['phonebook']['_meta']['name']['maxLength'] ?? null);
        self::assertSame(20, $response['capabilities']['contacts']['phonebook']['_meta']['phone']['maxLength'] ?? null);
        self::assertTrue($response['capabilities']['contacts']['phonebook']['_meta']['phone']['asciiOnly'] ?? false);
        self::assertSame(
            [
                ['name' => '', 'phone' => ''],
            ],
            $response['capabilities']['contacts']['phonebook']['value'] ?? null
        );
    }

    public function testShowExposesFourPTouchSosContactsAsNumbersObject(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('861728087060467', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['sos_contacts']);
        $db->deviceConfigurations->saveDesired(
            '861728087060467',
            'sosNumber1',
            'four-p-touch',
            '4P Touch',
            'D46',
            'SOS1',
            ['phone' => '+351938854803']
        );
        $db->deviceConfigurations->saveDesired(
            '861728087060467',
            'sosNumber2',
            'four-p-touch',
            '4P Touch',
            'D46',
            'SOS2',
            ['phone' => '+351938854807']
        );

        $response = $api->show('861728087060467');

        self::assertSame(
            ['+351938854803', '+351938854807'],
            $response['capabilities']['contacts']['sos_contacts']['value'] ?? null
        );
        self::assertSame(
            ['+351938854803', '+351938854807'],
            $response['configurations']['sos_contacts'] ?? null
        );
        self::assertSame(
            3,
            $response['capabilities']['contacts']['sos_contacts']['_meta']['limit'] ?? null
        );
        self::assertSame(
            20,
            $response['capabilities']['contacts']['sos_contacts']['_meta']['phone']['maxLength'] ?? null
        );
        self::assertTrue($response['capabilities']['contacts']['sos_contacts']['_meta']['phone']['asciiOnly'] ?? false);
    }

    public function testShowExposesFourPTouchCallWhitelistAsNumbersObject(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('637507597567372', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['call_whitelist']);
        $db->deviceConfigurations->saveDesired(
            '637507597567372',
            'whitelistGroup1',
            'four-p-touch',
            '4P Touch',
            'D46',
            'WHITELIST1',
            ['numbers' => ['111', '222', '333', '444', '555']]
        );
        $db->deviceConfigurations->saveDesired(
            '637507597567372',
            'whitelistGroup2',
            'four-p-touch',
            '4P Touch',
            'D46',
            'WHITELIST2',
            ['numbers' => ['666', '777', '', '', '']]
        );

        $response = $api->show('637507597567372');

        self::assertSame(
            ['111', '222', '333', '444', '555', '666', '777'],
            $response['capabilities']['contacts']['call_whitelist']['value'] ?? null
        );
        self::assertSame(
            5,
            $response['capabilities']['contacts']['call_whitelist']['_meta']['limit'] ?? null
        );
    }

    public function testShowWrapsFourPTouchGenericCapabilitiesWithValueAndMetaShape(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('861728087060467', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], [
            'location_reporting_interval',
            'sos_sms_alert',
            'low_battery_alert',
            'fall_detection',
            'auto_vitals_interval',
            'sleep_monitoring',
            'temperature_measurement_interval',
            'language_timezone',
        ]);
        $db->deviceConfigurations->saveDesired(
            '861728087060467',
            'uploadInterval',
            'four-p-touch',
            '4P Touch',
            'D46',
            'UPLOAD',
            ['intervalSeconds' => 300]
        );
        $db->deviceConfigurations->saveDesired(
            '861728087060467',
            'sosSmsAlerts',
            'four-p-touch',
            '4P Touch',
            'D46',
            'SOSSMS',
            ['enabled' => false]
        );
        $db->deviceConfigurations->saveDesired(
            '861728087060467',
            'lowBatterySmsAlerts',
            'four-p-touch',
            '4P Touch',
            'D46',
            'LOWBAT',
            ['enabled' => false]
        );
        $db->deviceConfigurations->saveDesired(
            '861728087060467',
            'fallDownAlert',
            'four-p-touch',
            '4P Touch',
            'D46',
            'FALLDOWN',
            ['enabled' => true, 'callCenterOnFall' => false]
        );
        $db->deviceConfigurations->saveDesired(
            '861728087060467',
            'healthAutoMeasurement',
            'four-p-touch',
            '4P Touch',
            'D46',
            'HEALTHAUTOSET',
            ['enabled' => true, 'intervalMinutes' => 10]
        );
        $db->deviceConfigurations->saveDesired(
            '861728087060467',
            'sleepTime',
            'four-p-touch',
            '4P Touch',
            'D46',
            'SLEEPTIME',
            ['range' => '21:10-07:30']
        );
        $db->deviceConfigurations->saveDesired(
            '861728087060467',
            'bodyTemperatureInterval',
            'four-p-touch',
            '4P Touch',
            'D46',
            'bodytemp',
            ['enabled' => true, 'intervalHours' => 2]
        );
        $db->deviceConfigurations->saveDesired(
            '861728087060467',
            'languageTimezone',
            'four-p-touch',
            '4P Touch',
            'D46',
            'LZ',
            ['language' => 3, 'timeZone' => '0']
        );

        $response = $api->show('861728087060467');

        self::assertSame(
            ['value' => ['intervalSeconds' => 300], '_meta' => [], '_type' => 'location_reporting_interval'],
            $response['capabilities']['settings_system']['location_reporting_interval'] ?? null
        );
        self::assertSame(
            ['value' => ['enabled' => false], '_meta' => [], '_type' => 'sos_sms_alert'],
            $response['capabilities']['alarms']['sos_sms_alert'] ?? null
        );
        self::assertSame(
            ['value' => ['enabled' => false], '_meta' => [], '_type' => 'low_battery_alert'],
            $response['capabilities']['alarms']['low_battery_alert'] ?? null
        );
        self::assertSame(
            ['value' => ['enabled' => true, 'callCenterOnFall' => false], '_meta' => [], '_type' => 'fall_detection'],
            $response['capabilities']['alarms']['fall_detection'] ?? null
        );
        self::assertSame(
            ['value' => ['enabled' => true, 'intervalMinutes' => 10], '_meta' => [], '_type' => 'auto_vitals_interval'],
            $response['capabilities']['health']['auto_vitals_interval'] ?? null
        );
        self::assertSame(
            ['value' => ['range' => '21:10-07:30'], '_meta' => [], '_type' => 'sleep_monitoring'],
            $response['capabilities']['health']['sleep_monitoring'] ?? null
        );
        self::assertSame(
            ['value' => ['enabled' => true, 'intervalHours' => 2], '_meta' => [], '_type' => 'temperature_measurement_interval'],
            $response['capabilities']['health']['temperature_measurement_interval'] ?? null
        );
        self::assertSame(
            ['value' => ['language' => 3, 'timeZone' => '0'], '_meta' => [], '_type' => 'language_timezone'],
            $response['capabilities']['settings_system']['language_timezone'] ?? null
        );
    }

    public function testShowNormalizesStoredFourPTouchFallSensitivityToNewKeys(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('864293000000333', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['fall_sensitivity']);
        $db->deviceConfigurations->saveDesired(
            '864293000000333',
            'fallDownSensitivity',
            'four-p-touch',
            '4P Touch',
            'D46',
            'LSSET',
            [
                'sensitivityLevel' => 4,
                'totalLevels' => 6,
            ]
        );

        $response = $api->show('864293000000333');

        self::assertSame(
            [
                'sensitivity' => 4,
                'levels' => 6,
            ],
            $response['capabilities']['alarms']['fall_sensitivity']['value'] ?? null
        );
    }

    public function testShowExposesTakePillsStructuredMetaForFourPTouch(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);
        $db->deviceConfigurations->saveDesired(
            '868017032159118',
            'takePills',
            'four-p-touch',
            '4P Touch',
            'D46',
            'TAKEPILLS',
            [
                'reminderSettings' => '11:25-1-3-1010',
                'number' => 3,
                'reminderText' => 'meds',
                'voiceData' => 'QUJDRA==',
            ]
        );

        $response = $api->show('868017032159118');

        self::assertSame(
            [
                'reminderSettings' => [
                    [
                        'time' => '11:25',
                        'enabled' => true,
                        'frequency' => 3,
                        'custom' => '1010',
                    ],
                ],
                'number' => 3,
                'reminderText' => 'meds',
                'voiceData' => 'QUJDRA==',
            ],
            $response['capabilities']['alarms']['medication_reminders']['value'] ?? null
        );
        self::assertSame(3, $response['capabilities']['alarms']['medication_reminders']['_meta']['limit'] ?? null);
        self::assertSame(
            [
                ['value' => 1, 'label' => 'Uma vez'],
                ['value' => 2, 'label' => 'Diariamente'],
                ['value' => 3, 'label' => 'Personalizado'],
            ],
            $response['capabilities']['alarms']['medication_reminders']['_meta']['frequency']['options'] ?? null
        );
        self::assertSame('takePills', $response['capabilities']['alarms']['medication_reminders']['_nativeKey'] ?? null);
    }

    public function testShowExposesMultipleTakePillsRemindersForFourPTouch(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);
        $db->deviceConfigurations->saveDesired(
            '868017032159118',
            'takePills',
            'four-p-touch',
            '4P Touch',
            'D46',
            'TAKEPILLS',
            [
                'reminderSettings' => '11:25-1-2-14:30-0-1-18:00-1-3-1010',
                'number' => 3,
                'reminderText' => 'meds',
                'voiceData' => 'QUJDRA==',
            ]
        );

        $response = $api->show('868017032159118');

        self::assertSame(
            [
                'reminderSettings' => [
                    ['time' => '11:25', 'enabled' => true, 'frequency' => 2, 'custom' => ''],
                    ['time' => '14:30', 'enabled' => false, 'frequency' => 1, 'custom' => ''],
                    ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '1010'],
                ],
                'number' => 3,
                'reminderText' => 'meds',
                'voiceData' => 'QUJDRA==',
            ],
            $response['capabilities']['alarms']['medication_reminders']['value'] ?? null
        );
    }

    public function testShowExposesVivistarAlarmClockStructuredMeta(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $store->registerDevice('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'reminders',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP85',
            [
                'items' => [
                    [
                        'time' => '08:10',
                        'days' => '135',
                        'enabled' => true,
                        'type' => 2,
                    ],
                ],
            ]
        );

        $response = $api->show('861265061009822');

        self::assertSame(
            [
                [
                    'time' => '08:10',
                    'enabled' => true,
                    'recurrence' => [
                        'kind' => 'custom',
                        'days' => [1, 3, 5],
                    ],
                    'type' => 2,
                ],
            ],
            $response['capabilities']['alarms']['alarm_clock']['value'] ?? null
        );
        self::assertSame(
            [
                [
                    'time' => '08:10',
                    'enabled' => true,
                    'recurrence' => [
                        'kind' => 'custom',
                        'days' => [1, 3, 5],
                    ],
                    'type' => 2,
                ],
            ],
            $response['configurations']['alarm_clock'] ?? null
        );
        self::assertSame(
            [
                ['value' => 1, 'label' => 'Seg'],
                ['value' => 2, 'label' => 'Ter'],
                ['value' => 3, 'label' => 'Qua'],
                ['value' => 4, 'label' => 'Qui'],
                ['value' => 5, 'label' => 'Sex'],
                ['value' => 6, 'label' => 'Sab'],
                ['value' => 7, 'label' => 'Dom'],
            ],
            $response['capabilities']['alarms']['alarm_clock']['_meta']['days']['options'] ?? null
        );
        self::assertSame(
            [
                ['value' => 1, 'label' => 'Medicação'],
                ['value' => 2, 'label' => 'Água'],
                ['value' => 3, 'label' => 'Sedentarismo'],
            ],
            $response['capabilities']['alarms']['alarm_clock']['_meta']['type']['options'] ?? null
        );
    }

    public function testShowExposesFourPTouchAlarmClockStructuredMeta(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);
        $db->deviceConfigurations->saveDesired(
            '868017032159118',
            'alarmClock',
            'four-p-touch',
            '4P Touch',
            'D46',
            'REMIND',
            [
                'alarms' => [
                    ['time' => '08:10', 'enabled' => true, 'frequency' => 1],
                    ['time' => '14:30', 'enabled' => false, 'frequency' => 2],
                    ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '0111110'],
                ],
            ]
        );

        $response = $api->show('868017032159118');

        self::assertSame(
            [
                [
                    'time' => '08:10',
                    'enabled' => true,
                    'recurrence' => ['kind' => 'once'],
                ],
                [
                    'time' => '14:30',
                    'enabled' => false,
                    'recurrence' => ['kind' => 'daily'],
                ],
                [
                    'time' => '18:00',
                    'enabled' => true,
                    'recurrence' => [
                        'kind' => 'custom',
                        'days' => [1, 2, 3, 4, 5],
                    ],
                ],
            ],
            $response['capabilities']['alarms']['alarm_clock']['value'] ?? null
        );
        self::assertSame(3, $response['capabilities']['alarms']['alarm_clock']['_meta']['limit'] ?? null);
        self::assertSame(
            [
                ['value' => 'once', 'label' => 'Uma vez'],
                ['value' => 'daily', 'label' => 'Todos os dias'],
                ['value' => 'custom', 'label' => 'Personalizado'],
            ],
            $response['capabilities']['alarms']['alarm_clock']['_meta']['recurrence']['options'] ?? null
        );
        self::assertSame(
            [
                ['value' => 0, 'label' => 'Dom'],
                ['value' => 1, 'label' => 'Seg'],
                ['value' => 2, 'label' => 'Ter'],
                ['value' => 3, 'label' => 'Qua'],
                ['value' => 4, 'label' => 'Qui'],
                ['value' => 5, 'label' => 'Sex'],
                ['value' => 6, 'label' => 'Sab'],
            ],
            $response['capabilities']['alarms']['alarm_clock']['_meta']['days']['options'] ?? null
        );
        self::assertArrayNotHasKey('type', $response['capabilities']['alarms']['alarm_clock']['_meta'] ?? []);
        self::assertSame(
            [
                [
                    'time' => '08:10',
                    'enabled' => true,
                    'recurrence' => ['kind' => 'once'],
                ],
                [
                    'time' => '14:30',
                    'enabled' => false,
                    'recurrence' => ['kind' => 'daily'],
                ],
                [
                    'time' => '18:00',
                    'enabled' => true,
                    'recurrence' => [
                        'kind' => 'custom',
                        'days' => [1, 2, 3, 4, 5],
                    ],
                ],
            ],
            $response['configurations']['alarm_clock'] ?? null
        );
    }

    public function testConfigurationPutAcceptsGenericAlarmClockAliasForVivistar(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);

        $response = $api->updateConfigurations('861265061009822', json_encode([
            'configurations' => [
                'alarm_clock' => [
                    'items' => [
                        [
                            'time' => '08:10',
                            'enabled' => true,
                            'type' => 2,
                            'recurrence' => ['kind' => 'custom', 'days' => [1, 3, 5]],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('reminders', $response['results'][0]['key'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('861265061009822', $submitted[0]['imei']);
        self::assertStringContainsString('BP85', $submitted[0]['bytes']);
        self::assertSame(
            [
                [
                    'time' => '08:10',
                    'enabled' => true,
                    'recurrence' => ['kind' => 'custom', 'days' => [1, 3, 5]],
                    'type' => 2,
                ],
            ],
            $response['configurations']['alarm_clock'] ?? null
        );
    }

    public function testConfigurationPutAcceptsVivistarAlarmClockOnceWithoutDays(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);

        $response = $api->updateConfigurations('861265061009822', json_encode([
            'configurations' => [
                'alarm_clock' => [
                    'items' => [
                        [
                            'time' => '09:00',
                            'enabled' => true,
                            'type' => 1,
                            'recurrence' => ['kind' => 'once'],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('reminders', $response['results'][0]['key'] ?? null);
        self::assertCount(1, $submitted);
        self::assertStringContainsString('0900,,1,1', $submitted[0]['bytes']);
        self::assertSame(
            [
                [
                    'time' => '09:00',
                    'enabled' => true,
                    'recurrence' => ['kind' => 'once'],
                    'type' => 1,
                ],
            ],
            $response['configurations']['alarm_clock'] ?? null
        );
    }

    public function testConfigurationPutAcceptsVivistarWhitelistEnabledToggleAsBp84(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['whitelist_enabled']);

        $response = $api->updateConfigurations('861265061009822', json_encode([
            'configurations' => [
                'whitelist_enabled' => [
                    'enabled' => false,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('whitelist_enabled', $response['results'][0]['key'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('861265061009822', $submitted[0]['imei']);
        self::assertStringContainsString('BP84', $submitted[0]['bytes']);
        self::assertStringContainsString(',0#', $submitted[0]['bytes']);
        self::assertSame(
            ['enabled' => false],
            $response['configurations']['whitelist_enabled'] ?? null
        );
    }

    public function testShowMapsVivistarEmptyDaysToOnceRecurrence(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $store->registerDevice('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'reminders',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP85',
            [
                'items' => [
                    [
                        'time' => '09:00',
                        'enabled' => true,
                        'type' => 1,
                        'days' => '',
                    ],
                ],
            ]
        );

        $response = $api->show('861265061009822');

        self::assertSame(
            [
                [
                    'time' => '09:00',
                    'enabled' => true,
                    'recurrence' => ['kind' => 'once'],
                    'type' => 1,
                ],
            ],
            $response['capabilities']['alarms']['alarm_clock']['value'] ?? null
        );
    }

    public function testFourPTouchSosContactsCapabilitySavesNativeSplitWithoutArrayCoercion(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });

        [$api, $db, $store] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');
        self::assertIsArray($model);
        $store->registerDevice('861728087060467', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['sos_contacts']);

        $response = $api->updateConfigurations('861728087060467', json_encode([
            'capabilities' => [
                'contacts' => [
                    'sos_contacts' => [
                        '+351938854803',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('861728087060467', $submitted[0]['imei']);
        self::assertStringContainsString('SOS1,+351938854803', $submitted[0]['bytes']);
        self::assertSame(
            ['phone' => '+351938854803'],
            $db->deviceConfigurations->allForImei('861728087060467')[0]['desired_payload'] ?? null
        );
    }

    public function testFourPTouchSosContactsRejectsRepeatedNumbers(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');
        self::assertIsArray($model);
        $store->registerDevice('861728087060467', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['sos_contacts']);

        $response = $api->updateConfigurations('861728087060467', json_encode([
            'capabilities' => [
                'contacts' => [
                    'sos_contacts' => [
                        '+351938854803',
                        '+351938854803',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
        self::assertSame('numbers must not contain repeated values', $response['error']['message'] ?? null);
    }

    public function testFourPTouchCallWhitelistRejectsRepeatedNumbers(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');
        self::assertIsArray($model);
        $store->registerDevice('861728087060467', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['call_whitelist']);

        $response = $api->updateConfigurations('861728087060467', json_encode([
            'capabilities' => [
                'contacts' => [
                    'call_whitelist' => [
                        '+351922222222',
                        '+351922222222',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
        self::assertSame('numbers must not contain repeated values', $response['error']['message'] ?? null);
    }

    public function testConfigurationPutRejectsVivistarAlarmClockWithoutType(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);

        $response = $api->updateConfigurations('861265061009822', json_encode([
            'configurations' => [
                'alarm_clock' => [
                    'items' => [
                        [
                            'time' => '08:10',
                            'enabled' => true,
                            'recurrence' => ['kind' => 'custom', 'days' => [1, 3, 5]],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
        self::assertSame('type is required', $response['error']['message'] ?? null);
    }

    public function testShowReturnsAbsoluteModelImageUrl(): void
    {
        [$api, $db] = $this->makeApi();
        $supplier = $db->suppliers->findByName('Vivistar');
        self::assertIsArray($supplier);

        $db->models->add((int)$supplier['id'], 'L08 Pro', 'L08 Pro', 'watch', '/model-images/example.jpg');

        $response = $api->show('861265061009822', null, 'http://localhost:8081');

        self::assertSame('http://localhost:8081/model-images/example.jpg', $response['model']['image'] ?? null);
    }

    public function testShowReturnsConfigPendingAndTransportPendingSeparately(): void
    {
        $queue = new class implements PendingDownlinkQueue {
            public function enqueue(string $imei, string $bytes, ?array $command, int $ttlSeconds): PendingDownlink
            {
                return new PendingDownlink($imei, 'dedupe', $bytes, $command, time(), time() + $ttlSeconds);
            }

            public function pendingFor(string $imei): array
            {
                return [
                    new PendingDownlink($imei, 'cfg:BP76', 'IWBP76,...', ['command' => 'BP76'], 1719650000, 1719650300),
                ];
            }

            public function remove(PendingDownlink $downlink): void
            {
            }
        };
        [$api, $db] = $this->makeApi(queue: $queue);
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['fall_detection']);
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'fallDetection',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP76',
            ['enabled' => true],
            'waiting',
            'cmd-1'
        );

        $response = $api->show('861265061009822');

        self::assertSame('waiting_device', $response['pending']['alarms']['fall_detection']['status'] ?? null);
        self::assertSame(['enabled' => true], $response['pending']['alarms']['fall_detection']['desired'] ?? null);
        self::assertSame('cmd-1', $response['pending']['alarms']['fall_detection']['lastCommandId'] ?? null);
        self::assertSame('cfg:BP76', $response['transportPending'][0]['dedupeKey'] ?? null);
    }

    public function testConfigurationPutSendsDownlinksForProtocolConfigKeys(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['fall_detection', 'call_whitelist']);
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'fallDetection',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP76',
            ['enabled' => false]
        );
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'call_whitelist',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP14',
            ['contacts' => [['name' => 'Ana', 'phone' => '+351911111111']]]
        );

        $response = $api->updateConfigurations('861265061009822', json_encode([
            'configurations' => [
                'fallDetection' => ['enabled' => true],
                'call_whitelist' => [
                    'contacts' => [
                        ['name' => 'Ana', 'phone' => '+351911111111'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(2, $submitted);
        self::assertSame('861265061009822', $submitted[0]['imei']);
        self::assertStringContainsString('BP76', $submitted[0]['bytes']);
        self::assertStringContainsString('BP14', $submitted[1]['bytes']);
        self::assertCount(2, $response['results'] ?? []);
        self::assertSame('fallDetection', $response['results'][0]['key'] ?? null);
        self::assertSame('call_whitelist', $response['results'][1]['key'] ?? null);
        self::assertSame(['enabled' => true], $response['configurations']['fall_detection'] ?? null);
        self::assertSame('waiting_device', $response['pending']['alarms']['fall_detection']['status'] ?? null);
    }

    public function testConfigurationPutSendsFourPTouchTakePillsDownlink(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db, $store] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                'medication_reminders' => [
                    'reminderSettings' => [
                        'time' => '11:25',
                        'enabled' => true,
                        'frequency' => 3,
                        'custom' => '1010',
                    ],
                    'number' => 3,
                    'reminderText' => 'meds',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('868017032159118', $submitted[0]['imei']);
        self::assertStringContainsString('TAKEPILLS,11:25-1-3-1010,3,006D006500640073', $submitted[0]['bytes']);
    }

    public function testConfigurationPutAcceptsLocationReportingIntervalAliasForFourPTouch(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['location_reporting_interval']);

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                'location_reporting_interval' => [
                    'intervalSeconds' => 3600,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('868017032159118', $submitted[0]['imei']);
        self::assertSame('location_reporting_interval', $response['results'][0]['key'] ?? null);
        self::assertStringContainsString('UPLOAD,3600', $submitted[0]['bytes']);
    }

    public function testConfigurationPutAcceptsCenterNumberAliasForFourPTouch(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['center_number']);

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                'center_number' => [
                    'phone' => '351911111111',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('868017032159118', $submitted[0]['imei']);
        self::assertSame('center_number', $response['results'][0]['key'] ?? null);
        self::assertStringContainsString('CENTER,351911111111', $submitted[0]['bytes']);
    }

    public function testConfigurationPutSendsFourPTouchAlarmClockDownlink(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                'alarmClock' => [
                    'alarms' => [
                        ['time' => '08:10', 'enabled' => true, 'frequency' => 1],
                        ['time' => '14:30', 'enabled' => false, 'frequency' => 2],
                        ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '0111110'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('868017032159118', $submitted[0]['imei']);
        self::assertStringContainsString('REMIND,08:10-1-1,14:30-0-2,18:00-1-3-0111110', $submitted[0]['bytes']);
    }

    public function testShowPrefersLatestVivistarAlarmClockRowOverLegacyAlias(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $store->registerDevice('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'alarm_clock',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP85',
            [
                'items' => [
                    [
                        'time' => '08:10',
                        'days' => '1',
                        'enabled' => true,
                        'type' => 1,
                    ],
                ],
            ],
            'queued',
            'legacy-1'
        );
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'reminders',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP85',
            [
                'items' => [
                    [
                        'time' => '09:30',
                        'days' => '24',
                        'enabled' => true,
                        'type' => 2,
                    ],
                ],
            ],
            'queued',
            'native-1'
        );

        $response = $api->show('861265061009822');

        self::assertSame(
            [
                [
                    'time' => '09:30',
                    'enabled' => true,
                    'recurrence' => [
                        'kind' => 'custom',
                        'days' => [2, 4],
                    ],
                    'type' => 2,
                ],
            ],
            $response['configurations']['alarm_clock'] ?? null
        );
    }

    public function testShowPrefersLatestFourPTouchAlarmClockRowOverLegacyAlias(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);
        $db->deviceConfigurations->saveDesired(
            '868017032159118',
            'alarm_clock',
            'four-p-touch',
            '4P Touch',
            'D46',
            'REMIND',
            [
                'items' => [
                    [
                        'time' => '08:10',
                        'enabled' => true,
                        'recurrence' => ['kind' => 'once'],
                    ],
                ],
            ],
            'queued',
            'legacy-1'
        );
        $db->deviceConfigurations->saveDesired(
            '868017032159118',
            'alarmClock',
            'four-p-touch',
            '4P Touch',
            'D46',
            'REMIND',
            [
                'alarms' => [
                    [
                        'time' => '10:45',
                        'enabled' => false,
                        'frequency' => 3,
                        'custom' => '0111110',
                    ],
                ],
            ],
            'queued',
            'native-1'
        );

        $response = $api->show('868017032159118');

        self::assertSame(
            [
                [
                    'time' => '10:45',
                    'enabled' => false,
                    'recurrence' => [
                        'kind' => 'custom',
                        'days' => [1, 2, 3, 4, 5],
                    ],
                ],
            ],
            $response['configurations']['alarm_clock'] ?? null
        );
    }

    public function testConfigurationPutAcceptsGenericAlarmClockAliasForFourPTouch(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                    'alarm_clock' => [
                        'items' => [
                            [
                                'time' => '08:10',
                                'enabled' => true,
                                'recurrence' => ['kind' => 'once'],
                            ],
                        ],
                    ],
                ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('alarmClock', $response['results'][0]['key'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('868017032159118', $submitted[0]['imei']);
        self::assertStringContainsString('REMIND,08:10-1-1', $submitted[0]['bytes']);
        self::assertSame(
            [
                [
                    'time' => '08:10',
                    'enabled' => true,
                    'recurrence' => ['kind' => 'once'],
                ],
            ],
            $response['configurations']['alarm_clock'] ?? null
        );
    }

    public function testConfigurationPutAcceptsGenericFallSensitivityAliasForFourPTouch(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['fall_sensitivity']);

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                'fall_sensitivity' => [
                    'sensitivity' => 4,
                    'levels' => 6,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('fall_sensitivity', $response['results'][0]['key'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('868017032159118', $submitted[0]['imei']);
        self::assertStringContainsString('LSSET,4+6', $submitted[0]['bytes']);
    }

    public function testConfigurationPutAcceptsGenericCallWhitelistAliasForFourPTouch(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db, $store] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['call_whitelist']);
        $store->registerDevice('637507597567372', '4P Touch', 'D46', 'watch', 0, '', '7597567372', 'hitcare');

        $response = $api->updateConfigurations('637507597567372', json_encode([
            'configurations' => [
                'call_whitelist' => [
                    '+351911111111',
                    '+351922222222',
                    '+351933333333',
                    '+351944444444',
                    '+351955555555',
                    '+351966666666',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('call_whitelist', $response['results'][0]['key'] ?? null);
        self::assertCount(2, $response['results'][0]['operations'] ?? []);
        self::assertCount(2, $submitted);
        self::assertStringContainsString('WHITELIST1', $submitted[0]['bytes']);
        self::assertStringContainsString('WHITELIST2', $submitted[1]['bytes']);
        self::assertSame(
            ['numbers' => ['+351911111111', '+351922222222', '+351933333333', '+351944444444', '+351955555555']],
            $db->deviceConfigurations->allForImei('637507597567372')[0]['desired_payload'] ?? null
        );
        self::assertSame(
            ['numbers' => ['+351966666666']],
            $db->deviceConfigurations->allForImei('637507597567372')[1]['desired_payload'] ?? null
        );
    }

    public function testConfigurationPutAcceptsPhonebookContactsWrapperForFourPTouch(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db, $store] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['call_whitelist']);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                'phonebook' => [
                    'contacts' => [
                        ['name' => 'Ana', 'phone' => '123456789'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('phonebook', $response['results'][0]['key'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('868017032159118', $submitted[0]['imei']);
        self::assertStringContainsString('PHB,123456789,0041006E0061', $submitted[0]['bytes']);
        self::assertSame(
            ['contacts' => [['name' => 'Ana', 'phone' => '123456789']]],
            $db->deviceConfigurations->allForImei('868017032159118')[0]['desired_payload'] ?? null
        );
    }

    public function testConfigurationPutAcceptsEmptyPhonebookContactsForFourPTouch(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db, $store] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['phonebook']);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                'phonebook' => [
                    'contacts' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('phonebook', $response['results'][0]['key'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('868017032159118', $submitted[0]['imei']);
        self::assertStringContainsString('PHB', $submitted[0]['bytes']);
        self::assertSame(
            ['contacts' => []],
            $db->deviceConfigurations->allForImei('868017032159118')[0]['desired_payload'] ?? null
        );
    }

    public function testConfigurationPutAcceptsEmptyAlarmClockItemsForFourPTouch(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db, $store] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                'alarm_clock' => [
                    'items' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('alarmClock', $response['results'][0]['key'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('868017032159118', $submitted[0]['imei']);
        self::assertStringContainsString('REMIND', $submitted[0]['bytes'] ?? '');
        self::assertSame(
            ['alarms' => []],
            $db->deviceConfigurations->allForImei('868017032159118')[0]['desired_payload'] ?? null
        );
    }

    public function testConfigurationPutAcceptsEmptySosContactsForFourPTouch(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db, $store] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['sos_contacts']);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                'sos_contacts' => [],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('sos_contacts', $response['results'][0]['key'] ?? null);
        self::assertCount(3, $submitted);
        self::assertStringContainsString('SOS1', $submitted[0]['bytes']);
        self::assertStringContainsString('SOS2', $submitted[1]['bytes']);
        self::assertStringContainsString('SOS3', $submitted[2]['bytes']);
        self::assertSame(
            ['phone' => ''],
            $db->deviceConfigurations->allForImei('868017032159118')[0]['desired_payload'] ?? null
        );
    }

    public function testConfigurationPutRejectsFourPTouchAlarmClockType(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                'alarm_clock' => [
                    'items' => [
                        [
                            'time' => '08:10',
                            'enabled' => true,
                            'type' => 1,
                            'recurrence' => ['kind' => 'once'],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
        self::assertSame('type is not supported for four-p-touch alarm_clock', $response['error']['message'] ?? null);
    }

    public function testConfigurationPutAllowsFourPTouchLanguageZeroForEnglish(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['language_timezone']);

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                'languageTimezone' => [
                    'language' => 0,
                    'timeZone' => '0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(1, $submitted);
        self::assertStringContainsString('LZ,0,0', $submitted[0]['bytes']);
    }

    public function testConfigurationPutSendsFourPTouchTakePillsWithMultipleReminders(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                'medication_reminders' => [
                    'reminderSettings' => [
                        ['time' => '11:25', 'enabled' => true, 'frequency' => 2, 'custom' => ''],
                        ['time' => '14:30', 'enabled' => false, 'frequency' => 1, 'custom' => ''],
                        ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '1010'],
                    ],
                    'number' => 3,
                    'reminderText' => 'meds',
                    'voiceData' => '',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(1, $submitted);
        self::assertStringContainsString('TAKEPILLS,11:25-1-2-14:30-0-1-18:00-1-3-1010,3,006D006500640073]', $submitted[0]['bytes']);
    }

    public function testConfigurationPutRejectsInvalidRequestWithoutConfigurations(): void
    {
        [$api] = $this->makeApi();

        $response = $api->updateConfigurations('861265061009822', json_encode([], JSON_THROW_ON_ERROR));

        self::assertSame('invalid_request', $response['error']['code'] ?? null);
    }

    public function testConfigurationPutRejectsUnknownConfigKey(): void
    {
        [$api] = $this->makeApi();

        $response = $api->updateConfigurations('861265061009822', json_encode([
            'configurations' => [
                'unknownConfig' => ['enabled' => true],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
    }

    public function testConfigurationPutRejectsInvalidPayload(): void
    {
        [$api] = $this->makeApi();

        $response = $api->updateConfigurations('861265061009822', json_encode([
            'configurations' => [
                'workingMode' => [
                    'mode' => 8,
                    'intervalSeconds' => 10,
                    'gpsEnabled' => true,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
        self::assertSame('intervalSeconds must be at least 30 for mode 8', $response['error']['message'] ?? null);
    }

    public function testPushMessageRequestSendsTransientBp40WithoutPersistingConfiguration(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['push_message']);

        $response = $api->requestFeature('861265061009822', json_encode([
            'capability' => 'push_message',
            'value' => ['message' => 'are you ok?'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('waiting', $response['status'] ?? null);
        self::assertSame('push_message', $response['capability'] ?? null);
        self::assertSame('BP40', $response['commands'][0]['nativeType'] ?? null);
        self::assertSame('push_message', $response['commands'][0]['capability'] ?? null);
        self::assertSame(['AP40'], $response['commands'][0]['expectedReplyTypes'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('861265061009822', $submitted[0]['imei']);
        self::assertStringStartsWith('IWBP40,861265061009822,', $submitted[0]['bytes']);
        self::assertStringEndsWith(',00610072006500200079006F00750020006F006B003F#', $submitted[0]['bytes']);
        self::assertSame([], $db->deviceConfigurations->allForImei('861265061009822'));
    }

    public function testPushMessageRequestRejectsDisabledModelCapability(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['heart_rate']);

        $response = $api->requestFeature('861265061009822', json_encode([
            'capability' => 'push_message',
            'value' => ['message' => 'are you ok?'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('unsupported_feature', $response['error']['code'] ?? null);
    }

    public function testPushMessageCannotBeSavedAsPersistentConfiguration(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['push_message']);

        $response = $api->updateConfigurations('861265061009822', json_encode([
            'configurations' => [
                'pushMessage' => ['message' => 'are you ok?'],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
        self::assertSame([], $db->deviceConfigurations->allForImei('861265061009822'));
    }

    public function testFourPTouchCallWhitelistCapabilitySaveFansOutToNativeCommands(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db, $store] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['call_whitelist']);
        $store->registerDevice('637507597567372', '4P Touch', 'D46', 'watch', 0, '', '7597567372', 'hitcare');

        $response = $api->updateConfigurations('637507597567372', json_encode([
            'capabilities' => [
                'contacts' => [
                    'call_whitelist' => [
                        '111',
                        '222',
                        '333',
                        '444',
                        '555',
                        '666',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(2, $response['changed']['contacts.call_whitelist']['operations'] ?? []);
        self::assertCount(2, $submitted);
        self::assertStringContainsString('WHITELIST1', $submitted[0]['bytes']);
        self::assertStringContainsString('WHITELIST2', $submitted[1]['bytes']);
        self::assertSame(
            ['111', '222', '333', '444', '555', '666'],
            $response['capabilities']['contacts']['call_whitelist']['value'] ?? null
        );
        $savedRows = $db->deviceConfigurations->allForImei('637507597567372');
        self::assertCount(2, $savedRows);
        self::assertSame(['numbers' => ['111', '222', '333', '444', '555']], $savedRows[0]['desired_payload'] ?? null);
        self::assertSame(['numbers' => ['666']], $savedRows[1]['desired_payload'] ?? null);
    }

    public function testFourPTouchAlarmClockCapabilitySaveFansOutToNativeCommand(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db, $store] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'capabilities' => [
                'alarms' => [
                    'alarm_clock' => [
                        'items' => [
                            [
                                'time' => '08:10',
                                'enabled' => true,
                                'recurrence' => ['kind' => 'daily'],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(1, $submitted);
        self::assertStringContainsString('REMIND', $submitted[0]['bytes']);
        self::assertSame(
            [
                [
                    'time' => '08:10',
                    'enabled' => true,
                    'recurrence' => ['kind' => 'daily'],
                ],
            ],
            $response['capabilities']['alarms']['alarm_clock']['value'] ?? null
        );
    }

    public function testRecentReturnsTelemetryEventsAndCommands(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['heart_rate']);
        $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 72]);
        $store->append('861265061009822', 'events', ['type' => 'sos', 'status' => 'triggered']);
        $api->requestFeature('861265061009822', json_encode(['feature' => 'heart_rate'], JSON_THROW_ON_ERROR));

        $response = $api->recent('861265061009822');

        self::assertSame('heart_rate', $response['telemetry'][0]['type'] ?? null);
        self::assertSame(72, $response['telemetry'][0]['value'] ?? null);
        self::assertSame('sos', $response['events'][0]['type'] ?? null);
        self::assertSame('BPXL', $response['commands'][0]['requestId'] ?? null);
    }

    public function testRequestFeatureSendsGenericTelemetryRequest(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['heart_rate']);

        $response = $api->requestFeature('861265061009822', json_encode(['feature' => 'heart_rate'], JSON_THROW_ON_ERROR));

        self::assertSame('waiting', $response['status'] ?? null);
        self::assertSame('heart_rate', $response['feature'] ?? null);
        self::assertCount(1, $submitted);
        self::assertStringContainsString('BPXL', $submitted[0]['bytes']);
    }

    public function testRequestFeatureRejectsNonRequestableTelemetry(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['call_whitelist']);

        $response = $api->requestFeature('861265061009822', json_encode(['feature' => 'heart_rate'], JSON_THROW_ON_ERROR));

        self::assertSame('unsupported_feature', $response['error']['code'] ?? null);
    }

    public function testCommandStatusReturnsStoredCommandById(): void
    {
        [$api] = $this->makeApi();

        $created = $api->requestFeature('861265061009822', json_encode(['feature' => 'heart_rate'], JSON_THROW_ON_ERROR));
        $id = (string)($created['commands'][0]['id'] ?? '');

        $response = $api->commandStatus($id);

        self::assertSame('861265061009822', $response['device']['imei'] ?? null);
        self::assertSame($id, $response['command']['id'] ?? null);
        self::assertSame('BPXL', $response['command']['requestId'] ?? null);
    }

    public function testUpdateRejectsConfigurationPayloadsOnMetadataEndpoint(): void
    {
        [$api] = $this->makeApi();

        $response = $api->update('861265061009822', json_encode([
            'configurations' => [
                'fallDetection' => ['enabled' => true],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('invalid_request', $response['error']['code'] ?? null);

        $legacyConfigs = $api->update('861265061009822', json_encode([
            'configs' => [
                'fallDetection' => ['enabled' => true],
            ],
        ], JSON_THROW_ON_ERROR));
        self::assertSame('invalid_request', $legacyConfigs['error']['code'] ?? null);

        $legacyCapabilities = $api->update('861265061009822', json_encode([
            'capabilities' => [
                'alarms' => [
                    'fall_detection' => ['enabled' => true],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        self::assertSame('invalid_request', $legacyCapabilities['error']['code'] ?? null);
    }

    /**
     * @return array{0: DeviceService, 1: ApiDataAccess, 2: DashboardStore}
     */
    private function makeApi(?\Hub\DeviceHubServer $hub = null, ?PendingDownlinkQueue $queue = null): array
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $store = new DashboardStore(new InMemoryRedisClientForDevicesApi(), prefix: 'test:dashboard:devices-api');
        $store->setDataAccess($db);
        $store->registerDevice('861265061009822', 'Vivistar', 'L08 Pro');
        $whitelist = new Whitelist($this->whitelistPath);

        $api = new DeviceService(
            $store,
            $whitelist,
            $hub ?? $this->makeHubServerMock(),
            $queue,
            $db
        );

        return [$api, $db, $store];
    }

    private function makeHubServerMock(): \Hub\DeviceHubServer
    {
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturn('sent');

        return $hub;
    }

    private function sampleWavBase64(): string
    {
        $sampleRate = 8000;
        $channels = 1;
        $bitsPerSample = 16;
        $data = str_repeat(pack('v', 0), 800);

        $byteRate = (int)($sampleRate * $channels * ($bitsPerSample / 8));
        $blockAlign = (int)($channels * ($bitsPerSample / 8));
        $header = 'RIFF'
            . pack('V', 36 + strlen($data))
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)
            . pack('v', 1)
            . pack('v', $channels)
            . pack('V', $sampleRate)
            . pack('V', $byteRate)
            . pack('v', $blockAlign)
            . pack('v', $bitsPerSample)
            . 'data'
            . pack('V', strlen($data));

        return base64_encode($header . $data);
    }
}

final class InMemoryRedisClientForDevicesApi implements ClientInterface
{
    /** @var array<string, array<string, bool>> */
    private array $sets = [];

    /** @var array<string, array<string, string>> */
    private array $hashes = [];

    /** @var array<string, array<int, string>> */
    private array $lists = [];

    /** @var array<string, string> */
    private array $strings = [];

    /** @var array<string, array<string, float>> */
    private array $sortedSets = [];

    public function getCommandFactory()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function getOptions()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function connect()
    {
    }

    public function disconnect()
    {
    }

    public function getConnection()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function createCommand($method, $arguments = [])
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function executeCommand(CommandInterface $command)
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function pipeline(callable $callback): void
    {
        $callback($this);
    }

    public function __call($method, $arguments)
    {
        return match (strtolower((string)$method)) {
            'sadd' => $this->sadd((string)$arguments[0], (string)$arguments[1]),
            'srem' => $this->srem((string)$arguments[0], (string)$arguments[1]),
            'smembers' => $this->smembers((string)$arguments[0]),
            'hmset' => $this->hmset((string)$arguments[0], $arguments[1]),
            'hgetall' => $this->hgetall((string)$arguments[0]),
            'hset' => $this->hset((string)$arguments[0], (string)$arguments[1], (string)$arguments[2]),
            'hdel' => $this->hdel((string)$arguments[0], $arguments[1]),
            'hget' => $this->hget((string)$arguments[0], (string)$arguments[1]),
            'lpush' => $this->lpush((string)$arguments[0], $arguments[1]),
            'ltrim' => $this->ltrim((string)$arguments[0], (int)$arguments[1], (int)$arguments[2]),
            'lrange' => $this->lrange((string)$arguments[0], (int)$arguments[1], (int)$arguments[2]),
            'lrem' => $this->lrem((string)$arguments[0], (int)$arguments[1], (string)$arguments[2]),
            'zadd' => $this->zadd((string)$arguments[0], $arguments[1]),
            'zrem' => $this->zrem((string)$arguments[0], $arguments[1]),
            'zrangebyscore' => $this->zrangebyscore((string)$arguments[0], (string)$arguments[1], (string)$arguments[2]),
            'setex' => $this->setex((string)$arguments[0], (int)$arguments[1], (string)$arguments[2]),
            'get' => $this->get((string)$arguments[0]),
            'del' => $this->del($arguments[0]),
            default => throw new \BadMethodCallException("Redis method {$method} is not implemented"),
        };
    }

    private function sadd(string $key, string $member): int
    {
        $exists = isset($this->sets[$key][$member]);
        $this->sets[$key][$member] = true;

        return $exists ? 0 : 1;
    }

    private function srem(string $key, string $member): int
    {
        $exists = isset($this->sets[$key][$member]);
        unset($this->sets[$key][$member]);

        return $exists ? 1 : 0;
    }

    private function smembers(string $key): array
    {
        return array_keys($this->sets[$key] ?? []);
    }

    private function hmset(string $key, array $dictionary): string
    {
        $this->hashes[$key] = array_merge($this->hashes[$key] ?? [], array_map('strval', $dictionary));

        return 'OK';
    }

    private function hgetall(string $key): array
    {
        return $this->hashes[$key] ?? [];
    }

    private function hset(string $key, string $field, string $value): int
    {
        $exists = isset($this->hashes[$key][$field]);
        $this->hashes[$key][$field] = $value;

        return $exists ? 0 : 1;
    }

    private function hget(string $key, string $field): ?string
    {
        return $this->hashes[$key][$field] ?? null;
    }

    private function hdel(string $key, array|string $fields): int
    {
        $removed = 0;
        foreach ((array)$fields as $field) {
            if (isset($this->hashes[$key][(string)$field])) {
                unset($this->hashes[$key][(string)$field]);
                $removed++;
            }
        }

        return $removed;
    }

    private function lpush(string $key, array $values): int
    {
        $this->lists[$key] ??= [];
        foreach ($values as $value) {
            array_unshift($this->lists[$key], (string)$value);
        }

        return count($this->lists[$key]);
    }

    private function ltrim(string $key, int $start, int $stop): string
    {
        $this->lists[$key] = array_slice($this->lists[$key] ?? [], $start, $stop - $start + 1);

        return 'OK';
    }

    private function lrange(string $key, int $start, int $stop): array
    {
        return array_slice($this->lists[$key] ?? [], $start, $stop - $start + 1);
    }

    private function lrem(string $key, int $count, string $value): int
    {
        $removed = 0;
        $this->lists[$key] = array_values(array_filter(
            $this->lists[$key] ?? [],
            static function (string $item) use ($value, $count, &$removed): bool {
                if ($item !== $value || ($count > 0 && $removed >= $count)) {
                    return true;
                }
                $removed++;
                return false;
            }
        ));

        return $removed;
    }

    private function zadd(string $key, array $members): int
    {
        $added = 0;
        $this->sortedSets[$key] ??= [];
        foreach ($members as $member => $score) {
            $member = (string)$member;
            if (!isset($this->sortedSets[$key][$member])) {
                $added++;
            }
            $this->sortedSets[$key][$member] = (float)$score;
        }

        return $added;
    }

    private function zrem(string $key, array|string $members): int
    {
        $removed = 0;
        foreach ((array)$members as $member) {
            $member = (string)$member;
            if (isset($this->sortedSets[$key][$member])) {
                unset($this->sortedSets[$key][$member]);
                $removed++;
            }
        }

        return $removed;
    }

    private function zrangebyscore(string $key, string $min, string $max): array
    {
        $minScore = $min === '-inf' ? -INF : (float)$min;
        $maxScore = $max === '+inf' ? INF : (float)$max;
        $matches = [];
        foreach ($this->sortedSets[$key] ?? [] as $member => $score) {
            if ($score < $minScore || $score > $maxScore) {
                continue;
            }
            $matches[$member] = $score;
        }
        asort($matches, SORT_NUMERIC);

        return array_keys($matches);
    }

    private function setex(string $key, int $ttlSeconds, string $value): string
    {
        $this->strings[$key] = $value;

        return 'OK';
    }

    private function get(string $key): ?string
    {
        return $this->strings[$key] ?? null;
    }

    private function del(array|string $keys): int
    {
        $removed = 0;
        foreach ((array)$keys as $key) {
            $removed += isset($this->hashes[$key]) || isset($this->lists[$key]) || isset($this->sets[$key]) || isset($this->strings[$key]) || isset($this->sortedSets[$key]) ? 1 : 0;
            unset($this->hashes[$key], $this->lists[$key], $this->sets[$key], $this->strings[$key], $this->sortedSets[$key]);
        }

        return $removed;
    }
}
