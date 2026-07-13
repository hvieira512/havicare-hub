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
            'phonebook',
            'call_whitelist',
            'device_password',
        ]);
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'phonebook',
            'vivistar',
            'Vivistar',
            'L08 Pro',
            'PB',
            ['contacts' => [['name' => 'Ana', 'phone' => '+351911111111']]]
        );
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'whitelistSwitch',
            'vivistar',
            'Vivistar',
            'L08 Pro',
            'WHITELIST_SWITCH',
            ['enabled' => true]
        );
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'whitelistGroup1',
            'vivistar',
            'Vivistar',
            'L08 Pro',
            'WHITELIST_GROUP_1',
            ['numbers' => ['+351922222222', '+351933333333']]
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
            [['name' => 'Ana', 'phone' => '+351911111111']],
            $response['capabilities']['contacts']['phonebook']['value'] ?? null
        );
        self::assertSame(10, $response['capabilities']['contacts']['phonebook']['_meta']['limit'] ?? null);
        self::assertTrue($response['capabilities']['contacts']['call_whitelist']['enabled'] ?? false);
        self::assertSame(
            ['+351922222222', '+351933333333'],
            $response['capabilities']['contacts']['call_whitelist']['numbers'] ?? null
        );
        self::assertSame(
            ['password' => '2468'],
            $response['capabilities']['settings_system']['device_password'] ?? null
        );
        self::assertSame([], $response['capabilities']['health'] ?? null);
        self::assertSame([], $response['capabilities']['alarms'] ?? null);
        self::assertSame('never_reported', $response['pending']['contacts']['phonebook']['status'] ?? null);
        self::assertSame('never_reported', $response['pending']['contacts']['call_whitelist']['status'] ?? null);
        self::assertSame('never_reported', $response['pending']['settings_system']['device_password']['status'] ?? null);
        self::assertSame([], $response['transportPending'] ?? null);
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
                    'type' => 2,
                    'recurrence' => [
                        'kind' => 'custom',
                        'days' => [1, 3, 5],
                    ],
                ],
            ],
            $response['capabilities']['alarms']['alarm_clock']['items'] ?? null
        );
        self::assertSame(
            [
                'items' => [
                    [
                        'time' => '08:10',
                        'enabled' => true,
                        'type' => 2,
                        'recurrence' => [
                            'kind' => 'custom',
                            'days' => [1, 3, 5],
                        ],
                    ],
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
            $response['capabilities']['alarms']['alarm_clock']['items'] ?? null
        );
        self::assertSame(3, $response['capabilities']['alarms']['alarm_clock']['_meta']['limit'] ?? null);
        self::assertSame(
            [
                ['value' => 1, 'label' => 'Uma vez'],
                ['value' => 2, 'label' => 'Todos os dias'],
                ['value' => 3, 'label' => 'Personalizado'],
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
                'items' => [
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
                'items' => [
                    [
                        'time' => '08:10',
                        'enabled' => true,
                        'type' => 2,
                        'recurrence' => ['kind' => 'custom', 'days' => [1, 3, 5]],
                    ],
                ],
            ],
            $response['configurations']['alarm_clock'] ?? null
        );
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
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['fall_detection', 'phonebook']);
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
            'phonebook',
            'vivistar-iw',
            'Vivistar',
            'L08 Pro',
            'BP14',
            ['contacts' => [['name' => 'Ana', 'phone' => '+351911111111']]]
        );

        $response = $api->updateConfigurations('861265061009822', json_encode([
            'configurations' => [
                'fallDetection' => ['enabled' => true],
                'phonebook' => [
                    'contacts' => [
                        ['name' => 'Ana', 'phone' => '+351911111111'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('861265061009822', $submitted[0]['imei']);
        self::assertStringContainsString('BP76', $submitted[0]['bytes']);
        self::assertCount(1, $response['results'] ?? []);
        self::assertSame('fallDetection', $response['results'][0]['key'] ?? null);
        self::assertSame(['enabled' => true], $response['configurations']['fallDetection'] ?? null);
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
        [$api, $db] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);

        $response = $api->updateConfigurations('868017032159118', json_encode([
            'configurations' => [
                'takePills' => [
                    'reminderSettings' => [
                        'time' => '11:25',
                        'enabled' => true,
                        'frequency' => 3,
                        'custom' => '1010',
                    ],
                    'number' => 3,
                    'reminderText' => 'meds',
                    'voiceData' => 'data:audio/wav;base64,' . $this->sampleWavBase64(),
                    'voiceMimeType' => 'audio/wav',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('868017032159118', $submitted[0]['imei']);
        self::assertStringContainsString('TAKEPILLS,11:25-1-3-1010,3,006D006500640073,IyFBTVIK', $submitted[0]['bytes']);
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
                            'type' => 1,
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
                'items' => [
                    [
                        'time' => '08:10',
                        'enabled' => true,
                        'type' => 1,
                        'recurrence' => ['kind' => 'once'],
                    ],
                ],
            ],
            $response['configurations']['alarm_clock'] ?? null
        );
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
                'takePills' => [
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
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['phonebook']);

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
