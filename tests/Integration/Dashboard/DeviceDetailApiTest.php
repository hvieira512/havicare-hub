<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Tests\Support\ConfigurationFixtures;
use Tests\Support\DevicesApiFixture;
use Tests\Support\MysqlDashboardTestCase;

final class DeviceDetailApiTest extends MysqlDashboardTestCase
{
    use DevicesApiFixture;

    public function testShowDoesNotExposePhonebookForVivistarDevices(): void
    {
        [$api] = $this->makeApi();

        $response = $api->show('861265061009822', null, '');

        self::assertArrayNotHasKey('phonebook', $response['capabilities']['contacts'] ?? []);
        self::assertArrayHasKey('call_whitelist', $response['capabilities']['contacts'] ?? []);
    }

    public function testShowDoesNotLeakLegacyRedisDeviceFields(): void
    {
        [$api, , $store] = $this->makeApi();
        $store->deviceSeen('861265061009822', [
            'online' => '1',
            'software' => 'null',
            'sourceSystem' => 'legacy',
            'sourceDeviceId' => 'legacy-device',
        ]);

        $response = $api->show('861265061009822', null, '');
        $device = $response['device'] ?? [];

        self::assertTrue($device['online'] ?? false);
        self::assertNotSame('', $device['lastSeenAt'] ?? '');
        self::assertArrayNotHasKey('software', $device);
        self::assertArrayNotHasKey('sourceSystem', $device);
        self::assertArrayNotHasKey('sourceDeviceId', $device);
    }

    public function testShowReturnsVoerkaHelpCallCapabilitySupportWithoutStoredConfiguration(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $db->whitelist->register('bea6c3dd8e02', 'Voerka', 'W812', 'ncs', 0, '', 'bea6c3dd8e02');
        $store->registerDevice('bea6c3dd8e02', 'Voerka', 'W812', 'ncs', 1001, '', '', 'hitcare');

        $response = $api->show('bea6c3dd8e02', null, '');

        self::assertSame('Voerka', $response['model']['supplier'] ?? null);
        self::assertSame('W812', $response['model']['commercialName'] ?? null);
        self::assertSame('ncs', $response['model']['deviceType'] ?? null);
        self::assertArrayHasKey('help_call', $response['capabilities']['alarms'] ?? []);
        self::assertSame(
            [
                'supported' => true,
            ],
            $response['capabilities']['alarms']['help_call'] ?? null
        );
    }

