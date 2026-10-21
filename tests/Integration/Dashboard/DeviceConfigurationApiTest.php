<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Tests\Support\DevicesApiFixture;
use Tests\Support\MysqlDashboardTestCase;

final class DeviceConfigurationApiTest extends MysqlDashboardTestCase
{
    use DevicesApiFixture;

    public function testRequestFeatureRejectsDisabledModelRequest(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['location']);

        $response = $api->requestFeature('861265061009822', ['feature' => 'heart_rate']);

        self::assertSame('unsupported_feature', $response['error']['code'] ?? null);
    }

    public function testConfigurationPatchAcceptsVivistarWhitelistEnabledToggleAsBp84(): void
    {
        $submitted = [];
        [$api, $db] = $this->makeApi(hub: $this->recordingHub($submitted));
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['whitelist_enabled']);

        $response = $api->updateConfigurations('861265061009822', [
            'configurations' => [
                'whitelist_enabled' => [
                    'enabled' => false,
                ],
            ],
        ]);

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

    public function testFourPTouchWhitelistEnabledUsesDocumentedRejectUnknownCallsCommand(): void
    {
        $submitted = [];
        [$api, $db, $store] = $this->makeApi(hub: $this->recordingHub($submitted));
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['whitelist_enabled']);
        $store->registerDevice('637507597567372', '4P Touch', 'D46', 'watch', 1, '', '7597567372', 'havicare');

        $disabled = $api->updateConfigurations('637507597567372', [
            'configurations' => [
                'whitelist_enabled' => ['enabled' => false],
            ],
        ]);

        self::assertSame('ok', $disabled['status'] ?? null);
        self::assertSame('rejectUnknownCalls', $disabled['results'][0]['operations'][0]['nativeKey'] ?? null);
        self::assertCount(1, $submitted);
        self::assertStringContainsString('DEVREFUSEPHONESWITCH,0]', $submitted[0]['bytes']);
        $afterDisable = $api->show('637507597567372');
        self::assertFalse($afterDisable['configurations']['whitelist_enabled']['enabled'] ?? true);
        self::assertFalse($afterDisable['capabilities']['contacts']['whitelist_enabled']['value']['enabled'] ?? true);

        $enabled = $api->updateConfigurations('637507597567372', [
            'configurations' => [
                'whitelist_enabled' => ['enabled' => true],
            ],
        ]);

        self::assertSame('ok', $enabled['status'] ?? null);
        self::assertCount(2, $submitted);
        self::assertStringContainsString('DEVREFUSEPHONESWITCH,1]', $submitted[1]['bytes']);
        $afterEnable = $api->show('637507597567372');
        self::assertTrue($afterEnable['configurations']['whitelist_enabled']['enabled'] ?? false);
        self::assertTrue($afterEnable['capabilities']['contacts']['whitelist_enabled']['value']['enabled'] ?? false);
    }

    public function testFourPTouchSosContactsRejectsRepeatedNumbers(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('4P Touch', 'D46');
        self::assertIsArray($model);
        $store->registerDevice('861728087060467', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['sos_contacts']);

        $response = $api->updateConfigurations('861728087060467', [
            'configurations' => [
                'sos_contacts' => [
                    '+351938854803',
                    '+351938854803',
                ],
            ],
        ]);

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
        self::assertSame('numbers must not contain repeated values', $response['error']['message'] ?? null);
    }

    public function testFourPTouchMonitorNumberIsNotAcceptedAsConfiguration(): void
    {
        // O MONITOR faz o relógio ligar de imediato para o número, em escuta silenciosa.
        // Não há forma de o gravar sem disparar a chamada, logo não é configuração.
        $hub = $this->createMock(\Hub\Device\DeviceHubServer::class);
        $hub->expects(self::never())->method('submitDownlink');
        [$api, $db, $store] = $this->makeApi(hub: $hub);
        $model = $db->models->find('4P Touch', 'D46');
        self::assertIsArray($model);
        $store->registerDevice('861728087060467', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['monitor_number']);

        $response = $api->updateConfigurations('861728087060467', [
            'configurations' => [
                'monitor_number' => ['phone' => '+351938854803'],
            ],
        ]);

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
        self::assertSame(
            'monitorNumber is a transient action and must be requested via /requests',
            $response['error']['message'] ?? null
        );
        self::assertSame([], $db->deviceConfigurations->allForImei('861728087060467'));
    }

    public function testConfigurationPatchSendsDownlinksForGenericKeys(): void
    {
        $submitted = [];
        [$api, $db] = $this->makeApi(hub: $this->recordingHub($submitted));
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['fall_detection', 'call_whitelist']);
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'fallDetection',
            'vivistar-iw',
            'BP76',
            ['enabled' => false]
        );
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'call_whitelist',
            'vivistar-iw',
            'BP14',
            ['contacts' => [['name' => 'Bruno', 'phone' => '+351922222222']]]
        );

        $response = $api->updateConfigurations('861265061009822', [
            'configurations' => [
                'fall_detection' => ['enabled' => true],
                'call_whitelist' => [
                    'contacts' => [
                        ['name' => 'Ana', 'phone' => '+351911111111'],
                    ],
                ],
            ],
        ]);

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(2, $submitted);
        self::assertSame('861265061009822', $submitted[0]['imei']);
        self::assertStringContainsString('BP76', $submitted[0]['bytes']);
        self::assertStringContainsString('BP14', $submitted[1]['bytes']);
        self::assertStringContainsString(
            '0041006E0061|+351911111111',
            $submitted[1]['bytes']
        );
        self::assertStringNotContainsString('|Ana|+351911111111', $submitted[1]['bytes']);
        self::assertCount(2, $response['results'] ?? []);
        self::assertSame('fall_detection', $response['results'][0]['key'] ?? null);
        self::assertSame('fallDetection', $response['results'][0]['operations'][0]['nativeKey'] ?? null);
        self::assertSame('call_whitelist', $response['results'][1]['key'] ?? null);
        self::assertSame(['enabled' => true], $response['configurations']['fall_detection'] ?? null);
        self::assertSame('awaiting_ack', $response['configurationSync']['entries']['alarms']['fall_detection']['status'] ?? null);
    }

    public function testConfigurationPatchPreservesStoredTakePillsVoiceWhenOmitted(): void
    {
        if (!\Hub\Command\Configuration\Payload\FourPTouchPayloadBuilder::supportsVoiceTranscoding()) {
            self::markTestSkipped('ffmpeg with AMR-NB support is not available');
        }
        $submitted = [];
        [$api, $db, $store] = $this->makeApi(hub: $this->recordingHub($submitted));
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->deviceConfigurations->saveDesired(
            '868017032159118',
            'takePills',
            'four-p-touch',
            'TAKEPILLS',
            [
                'reminderSettings' => [['time' => '11:25', 'enabled' => true, 'frequency' => 3, 'custom' => '1010101']],
                'number' => 1,
                'reminderText' => 'old',
                'voiceData' => 'UklGRsQAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YaAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
                'voiceMimeType' => 'audio/wav',
            ]
        );

        $response = $api->updateConfigurations('868017032159118', [
            'configurations' => [
                'medication_reminders' => [
                    'reminderSettings' => [
                        'time' => '11:25',
                        'enabled' => true,
                        'frequency' => 3,
                        'custom' => '1010101',
                    ],
                    'number' => 1,
                    'reminderText' => 'updated',
                ],
            ],
        ]);

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame(4, substr_count($submitted[0]['bytes'], ','));
        self::assertStringContainsString(",#!AMR\n", $submitted[0]['bytes']);
        self::assertStringEndsWith(']', $submitted[0]['bytes']);
    }

    public function testConfigurationPatchAcceptsPhonebookContactsWrapperForFourPTouch(): void
    {
        $submitted = [];
        [$api, $db, $store] = $this->makeApi(hub: $this->recordingHub($submitted));
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['phonebook']);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');

        $response = $api->updateConfigurations('868017032159118', [
            'configurations' => [
                'phonebook' => [
                    'contacts' => [
                        ['name' => 'ABCDEFGHIJK', 'phone' => '123456789'],
                    ],
                ],
            ],
        ]);

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('phonebook', $response['results'][0]['key'] ?? null);
        self::assertCount(1, $submitted);
        self::assertSame('868017032159118', $submitted[0]['imei']);
        self::assertStringContainsString(
            'PHBX2,1,004100420043004400450046004700480049004A004B,123456789',
            $submitted[0]['bytes'],
            'o nome vai inteiro: o PHBX2 não tem o limite de dez caracteres do PHB'
        );
        self::assertSame(
            ['contacts' => [1 => ['name' => 'ABCDEFGHIJK', 'phone' => '123456789']]],
            $db->deviceConfigurations->allForImei('868017032159118')[0]['desired_payload'] ?? null,
            'a forma nativa guarda o índice, que é o endereço do contacto no aparelho'
        );
    }

    public function testConfigurationPatchAcceptsEmptyPhonebookContactsForFourPTouch(): void
    {
        $submitted = [];
        [$api, $db, $store] = $this->makeApi(hub: $this->recordingHub($submitted));
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['phonebook']);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');

        $response = $api->updateConfigurations('868017032159118', [
            'configurations' => [
                'phonebook' => [
                    'contacts' => [],
                ],
            ],
        ]);

        self::assertSame('ok', $response['status'] ?? null);
        self::assertSame('phonebook', $response['results'][0]['key'] ?? null);
        self::assertSame(
            [],
            $submitted,
            'limpar uma lista que o aparelho não tem não é nada para entregar'
        );
        self::assertSame(
            ['contacts' => []],
            $db->deviceConfigurations->allForImei('868017032159118')[0]['desired_payload'] ?? null
        );
    }

    public function testConfigurationPatchClearsExistingSosContactsForFourPTouch(): void
    {
        $submitted = [];
        [$api, $db, $store] = $this->makeApi(hub: $this->recordingHub($submitted));
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['sos_contacts']);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');

        $initial = $api->updateConfigurations('868017032159118', [
            'configurations' => [
                'sos_contacts' => ['123456789'],
            ],
        ]);
        self::assertSame('ok', $initial['status'] ?? null);

        $cleared = $api->updateConfigurations('868017032159118', [
            'configurations' => [
                'sos_contacts' => [],
            ],
        ]);

        self::assertSame('[3G*1703215911*000F*SOS,123456789,,]', $submitted[0]['bytes'] ?? null);
        self::assertSame('ok', $cleared['status'] ?? null);
        self::assertCount(1, $cleared['results'][0]['operations'] ?? []);
        self::assertSame('[3G*1703215911*0006*SOS,,,]', $submitted[1]['bytes'] ?? null);

        $detail = $api->show('868017032159118');
        self::assertSame([], $detail['configurations']['sos_contacts'] ?? null);
        self::assertSame([], $detail['capabilities']['contacts']['sos_contacts']['value'] ?? null);
        self::assertSame([], $detail['configurationSync']['entries']['contacts']['sos_contacts']['desired'] ?? null);
    }

    public function testConfigurationPatchRejectsInvalidRequestWithoutConfigurations(): void
    {
        [$api] = $this->makeApi();

        $response = $api->updateConfigurations('861265061009822', []);

        self::assertSame('invalid_request', $response['error']['code'] ?? null);
    }

    public function testConfigurationPatchRejectsUnknownConfigKey(): void
    {
        [$api] = $this->makeApi();

        $response = $api->updateConfigurations('861265061009822', [
            'configurations' => [
                'unknownConfig' => ['enabled' => true],
            ],
        ]);

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
    }

    public function testConfigurationPatchRejectsSupplierNativeKey(): void
    {
        [$api] = $this->makeApi();

        $response = $api->updateConfigurations('861265061009822', [
            'configurations' => [
                'fallDetection' => ['enabled' => true],
            ],
        ]);

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
        self::assertSame(
            'Unsupported configuration fallDetection',
            $response['error']['message'] ?? null
        );
    }

    public function testConfigurationPatchRejectsGenericCapabilityDisabledForModel(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['fall_detection']);

        $response = $api->updateConfigurations('861265061009822', [
            'configurations' => [
                'working_mode' => ['mode' => 1],
            ],
        ]);

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
        self::assertSame(
            'Capability working_mode is not enabled for this model',
            $response['error']['message'] ?? null
        );
        self::assertSame([], $db->deviceConfigurations->allForImei('861265061009822'));
    }

    public function testPushMessageRequestSendsTransientBp40WithoutPersistingConfiguration(): void
    {
        $submitted = [];
        [$api, $db] = $this->makeApi(hub: $this->recordingHub($submitted));
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['push_message']);

        $response = $api->requestFeature('861265061009822', [
            'capability' => 'push_message',
            'value' => ['message' => 'are you ok?'],
        ]);

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

    public function testWonlexPushMessageRequestSendsMessageNoticeWithoutWaitingForUndocumentedAck(): void
    {
        $submitted = [];
        [$api, $db, $store] = $this->makeApi(hub: $this->recordingHub($submitted));
        $imei = '868705080300698';
        $model = $db->models->find('Wonlex', 'HW20PRO');

        self::assertIsArray($model);
        self::assertSame('ok', $api->create([
            'imei' => $imei,
            'supplier' => 'Wonlex',
            'model' => 'HW20PRO',
            'deviceType' => 'watch',
            'licenseId' => '0',
        ])['status'] ?? null);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['push_message']);
        $store->registerDevice($imei, 'Wonlex', 'HW20PRO');

        $response = $api->requestFeature($imei, [
            'capability' => 'push_message',
            'value' => ['message' => 'Hello World'],
        ]);

        self::assertSame('sent', $response['status'] ?? null);
        self::assertSame('sent', $response['commands'][0]['status'] ?? null);
        self::assertFalse($response['commands'][0]['retryable'] ?? true);
        self::assertSame([], $response['commands'][0]['expectedReplyTypes'] ?? null);
        self::assertCount(1, $submitted);
        $frame = (new \Hub\Protocol\Adapter\WonlexAdapter())->decodeIncoming($submitted[0]['bytes']);
        self::assertSame('msgNotice', $frame['type'] ?? null);
        self::assertSame('msg', $frame['data']['msgType'] ?? null);
        self::assertSame('Hello World', $frame['data']['msg'] ?? null);
        self::assertSame([], $db->deviceConfigurations->allForImei($imei));
    }

    public function testPushMessageCannotBeSavedAsPersistentConfiguration(): void
    {
        [$api, $db] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['push_message']);

        $response = $api->updateConfigurations('861265061009822', [
            'configurations' => [
                'push_message' => ['message' => 'are you ok?'],
            ],
        ]);

        self::assertSame('invalid_config', $response['error']['code'] ?? null);
        self::assertSame([], $db->deviceConfigurations->allForImei('861265061009822'));
    }

    public function testFourPTouchCallWhitelistConfigurationFansOutToNativeCommands(): void
    {
        $submitted = [];
        [$api, $db, $store] = $this->makeApi(hub: $this->recordingHub($submitted));
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['call_whitelist']);
        $store->registerDevice('637507597567372', '4P Touch', 'D46', 'watch', 0, '', '7597567372', 'hitcare');

        $response = $api->updateConfigurations('637507597567372', [
            'configurations' => [
                'call_whitelist' => [
                    '111',
                    '222',
                    '333',
                    '444',
                    '555',
                    '666',
                ],
            ],
        ]);

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(2, $response['results'][0]['operations'] ?? []);
        self::assertCount(2, $submitted);
        self::assertStringContainsString('WHITELIST1', $submitted[0]['bytes']);
        self::assertStringContainsString('WHITELIST2', $submitted[1]['bytes']);
        self::assertSame(
            ['111', '222', '333', '444', '555', '666'],
            $response['configurations']['call_whitelist'] ?? null
        );
        $savedRows = $db->deviceConfigurations->allForImei('637507597567372');
        self::assertCount(2, $savedRows);
        self::assertSame(['numbers' => ['111', '222', '333', '444', '555']], $savedRows[0]['desired_payload'] ?? null);
        self::assertSame(['numbers' => ['666']], $savedRows[1]['desired_payload'] ?? null);
    }

    public function testWonlexGenericConfigurationsEmitDocumentedFramesAndRoundTrip(): void
    {
        $submitted = [];
        [$api, $db] = $this->makeApi(hub: $this->recordingHub($submitted));
        $imei = '868705080300698';
        self::assertSame('ok', $api->create([
            'imei' => $imei,
            'supplier' => 'Wonlex',
            'model' => 'HW20PRO',
            'deviceType' => 'watch',
            'licenseId' => '0',
        ])['status'] ?? null);

        $model = $db->models->find('Wonlex', 'HW20PRO');
        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], [
            'alarm_clock',
            'phonebook',
            'sos_contacts',
            'whitelist_enabled',
            'sos_sms_alert',
            'heart_rate_measurement_interval',
        ]);
        $response = $api->updateConfigurations($imei, [
            'configurations' => [
                'heart_rate_measurement_interval' => ['interval' => 15],
                'alarm_clock' => ['items' => [[
                    'label' => 'Medicine',
                    'time' => '08:00',
                    'enabled' => true,
                    'recurrence' => ['kind' => 'daily'],
                    'url' => 'https://developer.mozilla.org/shared-assets/audio/t-rex-roar.mp3',
                ]]],
                'phonebook' => ['contacts' => [[
                    'name' => 'Rodrigo',
                    'phone' => '+351210000000',
                ]]],
                'sos_contacts' => ['+351210000000'],
                'whitelist_enabled' => ['enabled' => true],
                'sos_sms_alert' => ['enabled' => true],
            ],
        ]);

        self::assertSame('ok', $response['status'] ?? null);
        self::assertCount(6, $submitted);
        $adapter = new \Hub\Protocol\Adapter\WonlexAdapter();
        $frames = array_map(
            static fn(array $item): array => $adapter->decodeIncoming($item['bytes']) ?? [],
            $submitted
        );
        self::assertSame([
            'familyNumber',
            'deviceMeasuringFrequency',
            'alarmClock',
            'SOSNumber',
            'deviceConfig',
            'deviceConfig',
        ], array_column($frames, 'type'));
        self::assertSame('351', $frames[0]['data']['familyNumbers'][0]['areaCode'] ?? null);
        self::assertSame('Rodr', $frames[0]['data']['familyNumbers'][0]['name'] ?? null);
        self::assertSame('15', $frames[1]['data']['configs']['upHeartRate']['interval'] ?? null);
        self::assertSame('1111111', $frames[2]['data']['alarmClockList'][0]['week'] ?? null);
        self::assertSame('Medicine', $frames[2]['data']['alarmClockList'][0]['label'] ?? null);
        self::assertSame(
            'https://developer.mozilla.org/shared-assets/audio/t-rex-roar.mp3',
            $frames[2]['data']['alarmClockList'][0]['url'] ?? null
        );
        self::assertSame('210000000', $frames[3]['data']['sosNumbers'][0]['phone'] ?? null);
        self::assertSame(1, $frames[4]['data']['configs']['CallInLimitSwitch']['switchState'] ?? null);
        self::assertSame(1, $frames[5]['data']['configs']['SOSSwitch']['switchState'] ?? null);
        self::assertSame('daily', $response['configurations']['alarm_clock'][0]['recurrence']['kind'] ?? null);
        self::assertSame(
            [['name' => 'Rodr', 'phone' => '+351210000000']],
            $response['configurations']['phonebook'] ?? null
        );
        self::assertSame(['+351210000000'], $response['configurations']['sos_contacts'] ?? null);
        self::assertTrue($response['configurations']['whitelist_enabled']['enabled'] ?? false);
        self::assertTrue($response['configurations']['sos_sms_alert']['enabled'] ?? false);
    }

    public function testRecentReturnsTelemetryEventsAndCommands(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['heart_rate']);
        $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 72]);
        $store->append('861265061009822', 'events', ['type' => 'sos', 'status' => 'triggered']);
        $api->requestFeature('861265061009822', ['feature' => 'heart_rate']);

        $response = $api->recent('861265061009822');

        self::assertSame('heart_rate', $response['telemetry'][0]['type'] ?? null);
        self::assertSame(72, $response['telemetry'][0]['value'] ?? null);
        self::assertSame('sos', $response['events'][0]['type'] ?? null);
        self::assertSame('BPXL', $response['commands'][0]['requestId'] ?? null);
    }

    public function testRequestFeatureSendsGenericTelemetryRequest(): void
    {
        $submitted = [];
        [$api, $db] = $this->makeApi(hub: $this->recordingHub($submitted));
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['heart_rate']);

        $response = $api->requestFeature('861265061009822', ['feature' => 'heart_rate']);

        self::assertSame('waiting', $response['status'] ?? null);
        self::assertSame('heart_rate', $response['feature'] ?? null);
        self::assertCount(1, $submitted);
        self::assertStringContainsString('BPXL', $submitted[0]['bytes']);
        self::assertTrue($response['commands'][0]['retryable'] ?? false);
        self::assertSame($submitted[0]['bytes'], $response['commands'][0]['bytes'] ?? null);
        self::assertSame(1, $response['commands'][0]['attempts'] ?? null);
        self::assertSame(3, $response['commands'][0]['maxAttempts'] ?? null);
        self::assertSame(60, $response['commands'][0]['retryDelaySeconds'] ?? null);
        self::assertNotEmpty($response['commands'][0]['nextRetryAt'] ?? null);
    }

    public function testRepeatedTelemetryRequestSupersedesThePendingRequest(): void
    {
        [$api, $db, $store] = $this->makeApi();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['heart_rate']);

        $first = $api->requestFeature(
            '861265061009822',
            ['feature' => 'heart_rate']
        );
        $second = $api->requestFeature(
            '861265061009822',
            ['feature' => 'heart_rate']
        );

        $firstId = (string)($first['commands'][0]['id'] ?? '');
        $secondId = (string)($second['commands'][0]['id'] ?? '');
        self::assertSame('superseded', $store->findCommand($firstId)['command']['status'] ?? null);
        self::assertSame('waiting', $store->findCommand($secondId)['command']['status'] ?? null);

        $redispatched = [];
        $store->recordCommand('861265061009822', $firstId, array_merge(
            $store->findCommand($firstId)['command'] ?? [],
            ['nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1)]
        ));
        $store->retryWaitingCommands(
            60,
            3600,
            3,
            static function (string $imei, string $bytes) use (&$redispatched): string {
                $redispatched[] = [$imei, $bytes];
                return 'sent';
            }
        );

        self::assertSame([], $redispatched);
    }

    public function testHw20ProOnlyAllowsVerifiedHealthRequests(): void
    {
        $submitted = [];
        [$api, $db, $store] = $this->makeApi($this->recordingHub($submitted));
        $model = $db->models->find('Wonlex', 'HW20PRO');
        $imei = '868705080300698';
        $requestableFeatures = ['blood_pressure', 'blood_oxygen'];
        $nonRequestableFeatures = ['heart_rate', 'temperature', 'breath_rate', 'ecg', 'hrv', 'ppg', 'rr_interval'];

        self::assertIsArray($model);
        $created = $api->create([
            'imei' => $imei,
            'supplier' => 'Wonlex',
            'model' => 'HW20PRO',
            'deviceType' => 'watch',
            'licenseId' => '0',
        ]);
        self::assertSame('ok', $created['status'] ?? null);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], [...$requestableFeatures, ...$nonRequestableFeatures]);
        // O que se pode pedir é decisão por modelo, no `model_capabilities.is_requestable`.
        $db->modelCapabilities->replaceTelemetryRequestabilityForModelId((int)$model['id'], $requestableFeatures);
        $store->registerDevice($imei, 'Wonlex', 'HW20PRO');

        $detail = $api->show($imei);
        foreach ($requestableFeatures as $feature) {
            self::assertSame(
                ['supported' => true, 'requestable' => true],
                $detail['capabilities']['telemetry'][$feature] ?? null,
                $feature
            );
            self::assertSame('waiting', $api->requestFeature($imei, ['feature' => $feature])['status'] ?? null, $feature);
        }
        foreach ($nonRequestableFeatures as $feature) {
            self::assertSame(
                ['supported' => true, 'requestable' => false],
                $detail['capabilities']['telemetry'][$feature] ?? null,
                $feature
            );
            self::assertSame(
                'feature_not_requestable',
                $api->requestFeature($imei, ['feature' => $feature])['error']['code'] ?? null,
                $feature
            );
        }

        self::assertCount(count($requestableFeatures), $submitted);
    }

    public function testQueuedTelemetryRequestIsRedispatchedWhenDeliveryBecomesAvailable(): void
    {
        $hub = $this->createMock(\Hub\Device\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturn('queued');
        [$api, $db, $store] = $this->makeApi(hub: $hub);
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['heart_rate']);

        $response = $api->requestFeature(
            '861265061009822',
            ['feature' => 'heart_rate']
        );

        self::assertSame('queued', $response['status'] ?? null);
        $command = $response['commands'][0] ?? [];
        $id = (string)($command['id'] ?? '');
        self::assertNotSame('', $id);
        self::assertTrue($command['retryable'] ?? false);
        self::assertNotEmpty($command['bytes'] ?? null);

        $store->recordCommand('861265061009822', $id, array_merge($command, [
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]));

        $dispatched = [];
        $store->retryWaitingCommands(60, 3600, 3, static function (string $imei, string $bytes) use (&$dispatched): string {
            $dispatched[] = [$imei, $bytes];
            return 'sent';
        });

        self::assertSame([['861265061009822', (string)$command['bytes']]], $dispatched);
        $stored = $store->findCommand($id)['command'] ?? [];
        self::assertSame('waiting', $stored['status'] ?? null);
        self::assertSame(1, $stored['attempts'] ?? null);
        self::assertNotEmpty($stored['sentAt'] ?? null);
    }

    public function testCommandStatusReturnsStoredCommandById(): void
    {
        [$api] = $this->makeApi();

        $created = $api->requestFeature('861265061009822', ['feature' => 'heart_rate']);
        $id = (string)($created['commands'][0]['id'] ?? '');

        $response = $api->commandStatus($id);

        self::assertSame('861265061009822', $response['device']['imei'] ?? null);
        self::assertSame($id, $response['command']['id'] ?? null);
        self::assertSame('BPXL', $response['command']['requestId'] ?? null);
    }
}