    public function testShowExposesModelSupportedCapabilitiesWithoutStoredConfigurationRows(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('864293000000222', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock', 'phonebook']);

        $response = $api->show('864293000000222', null, '');

        self::assertSame([], $response['configurations'] ?? []);
        self::assertArrayHasKey('alarm_clock', $response['capabilities']['reminders'] ?? []);
        self::assertSame(3, $response['capabilities']['reminders']['alarm_clock']['_meta']['limit'] ?? null);
        self::assertSame(
            [
                ['value' => 'once', 'label' => 'Uma vez'],
                ['value' => 'daily', 'label' => 'Todos os dias'],
                ['value' => 'custom', 'label' => 'Personalizado'],
            ],
            $response['capabilities']['reminders']['alarm_clock']['_meta']['recurrence']['options'] ?? null
        );
        self::assertSame(
            [
                [
                    'time' => '',
                    'enabled' => true,
                    'recurrence' => ['kind' => 'once'],
                ],
            ],
            $response['capabilities']['reminders']['alarm_clock']['value'] ?? null
        );
        self::assertArrayHasKey('phonebook', $response['capabilities']['contacts'] ?? []);
        self::assertSame(100, $response['capabilities']['contacts']['phonebook']['_meta']['limit'] ?? null);
        self::assertNull(
            $response['capabilities']['contacts']['phonebook']['_meta']['name']['maxLength'] ?? null,
            'o PHBX2 não limita o nome: a API não anuncia limite nenhum para ele'
        );
        self::assertSame(20, $response['capabilities']['contacts']['phonebook']['_meta']['phone']['maxLength'] ?? null);
        self::assertTrue($response['capabilities']['contacts']['phonebook']['_meta']['phone']['asciiOnly'] ?? false);
        self::assertSame([], $response['capabilities']['contacts']['phonebook']['value'] ?? null);
    }

    public function testShowExposesFourPTouchSosContactsAsNumbersObject(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('861728087060467', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['sos_contacts']);
        ConfigurationFixtures::desired(
            $db,
            '861728087060467',
            'sosContacts',
            'four-p-touch',
            'SOS',
            ['numbers' => ['+351938854803', '+351938854807', '']]
        );

        $response = $api->show('861728087060467', null, '');

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
        ConfigurationFixtures::desired(
            $db,
            '637507597567372',
            'whitelistGroup1',
            'four-p-touch',
            'WHITELIST1',
            ['numbers' => ['111', '222', '333', '444', '555']]
        );
        ConfigurationFixtures::desired(
            $db,
            '637507597567372',
            'whitelistGroup2',
            'four-p-touch',
            'WHITELIST2',
            ['numbers' => ['666', '777', '', '', '']]
        );

        $response = $api->show('637507597567372', null, '');

        self::assertSame(
            ['111', '222', '333', '444', '555', '666', '777'],
            $response['capabilities']['contacts']['call_whitelist']['value'] ?? null
        );
        self::assertSame(
            10,
            $response['capabilities']['contacts']['call_whitelist']['_meta']['limit'] ?? null
        );
    }

    public function testShowNormalizesStaleFourPTouchCallWhitelistContactsToNumbers(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('637507597567372', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['call_whitelist']);
        ConfigurationFixtures::desired(
            $db,
            '637507597567372',
            'call_whitelist',
            'four-p-touch',
            'WHITELIST1',
            [
                'contacts' => [
                    ['name' => '', 'phone' => '+351938854803'],
                ],
            ]
        );

        $response = $api->show('637507597567372', null, '');

        self::assertSame(
            ['+351938854803'],
            $response['capabilities']['contacts']['call_whitelist']['value'] ?? null
        );
        self::assertSame(
            10,
            $response['capabilities']['contacts']['call_whitelist']['_meta']['limit'] ?? null
        );
        self::assertSame(
            ['+351938854803'],
            $response['configurations']['call_whitelist'] ?? null
        );
    }

    public function testShowNormalizesWonlexFamilyContactsAndExposesContactMetadata(): void
    {
        [$api, $db] = $this->makeApi();
        $imei = '868705080300697';
        $created = $api->create([
            'imei' => $imei,
            'supplier' => 'Wonlex',
            'model' => 'HW20PRO',
            'deviceType' => 'watch',
            'licenseId' => '0',
        ]);
        self::assertSame('ok', $created['status'] ?? null);

        $model = $db->models->find('Wonlex', 'HW20PRO');
        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], [
            'alarm_clock',
            'phonebook',
            'sos_contacts',
            'whitelist_enabled',
        ]);
        $contacts = [[
            'familyNumberId' => '8c67b51b',
            'name' => 'Care',
            'phone' => '210000000',
            'sosSwitch' => true,
            'areaCode' => '351',
        ]];
        ConfigurationFixtures::desired(
            $db,
            $imei,
            'familyNumber',
            'wonlex-json',
            'familyNumber',
            ['contacts' => $contacts]
        );
        ConfigurationFixtures::desired(
            $db,
            $imei,
            'alarmClock',
            'wonlex-json',
            'alarmClock',
            ['items' => [[
                'label' => 'Medicine',
                'time' => '08:00',
                'week' => '1111111',
                'enabled' => true,
            ]]]
        );
        ConfigurationFixtures::desired(
            $db,
            $imei,
            'SOSNumber',
            'wonlex-json',
            'SOSNumber',
            ['contacts' => [[
                'sosNumberId' => '8c67b51b',
                'name' => 'Care',
                'phone' => '210000000',
                'publicPhone' => '+351210000000',
            ]]]
        );
        ConfigurationFixtures::desired(
            $db,
            $imei,
            'wonlexCallInLimitSwitch',
            'wonlex-json',
            'deviceConfig',
            ['switchState' => true]
        );

        $response = $api->show($imei, null, '');

        $publicContacts = [['name' => 'Care', 'phone' => '+351210000000']];
        self::assertSame($publicContacts, $response['capabilities']['contacts']['phonebook']['value'] ?? null);
        self::assertSame($publicContacts, $response['configurationSync']['entries']['contacts']['phonebook']['desired'] ?? null);
        self::assertSame(['+351210000000'], $response['configurations']['sos_contacts'] ?? null);
        self::assertTrue($response['configurations']['whitelist_enabled']['enabled'] ?? false);
        self::assertArrayNotHasKey('call_whitelist', $response['capabilities']['contacts'] ?? []);
        self::assertArrayNotHasKey('call_in_restriction', $response['capabilities']['contacts'] ?? []);
        self::assertSame(10, $response['capabilities']['contacts']['phonebook']['_meta']['limit'] ?? null);
        self::assertSame(4, $response['capabilities']['contacts']['phonebook']['_meta']['name']['maxLength'] ?? null);
        self::assertSame('phonebook', $response['capabilities']['contacts']['sos_contacts']['_meta']['sourceCapability'] ?? null);
        self::assertSame('subset', $response['capabilities']['contacts']['sos_contacts']['_meta']['selectionMode'] ?? null);
        self::assertSame(
            ['phonebook', 'sos_contacts'],
            $response['capabilities']['contacts']['whitelist_enabled']['_meta']['allowedContactSources'] ?? null
        );
        self::assertSame('Medicine', $response['configurations']['alarm_clock'][0]['label'] ?? null);
        self::assertSame('daily', $response['configurations']['alarm_clock'][0]['recurrence']['kind'] ?? null);
        self::assertSame(10, $response['capabilities']['reminders']['alarm_clock']['_meta']['limit'] ?? null);
        self::assertTrue($response['capabilities']['reminders']['alarm_clock']['_meta']['label']['supported'] ?? false);
        self::assertArrayNotHasKey('required', $response['capabilities']['reminders']['alarm_clock']['_meta']['label'] ?? []);
        self::assertTrue($response['capabilities']['reminders']['alarm_clock']['_meta']['url']['supported'] ?? false);
        self::assertSame(
            ['http', 'https'],
            $response['capabilities']['reminders']['alarm_clock']['_meta']['url']['schemes'] ?? null
        );
        self::assertSame(
            ['daily', 'custom'],
            array_column($response['capabilities']['reminders']['alarm_clock']['_meta']['recurrence']['options'] ?? [], 'value')
        );
        self::assertArrayHasKey('sos_contacts', $response['capabilities']['contacts'] ?? []);
    }

    public function testShowDoesNotLeakWonlexSwitchStateIntoGenericConfigurationSurfaces(): void
    {
        [$api, $db] = $this->makeApi();
        $imei = '868705080300697';
        $created = $api->create([
            'imei' => $imei,
            'supplier' => 'Wonlex',
            'model' => 'HW20PRO',
            'deviceType' => 'watch',
            'licenseId' => '0',
        ]);
        self::assertSame('ok', $created['status'] ?? null);

        $model = $db->models->find('Wonlex', 'HW20PRO');
        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['fall_detection']);
        ConfigurationFixtures::desired(
            $db,
            $imei,
            'wonlexFallWarnSwitch',
            'wonlex-json',
            'deviceConfig',
            ['switchState' => true],
            'acked',
        );

        $response = $api->show($imei, null, '');

        self::assertSame(
            ['enabled' => true],
            $response['configurations']['fall_detection'] ?? null
        );
        self::assertSame(
            ['enabled' => true],
            $response['capabilities']['alarms']['fall_detection']['value'] ?? null
        );
        self::assertSame(
            ['enabled' => true],
            $response['effectiveConfigurations']['fall_detection'] ?? null
        );
        self::assertSame(
            ['enabled' => true],
            $response['configurationSync']['entries']['alarms']['fall_detection']['desired'] ?? null
        );
        self::assertArrayNotHasKey('switchState', $response['configurations']['fall_detection'] ?? []);
    }

    public function testWonlexFallDetectionPatchUsesEnabledPubliclyAndSwitchStateOnWire(): void
    {
        $submitted = [];
        [$api, $db, $store] = $this->makeApi(hub: $this->recordingHub($submitted));
        $imei = '868705080300697';
        self::assertSame('ok', $api->create([
            'imei' => $imei,
            'supplier' => 'Wonlex',
            'model' => 'HW20PRO',
            'deviceType' => 'watch',
            'licenseId' => '0',
        ])['status'] ?? null);

        $model = $db->models->find('Wonlex', 'HW20PRO');
        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['fall_detection']);

        $updated = $api->updateConfigurations($imei, [
            'configurations' => [
                'fall_detection' => ['enabled' => false],
            ],
        ]);

        self::assertSame('ok', $updated['status'] ?? null);
        self::assertCount(1, $submitted);
        $frame = (new \Hub\Protocol\Adapter\WonlexAdapter())->decodeIncoming($submitted[0]['bytes']);
        self::assertSame(
            0,
            $frame['data']['configs']['FallWarnSwitch']['switchState'] ?? null
        );
        self::assertSame(
            ['enabled' => false],
            $updated['configurations']['fall_detection'] ?? null
        );
        self::assertSame(
            ['enabled' => false],
            $updated['configurationSync']['entries']['alarms']['fall_detection']['desired'] ?? null
        );

        $store->markCommandReply($imei, 'deviceConfig', 999999, 'w:reply');
        $confirmed = $api->show($imei, null, '');
        self::assertSame(
            ['enabled' => false],
            $confirmed['effectiveConfigurations']['fall_detection'] ?? null
        );
        self::assertSame(
            ['enabled' => false],
            $confirmed['capabilities']['alarms']['fall_detection']['value'] ?? null
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
            'fall_detection',
        ]);
        ConfigurationFixtures::desired(
            $db,
            '861728087060467',
            'uploadInterval',
            'four-p-touch',
            'UPLOAD',
            ['intervalSeconds' => 300]
        );
        ConfigurationFixtures::desired(
            $db,
            '861728087060467',
            'fallDownAlert',
            'four-p-touch',
            'FALLDOWN',
            ['enabled' => true, 'callCenterOnFall' => false]
        );

        $response = $api->show('861728087060467', null, '');

        self::assertSame(
            ['value' => ['intervalSeconds' => 300], '_meta' => ['intervalSeconds' => ['min' => 60, 'max' => 65535]]],
            $response['capabilities']['health']['location_reporting_interval'] ?? null
        );
        self::assertSame(
            ['value' => ['enabled' => true, 'callCenterOnFall' => false], '_meta' => []],
            $response['capabilities']['alarms']['fall_detection'] ?? null
        );
    }

    public function testShowCompactsOversizedTakePillsVoiceData(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');
        $voiceData = base64_encode(str_repeat('audio', 20000));

        self::assertIsArray($model);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);
        ConfigurationFixtures::desired(
            $db,
            '868017032159118',
            'takePills',
            'four-p-touch',
            'TAKEPILLS',
            [
                'reminderSettings' => '11:25-1-3-1010101',
                'number' => 1,
                'reminderText' => 'meds',
                'voiceData' => $voiceData,
                'voiceMimeType' => 'audio/mpeg',
            ]
        );

        $response = $api->show('868017032159118', null, '');

        foreach (
            [
            $response['configurations']['medication_reminders'] ?? [],
            $response['capabilities']['reminders']['medication_reminders']['value'] ?? [],
            $response['configurationSync']['entries']['reminders']['medication_reminders']['desired'] ?? [],
            ] as $value
        ) {
            self::assertArrayNotHasKey('voiceData', $value);
            self::assertTrue($value['voiceDataAvailable'] ?? false);
            self::assertSame(100000, $value['voiceDataBytes'] ?? null);
        }
        self::assertLessThan(20000, strlen(json_encode($response, JSON_THROW_ON_ERROR)));
    }

    public function testShowExposesVivistarAlarmClockStructuredMeta(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $store->registerDevice('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);
        ConfigurationFixtures::desired(
            $db,
            '861265061009822',
            'reminders',
            'vivistar-iw',
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

        $response = $api->show('861265061009822', null, '');

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
            $response['capabilities']['reminders']['alarm_clock']['value'] ?? null
        );
        self::assertSame(
            [
                ['value' => 1, 'label' => 'Medicação'],
                ['value' => 2, 'label' => 'Água'],
                ['value' => 3, 'label' => 'Sedentarismo'],
            ],
            $response['capabilities']['reminders']['alarm_clock']['_meta']['type']['options'] ?? null
        );
    }

    public function testShowExposesFourPTouchAlarmClockStructuredMeta(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);
        ConfigurationFixtures::desired(
            $db,
            '868017032159118',
            'alarmClock',
            'four-p-touch',
            'REMIND',
            [
                'alarms' => [
                    ['time' => '08:10', 'enabled' => true, 'frequency' => 1],
                    ['time' => '14:30', 'enabled' => false, 'frequency' => 2],
                    ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '0111110'],
                ],
            ]
        );

        $response = $api->show('868017032159118', null, '');

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
            $response['capabilities']['reminders']['alarm_clock']['value'] ?? null
        );
        self::assertSame(3, $response['capabilities']['reminders']['alarm_clock']['_meta']['limit'] ?? null);
        self::assertArrayNotHasKey('type', $response['capabilities']['reminders']['alarm_clock']['_meta'] ?? []);
    }

    public function testShowMapsVivistarEmptyDaysToOnceRecurrence(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $store->registerDevice('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['alarm_clock']);
        ConfigurationFixtures::desired(
            $db,
            '861265061009822',
            'reminders',
            'vivistar-iw',
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

        $response = $api->show('861265061009822', null, '');

        self::assertSame(
            [
                [
                    'time' => '09:00',
                    'enabled' => true,
                    'recurrence' => ['kind' => 'once'],
                    'type' => 1,
                ],
            ],
            $response['capabilities']['reminders']['alarm_clock']['value'] ?? null
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

    public function testAcknowledgementEnvelopeIsAppliedRatherThanDiverged(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['auto_vitals_interval']);
        ConfigurationFixtures::desired(
            $db,
            '861265061009822',
            'autoHealthMeasurement',
            'vivistar-iw',
            'BP86',
            ['enabled' => true, 'intervalMinutes' => 30],
            'acked',
        );
        $db->deviceConfigurations->saveReported(
            '861265061009822',
            'autoHealthMeasurement',
            'vivistar-iw',
            'AP86',
            [
                'type' => 'device_config',
                'data' => ['status' => 'ok'],
                'source' => ['protocol' => 'vivistar-iw', 'nativeType' => 'AP86'],
            ]
        );

        $response = $api->show('861265061009822', null, '');
        $delivery = $response['configurationSync']['entries']['health']['auto_vitals_interval'] ?? [];

        self::assertSame('confirmed', $delivery['status'] ?? null);
        self::assertSame(
            ['enabled' => true, 'intervalMinutes' => 30],
            $delivery['desired'] ?? null
        );
        self::assertSame(
            ['enabled' => true, 'intervalMinutes' => 30],
            $delivery['effective'] ?? null
        );
    }

    /** O AP86 só acusa a receção, sem devolver o intervalo que o relógio aplicou. */
    public function testVivistarAutoHealthMeasurementIsConfirmedByAckOnly(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['auto_vitals_interval']);
        $updated = $api->updateConfigurations('861265061009822', [
            'configurations' => [
                'auto_vitals_interval' => ['enabled' => true, 'intervalMinutes' => 60],
            ],
        ]);
        $commandId = (string)($updated['results'][0]['operations'][0]['lastCommandId'] ?? '');
        self::assertNotSame('', $commandId);

        $command = $store->findCommand($commandId)['command'] ?? [];
        self::assertSame('BP86', $command['nativeType'] ?? null);
        self::assertSame('ack_only', $command['confirmationMode'] ?? null);
    }

    public function testRetryExhaustionMarksStoredConfigurationAsFailed(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['fall_detection']);
        $updated = $api->updateConfigurations('861265061009822', [
            'configurations' => [
                'fall_detection' => ['enabled' => true],
            ],
        ]);
        $commandId = (string)($updated['results'][0]['operations'][0]['lastCommandId'] ?? '');
        self::assertNotSame('', $commandId);

        $command = $store->findCommand($commandId)['command'] ?? [];
        $store->recordCommand('861265061009822', $commandId, array_merge($command, [
            'attempts' => 3,
            'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]));
        $store->retryWaitingCommands(60, 3600, 3, static fn(): string => 'sent');

        $response = $api->show('861265061009822', null, '');
        $delivery = $response['configurationSync']['entries']['alarms']['fall_detection'] ?? [];
        self::assertSame('failed', $delivery['status'] ?? null);
        self::assertSame('retry_exhausted', $delivery['operations'][0]['error'] ?? null);
        self::assertSame($commandId, $delivery['operations'][0]['operationId'] ?? null);
        self::assertSame(
            'failed',
            $db->deviceConfigurations->allForImei('861265061009822')[0]['last_status'] ?? null
        );

        $retried = $api->updateConfigurations('861265061009822', [
            'configurations' => [
                'fall_detection' => ['enabled' => true],
            ],
        ]);
        self::assertSame(2, $retried['results'][0]['desiredRevision'] ?? null);
        self::assertNotSame(
            $updated['results'][0]['changeId'] ?? '',
            $retried['results'][0]['changeId'] ?? ''
        );
    }
}
