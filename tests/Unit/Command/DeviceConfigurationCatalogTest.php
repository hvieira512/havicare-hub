<?php

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\GenericModelCapabilityCatalog;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use Hub\Protocol\Adapter\VivistarAdapter;
use Hub\Protocol\Adapter\WonlexAdapter;
use PHPUnit\Framework\TestCase;

final class DeviceConfigurationCatalogTest extends TestCase
{
    public function testVivistarFallDetectionBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('vivistar-iw', 'fallDetection', ['enabled' => true]);
        self::assertSame('BP76', $payload['command']);
        self::assertSame(['fields' => ['1']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('vivistar-iw', '861265061009822', $payload['command'], $payload['payload']);
        self::assertStringStartsWith('IWBP76,861265061009822,', $wire);
        self::assertStringEndsWith(',1#', $wire);
        self::assertNotNull((new VivistarAdapter())->decodeIncoming(str_replace('BP76', 'AP76', preg_replace('/^IWBP76,\d{15},/', 'IWAP76,', $wire))));
    }

    public function testVivistarFallDetectionPublicKeyIsResolvedToNativeDefinition(): void
    {
        $config = DeviceConfigurationCatalog::configForProtocol('vivistar-iw', 'fall_detection');
        self::assertIsArray($config);
        self::assertSame('fallDetection', $config['key'] ?? null);
        self::assertSame('BP76', $config['command'] ?? null);

        $payload = DeviceConfigurationCatalog::commandPayload('vivistar-iw', 'fall_detection', ['enabled' => false]);
        self::assertSame('BP76', $payload['command']);
        self::assertSame(['fields' => ['0']], $payload['payload']);
    }

    public function testVivistarSOSContactsMetadataIncludesCategoryAndLimit(): void
    {
        $config = DeviceConfigurationCatalog::configForProtocol('vivistar-iw', 'sosContacts');
        self::assertIsArray($config);
        self::assertSame('contacts', $config['category'] ?? null);
        self::assertSame(3, $config['limit'] ?? null);
    }

    public function testSupplierSosPayloadsRejectMoreThanTheirDeclaredLimit(): void
    {
        foreach ([
            ['protocol' => 'vivistar-iw', 'key' => 'sosContacts'],
            ['protocol' => 'wonlex-json', 'key' => 'SOSNumber'],
        ] as $supplierConfig) {
            $config = DeviceConfigurationCatalog::configForProtocol(
                $supplierConfig['protocol'],
                $supplierConfig['key']
            );
            self::assertIsArray($config);
            $limit = (int)($config['limit'] ?? 0);
            self::assertGreaterThan(0, $limit);

            $numbers = array_map(
                static fn(int $index): string => '+35190000000' . $index,
                range(1, $limit + 1)
            );
            self::assertSame(
                "numbers must contain at most {$limit} values",
                DeviceConfigurationCatalog::validate(
                    $supplierConfig['protocol'],
                    $supplierConfig['key'],
                    ['numbers' => $numbers]
                )
            );
        }
    }

    public function testVivistarCallWhitelistAndSwitchAreSeparated(): void
    {
        $callWhitelist = DeviceConfigurationCatalog::configForProtocol('vivistar-iw', 'call_whitelist');
        self::assertIsArray($callWhitelist);
        self::assertSame('call_whitelist', $callWhitelist['key'] ?? null);
        self::assertSame('contacts', $callWhitelist['input'] ?? null);
        self::assertSame(10, $callWhitelist['limit'] ?? null);

        $switch = DeviceConfigurationCatalog::configForProtocol('vivistar-iw', 'whitelist_enabled');
        self::assertIsArray($switch);
        self::assertSame('whitelist_enabled', $switch['key'] ?? null);
        self::assertSame('toggle', $switch['input'] ?? null);
    }

    public function testVivistarCallWhitelistBuildsNamePhonePayload(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('vivistar-iw', 'call_whitelist', [
            'contacts' => [
                ['name' => 'HAVICARE', 'phone' => '+351278710140'],
            ],
        ]);

        self::assertSame('BP14', $payload['command']);
        self::assertSame(
            ['00480041005600490043004100520045|+351278710140', '', '', '', '', '', '', '', '', ''],
            $payload['payload']['fields'] ?? []
        );
    }

    public function testVivistarAutoHealthMeasurementAcceptsZeroMinutesWhenDisabled(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('vivistar-iw', 'autoHealthMeasurement', [
            'enabled' => false,
            'intervalMinutes' => 0,
        ]);

        self::assertSame('BP86', $payload['command']);
        self::assertSame(['0', '0'], $payload['payload']['fields'] ?? []);
    }

    public function testVivistarPushMessageBuildsBp40WithUtf16Hex(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('vivistar-iw', 'pushMessage', ['message' => 'are you ok?']);

        self::assertSame('BP40', $payload['command']);
        self::assertSame('00610072006500200079006f00750020006f006b003f', strtolower($payload['payload']['fields'][0] ?? ''));

        $wire = DeviceCommandCatalog::buildDownlink('vivistar-iw', '861265061009822', $payload['command'], $payload['payload']);
        self::assertStringStartsWith('IWBP40,861265061009822,', $wire);
        self::assertStringEndsWith(',' . ($payload['payload']['fields'][0] ?? '') . '#', $wire);
    }

    public function testVivistarPushMessageRejectsEmptyMessage(): void
    {
        self::assertSame(
            'message is required',
            DeviceConfigurationCatalog::validate('vivistar-iw', 'pushMessage', ['message' => ''])
        );
    }

    public function testVivistarAlarmClockAcceptsPublicRecurrencePayload(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('vivistar-iw', 'alarm_clock', [
            'items' => [
                [
                    'time' => '09:00',
                    'enabled' => true,
                    'type' => 2,
                    'recurrence' => ['kind' => 'custom', 'days' => [2]],
                ],
            ],
        ]);

        self::assertSame('BP85', $payload['command']);
        self::assertSame(['1', '1', '0900,2,1,2'], $payload['payload']['fields'] ?? []);
    }

    public function testVivistarAlarmClockRejectsMissingType(): void
    {
        self::assertSame(
            'type is required',
            DeviceConfigurationCatalog::validate('vivistar-iw', 'alarm_clock', [
                'items' => [
                    [
                        'time' => '09:00',
                        'enabled' => true,
                        'recurrence' => ['kind' => 'custom', 'days' => [1]],
                    ],
                ],
            ])
        );
    }

    public function testVivistarAlarmClockOnceDoesNotRequireDays(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('vivistar-iw', 'alarm_clock', [
            'items' => [
                [
                    'time' => '09:00',
                    'enabled' => true,
                    'type' => 1,
                    'recurrence' => ['kind' => 'once'],
                ],
            ],
        ]);

        self::assertSame('BP85', $payload['command']);
        self::assertSame(['1', '1', '0900,,1,1'], $payload['payload']['fields'] ?? []);
    }

    public function testFourPTouchAlarmClockRejectsType(): void
    {
        self::assertSame(
            'type is not supported for four-p-touch alarm_clock',
            DeviceConfigurationCatalog::validate('four-p-touch', 'alarm_clock', [
                'items' => [
                    [
                        'time' => '09:00',
                        'enabled' => true,
                        'type' => 1,
                        'recurrence' => ['kind' => 'once'],
                    ],
                ],
            ])
        );
    }

    public function testFourPTouchFallSensitivityMetadataIncludesNativeLevelOptions(): void
    {
        $config = DeviceConfigurationCatalog::configForProtocol('four-p-touch', 'fallDownSensitivity');
        self::assertIsArray($config);
        self::assertSame('fallSensitivityLevels', $config['input'] ?? null);
        self::assertSame(
            [
                ['value' => 1, 'label' => 'Máxima'],
                ['value' => 2, 'label' => 'Muito Alta'],
                ['value' => 3, 'label' => 'Alta'],
                ['value' => 4, 'label' => 'Moderada'],
                ['value' => 5, 'label' => 'Baixa'],
                ['value' => 6, 'label' => 'Muito Baixa'],
                ['value' => 7, 'label' => 'Quase Mínima'],
                ['value' => 8, 'label' => 'Mínima'],
            ],
            $config['options']['sensitivity'] ?? null
        );
        self::assertSame(
            [
                ['value' => 6, 'label' => '6 níveis'],
                ['value' => 8, 'label' => '8 níveis'],
            ],
            $config['options']['levels'] ?? null
        );
    }

    public function testPublicAlarmClockAliasMapsToGenericCapability(): void
    {
        self::assertSame('alarm_clock', GenericModelCapabilityCatalog::mapConfigurationKey('alarm_clock'));

        self::assertSame('call_whitelist', GenericModelCapabilityCatalog::mapConfigurationKey('call_whitelist'));
        self::assertSame('sos_contacts', GenericModelCapabilityCatalog::mapConfigurationKey('SOSNumber'));
        self::assertSame('whitelist_enabled', GenericModelCapabilityCatalog::mapConfigurationKey('whitelistSwitch'));
        self::assertSame('whitelist_enabled', GenericModelCapabilityCatalog::mapConfigurationKey('rejectUnknownCalls'));

        $config = DeviceConfigurationCatalog::configForProtocol('vivistar-iw', 'call_whitelist');
        self::assertIsArray($config);
        self::assertSame('call_whitelist', $config['key'] ?? null);

        $switch = DeviceConfigurationCatalog::configForProtocol('vivistar-iw', 'whitelist_enabled');
        self::assertIsArray($switch);
        self::assertSame('whitelist_enabled', $switch['key'] ?? null);

        $fourPTouch = DeviceConfigurationCatalog::configForProtocol('four-p-touch', 'alarmClock');
        self::assertIsArray($fourPTouch);
        self::assertSame('alarmClock', $fourPTouch['key'] ?? null);

        $wonlexAlarm = DeviceConfigurationCatalog::configForProtocol('wonlex-json', 'alarmClock');
        self::assertSame(10, $wonlexAlarm['limit'] ?? null);
    }

    public function testWonlexLocationIntervalBuildsJsonPayload(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'locationInterval', ['intervalTime' => 300]);
        self::assertSame('locationInterval', $payload['command']);

        $wire = DeviceCommandCatalog::buildDownlink('wonlex-json', '865028000000308', $payload['command'], $payload['payload']);
        $decoded = (new WonlexAdapter())->decodeIncoming($wire);

        self::assertIsArray($decoded);
        self::assertSame('locationInterval', $decoded['type'] ?? null);
        self::assertSame(300, $decoded['data']['intervalTime'] ?? null);
    }

    public function testWonlexHealthRequestsUseOnlyDocumentedRequiredFields(): void
    {
        foreach ([
            'dnHeartRate',
            'dnBP',
            'dnBO',
            'dnTemperature',
            'dnBreathe',
            'dnECG',
            'dnHRV',
            'dnPPG',
            'dnRR',
        ] as $nativeType) {
            $wire = DeviceCommandCatalog::buildDownlink(
                'wonlex-json',
                '868705080300697',
                $nativeType,
                [],
                ['ident' => 123456]
            );
            $decoded = (new WonlexAdapter())->decodeIncoming($wire);

            self::assertIsArray($decoded, $nativeType);
            self::assertSame($nativeType, $decoded['type'] ?? null, $nativeType);
            self::assertSame(123456, $decoded['ident'] ?? null, $nativeType);
            self::assertSame('s:down', $decoded['ref'] ?? null, $nativeType);
            self::assertSame(
                ['type', 'imei', 'timestamp'],
                array_keys($decoded['data'] ?? []),
                $nativeType
            );
        }
    }

    public function testWonlexWaveformRequestPreservesExplicitOptionalSamplingParameters(): void
    {
        $wire = DeviceCommandCatalog::buildDownlink(
            'wonlex-json',
            '868705080300697',
            'dnECG',
            [
                'frequency' => '500',
                'oneTime' => 30,
                'collectionLogo' => '87654321',
            ],
            ['ident' => 123456]
        );
        $decoded = (new WonlexAdapter())->decodeIncoming($wire);

        self::assertSame('500', $decoded['data']['frequency'] ?? null);
        self::assertSame(30, $decoded['data']['oneTime'] ?? null);
        self::assertSame('87654321', $decoded['data']['collectionLogo'] ?? null);
    }

    public function testWonlexMeasurementIntervalsBuildNestedConfigPayloads(): void
    {
        $ppg = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexPPGInterval', ['interval' => 60]);
        self::assertSame('deviceMeasuringFrequency', $ppg['command']);
        self::assertSame(['configs' => ['upPPG' => ['interval' => '60']]], $ppg['payload']);

        $rr = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexRRInterval', ['interval' => 15]);
        self::assertSame('deviceMeasuringFrequency', $rr['command']);
        self::assertSame(['configs' => ['upRR' => ['interval' => '15']]], $rr['payload']);

        $bloodPressure = DeviceConfigurationCatalog::commandPayload(
            'wonlex-json',
            'wonlexBPInterval',
            ['interval' => 0]
        );
        self::assertSame('deviceMeasuringFrequency', $bloodPressure['command']);
        self::assertSame(['configs' => ['upBP' => ['interval' => '0']]], $bloodPressure['payload']);
    }

    public function testWonlexConfigurationReplyMetadataUsesSameTypeAcknowledgements(): void
    {
        foreach (DeviceConfigurationCatalog::configsForProtocol('wonlex-json') as $entry) {
            $command = (string)($entry['command'] ?? '');
            self::assertSame(
                [$command],
                $entry['expectedReplyTypes'] ?? [],
                (string)($entry['key'] ?? $command)
            );
        }
    }

    public function testWonlexComplexDashboardConfigurationsUseStructuredEditors(): void
    {
        $medication = DeviceConfigurationCatalog::configForProtocol('wonlex-json', 'dnMedicationPlan');
        $weather = DeviceConfigurationCatalog::configForProtocol('wonlex-json', 'weatherData');

        self::assertIsArray($medication);
        self::assertIsArray($weather);
        self::assertSame('wonlexMedicationPlans', $medication['input'] ?? null);
        self::assertSame('wonlexWeather', $weather['input'] ?? null);
        self::assertStringNotContainsString('JSON', (string)($medication['label'] ?? ''));
        self::assertStringNotContainsString('JSON', (string)($weather['label'] ?? ''));
    }

    public function testWonlexStructuredDeviceConfigBuildsNestedPayloads(): void
    {
        $toggle = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexPPGBPTrend', ['switchState' => true]);
        self::assertSame('deviceConfig', $toggle['command']);
        self::assertSame(['configs' => ['PPGBPTrend' => ['switchState' => 1]]], $toggle['payload']);

        $steps = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexStepTarget', ['steps' => 7500]);
        self::assertSame('deviceConfig', $steps['command']);
        self::assertSame(['configs' => ['StepTarget' => ['steps' => 7500]]], $steps['payload']);
    }

    public function testWonlexAdultHealthPayloadsUseDocumentedWireKeys(): void
    {
        $binding = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'dnDevBindStatus', ['enabled' => true]);
        self::assertSame(['status' => 1], $binding['payload']);

        $alarm = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'alarmClock', [
            'items' => [['label' => 'Medicine', 'time' => '08:00', 'week' => '1111100', 'enabled' => true]],
        ]);
        self::assertArrayHasKey('alarmClockList', $alarm['payload']);
        self::assertSame('08:00', $alarm['payload']['alarmClockList'][0]['startTime']);

        $dailyAlarm = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'alarmClock', [
            'items' => [[
                'label' => 'Daily medicine',
                'time' => '20:00',
                'enabled' => true,
                'recurrence' => ['kind' => 'daily'],
            ]],
        ]);
        self::assertSame('1111111', $dailyAlarm['payload']['alarmClockList'][0]['week']);

        $audioUrl = 'https://developer.mozilla.org/shared-assets/audio/t-rex-roar.mp3';
        $onceAlarm = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'alarmClock', [
            'items' => [[
                'label' => 'Audio test',
                'time' => '20:03',
                'enabled' => true,
                'recurrence' => ['kind' => 'once'],
                'url' => $audioUrl,
            ]],
        ]);
        $weekday = (int)date('N');
        $expectedWeek = str_repeat('0', $weekday - 1) . '1' . str_repeat('0', 7 - $weekday);
        self::assertSame($expectedWeek, $onceAlarm['payload']['alarmClockList'][0]['week']);
        self::assertSame('Audio test', $onceAlarm['payload']['alarmClockList'][0]['label']);
        self::assertSame($audioUrl, $onceAlarm['payload']['alarmClockList'][0]['url']);

        $family = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'familyNumber', [
            'contacts' => [['name' => 'Care', 'phone' => '+351210000000', 'areaCode' => '351']],
        ]);
        self::assertSame('+351210000000', $family['payload']['familyNumbers'][0]['phone']);

        $sos = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'SOSNumber', [
            'numbers' => ['+351210000000'],
        ]);
        self::assertArrayHasKey('sosNumbers', $sos['payload']);
        self::assertSame('+351210000000', $sos['payload']['sosNumbers'][0]['phone']);

        $medication = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'dnMedicationPlan', [
            'plans' => [[
                'drugType' => 0,
                'drugName' => 'Medicine',
                'drugDose' => 1.5,
                'drugUnit' => '0',
                'drugStartTime' => '2026-07-28',
                'drugEndTime' => '2026-08-28',
                'drugInterval' => 1,
                'drugTime' => ['alarmClock' => ['Morning' => '08:00'], 'checkboxes' => [0], 'radio' => 0],
            ]],
        ]);
        self::assertArrayNotHasKey('plans', $medication['payload']);
        self::assertSame('Medicine', $medication['payload']['drugName']);
        self::assertSame(1.5, $medication['payload']['drugDose']);
    }

    public function testWonlexAlarmRejectsNonHttpAudioUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('alarm url must be a valid HTTP or HTTPS URL');

        DeviceConfigurationCatalog::commandPayload('wonlex-json', 'alarmClock', [
            'items' => [[
                'time' => '20:04',
                'enabled' => true,
                'recurrence' => ['kind' => 'once'],
                'url' => 'file:///tmp/alarm.mp3',
            ]],
        ]);
    }

    public function testWonlexMedicationPlansExpandToOneWireCommandPerPlan(): void
    {
        $base = [
            'drugType' => 0,
            'drugDose' => 1,
            'drugUnit' => '0',
            'drugStartTime' => '2026-07-28',
            'drugEndTime' => '2026-08-28',
            'drugInterval' => 1,
            'drugTime' => ['alarmClock' => ['Morning' => '08:00'], 'checkboxes' => [0], 'radio' => 0],
        ];

        $commands = DeviceConfigurationCatalog::commandPayloads('wonlex-json', 'dnMedicationPlan', [
            'plans' => [
                $base + ['drugName' => 'Morning medicine'],
                $base + ['drugName' => 'Evening medicine'],
            ],
        ]);

        self::assertCount(2, $commands);
        self::assertSame(['dnMedicationPlan', 'dnMedicationPlan'], array_column($commands, 'command'));
        self::assertSame('Morning medicine', $commands[0]['payload']['drugName']);
        self::assertSame('Evening medicine', $commands[1]['payload']['drugName']);
        self::assertArrayNotHasKey('plans', $commands[0]['payload']);
    }

    public function testWonlexSleepAndThresholdConfigsBuildStructuredPayloads(): void
    {
        $sleep = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexSleepIntervalOrSwitch', [
            'switchState' => true,
            'sleepStartTime' => '220000',
            'sleepEndTime' => '100000',
            'sleepTarget' => 480,
        ]);
        self::assertSame('deviceConfig', $sleep['command']);
        self::assertSame([
            'configs' => [
                'SleepIntervalOrSwitch' => [
                    'switchState' => 1,
                    'sleepStartTime' => '220000',
                    'sleepEndTime' => '100000',
                    'sleepTarget' => 480,
                ],
            ],
        ], $sleep['payload']);

        $oxygen = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexBloodOxygenWarn', [
            'switchState' => true,
            'reminderValue' => 90,
        ]);
        self::assertSame([
            'configs' => [
                'bloodOxygenWarn' => [
                    'switchState' => 1,
                    'reminderValue' => 90,
                ],
            ],
        ], $oxygen['payload']);
    }

    public function testWonlexHeartRateAndBloodPressureWarningsBuildStructuredPayloads(): void
    {
        $heartRate = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexHeartRateHighRemind', [
            'switchState' => true,
            'remindValue' => 120,
            'exerciseSwitchState' => true,
            'exerciseHRMin' => 100,
            'exerciseHRMax' => 140,
            'exerciseRemindValue' => 140,
        ]);
        self::assertSame([
            'configs' => [
                'HROvertopRemind' => [
                    'switchState' => 1,
                    'remindValue' => 120,
                    'exerciseSwitchState' => 1,
                    'exerciseHRMin' => 100,
                    'exerciseHRMax' => 140,
                    'exerciseRemindValue' => 140,
                ],
            ],
        ], $heartRate['payload']);

        $bpWarning = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexBPEarlyWarning', [
            'switchState' => true,
            'hpWarn' => 135,
            'LPWarn' => 90,
        ]);
        self::assertSame([
            'configs' => [
                'BPEarlyWarning' => [
                    'switchState' => 1,
                    'hpWarn' => 135,
                    'LPWarn' => 90,
                ],
            ],
        ], $bpWarning['payload']);
    }

    public function testInvalidConfigIsRejected(): void
    {
        self::assertSame(
            'intervalSeconds must be at least 30 for mode 8',
            DeviceConfigurationCatalog::validate('vivistar-iw', 'workingMode', ['mode' => 8, 'intervalSeconds' => 10, 'gpsEnabled' => true])
        );
    }

    public function testFourPTouchUploadIntervalBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'uploadInterval', ['intervalSeconds' => 600]);
        self::assertSame('UPLOAD', $payload['command']);
        self::assertSame(['fields' => ['600']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '637507597567372', $payload['command'], $payload['payload'], ['deviceId' => '7597567372']);
        self::assertSame('[3G*7597567372*000A*UPLOAD,600]', $wire);
    }

    public function testFourPTouchUploadIntervalRequiresProtocolMinimum(): void
    {
        self::assertSame(
            'intervalSeconds must be between 60 and 65535',
            DeviceConfigurationCatalog::validate('four-p-touch', 'uploadInterval', ['intervalSeconds' => 59])
        );
    }

    public function testFourPTouchFallsBackToCanonicalImeiOnlyWhenNoDeviceIdIsProvided(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'uploadInterval', ['intervalSeconds' => 600]);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertSame('[3G*8800000015*000A*UPLOAD,600]', $wire);
    }

    public function testFourPTouchIgnoresEmptyDeviceIdContext(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'uploadInterval', ['intervalSeconds' => 600]);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '7597567372', $payload['command'], $payload['payload'], ['deviceId' => '']);
        self::assertSame('[3G*7597567372*000A*UPLOAD,600]', $wire);
    }

    public function testFourPTouchWhitelistSupportsFiveNumbers(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'whitelistGroup1', [
            'numbers' => ['111', '222', '333', '444', '555'],
        ]);

        self::assertSame('WHITELIST1', $payload['command']);
        self::assertSame(['fields' => ['111', '222', '333', '444', '555']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        $decoded = (new FourPTouchAdapter())->decodeIncoming($wire);

        self::assertIsArray($decoded);
        self::assertSame('WHITELIST1', $decoded['type'] ?? null);
        self::assertSame(['111', '222', '333', '444', '555'], $decoded['data']['fields'] ?? null);
    }

    public function testFourPTouchTakePillsBuildsVoiceReminderFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'takePills', [
            'reminderSettings' => [
                'time' => '11:25',
                'enabled' => true,
                'frequency' => 2,
                'custom' => '',
            ],
            'number' => 1,
            'reminderText' => 'meds',
            'voiceData' => $this->sampleWavBase64(),
            'voiceMimeType' => 'audio/wav',
        ]);

        self::assertSame('TAKEPILLS', $payload['command']);
        self::assertSame(['11:25-1-2', '1', '006D006500640073'], array_slice($payload['payload']['fields'] ?? [], 0, 3));
        self::assertStringStartsWith("#!AMR\n", $payload['payload']['fields'][3] ?? '');

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('TAKEPILLS,11:25-1-2,1,006D006500640073,', $wire);
        self::assertStringContainsString(',' . ($payload['payload']['fields'][3] ?? '') . ']', $wire);
        $encodedAudio = (string)($payload['payload']['fields'][3] ?? '');
        self::assertGreaterThan(strlen("#!AMR\n"), strlen($encodedAudio));
        for ($offset = 0, $length = strlen($encodedAudio); $offset < $length; $offset++) {
            $byte = ord($encodedAudio[$offset]);
            self::assertNotContains($byte, [0x5B, 0x5D, 0x2C, 0x2A]);
            if ($byte === 0x7D) {
                self::assertLessThan($length, $offset + 1);
                self::assertContains(ord($encodedAudio[++$offset]), [0x01, 0x02, 0x03, 0x04, 0x05]);
            }
        }
    }

    public function testFourPTouchTakePillsStripsLegacyDataUrlsFromVoiceData(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'takePills', [
            'reminderSettings' => [
                'time' => '11:25',
                'enabled' => true,
                'frequency' => 2,
                'custom' => '',
            ],
            'number' => 1,
            'reminderText' => 'meds',
            'voiceData' => 'data:audio/wav;base64,' . $this->sampleWavBase64(),
            'voiceMimeType' => 'audio/wav',
        ]);

        self::assertStringStartsWith("#!AMR\n", $payload['payload']['fields'][3] ?? '');
    }

    public function testFourPTouchTakePillsAllowsOmittingVoiceAudio(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'takePills', [
            'reminderSettings' => [
                'time' => '11:25',
                'enabled' => true,
                'frequency' => 2,
                'custom' => '',
            ],
            'number' => 1,
            'reminderText' => 'meds',
        ]);

        self::assertSame('TAKEPILLS', $payload['command']);
        self::assertSame(['11:25-1-2', '1', '006D006500640073', ''], $payload['payload']['fields'] ?? []);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('TAKEPILLS,11:25-1-2,1,006D006500640073,]', $wire);
    }

    public function testFourPTouchRejectsContentLargerThanFourDigitLengthField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the protocol maximum');

        DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', 'TAKEPILLS', [
            'fields' => [str_repeat('A', 65536)],
        ]);
    }

    public function testFourPTouchTakePillsMapsToMedicationReminderCapability(): void
    {
        $config = DeviceConfigurationCatalog::configForProtocol('four-p-touch', 'takePills');

        self::assertIsArray($config);
        self::assertSame('alerts', $config['category'] ?? null);
        self::assertSame(3, $config['limit'] ?? null);
        self::assertSame([
            'frequency' => [
                ['value' => 1, 'label' => 'Uma vez'],
                ['value' => 2, 'label' => 'Diariamente'],
                ['value' => 3, 'label' => 'Personalizado'],
            ],
        ], $config['options'] ?? null);
        self::assertSame('medication_reminders', GenericModelCapabilityCatalog::mapConfigurationKey('takePills'));
    }

    public function testFourPTouchLanguageTimezoneBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'languageTimezone', [
            'language' => 3,
            'timeZone' => '0',
        ]);

        self::assertSame('LZ', $payload['command']);
        self::assertSame(['fields' => ['3', '0']], $payload['payload']);
    }

    public function testFourPTouchLanguageTimezoneAllowsEnglish(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'languageTimezone', [
            'language' => 0,
            'timeZone' => '+01:00',
        ]);

        self::assertSame(['fields' => ['0', '+01:00']], $payload['payload']);
    }

    public function testFourPTouchFallDownSensitivityBuildsFirmwareAwarePayload(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'fallDownSensitivity', [
            'sensitivity' => 5,
            'levels' => 8,
        ]);

        self::assertSame('LSSET', $payload['command']);
        self::assertSame(['fields' => ['5+8']], $payload['payload']);
        self::assertSame(
            '[3G*4504816144*0009*LSSET,5+8]',
            DeviceCommandCatalog::buildDownlink(
                'four-p-touch',
                '4504816144',
                $payload['command'],
                $payload['payload']
            )
        );

        $reply = (new FourPTouchAdapter())->decodeIncoming(
            '[3G*4504816144*0007*LSSET,5]'
        );
        self::assertIsArray($reply);
        self::assertSame('LSSET', $reply['type'] ?? null);
        self::assertSame(['5'], $reply['data']['fields'] ?? null);
    }

    public function testFourPTouchFallSensitivityPublicAliasBuildsFirmwareAwarePayload(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'fall_sensitivity', [
            'sensitivity' => 4,
            'levels' => 6,
        ]);

        self::assertSame('LSSET', $payload['command']);
        self::assertSame(['fields' => ['4+6']], $payload['payload']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function fourPTouchConfigAliasProvider(): iterable
    {
        yield 'location reporting interval' => ['location_reporting_interval', 'uploadInterval'];
        yield 'monitor number' => ['monitor_number', 'monitorNumber'];
        yield 'center number' => ['center_number', 'centerNumber'];
        yield 'sos sms alert' => ['sos_sms_alert', 'sosSmsAlerts'];
        yield 'low battery alert' => ['low_battery_alert', 'lowBatterySmsAlerts'];
        yield 'remove watch alarm' => ['remove_watch_alarm', 'removeWatchAlarm'];
        yield 'remove watch sms alert' => ['remove_watch_sms_alert', 'removeWatchSmsAlerts'];
        yield 'fall detection' => ['fall_detection', 'fallDownAlert'];
        yield 'fall sensitivity' => ['fall_sensitivity', 'fallDownSensitivity'];
        yield 'medication reminders' => ['medication_reminders', 'takePills'];
        yield 'auto vitals interval' => ['auto_vitals_interval', 'healthAutoMeasurement'];
        yield 'pedometer schedule' => ['pedometer_schedule', 'walkTime'];
        yield 'sleep monitoring' => ['sleep_monitoring', 'sleepTime'];
        yield 'temperature measurement interval' => ['temperature_measurement_interval', 'bodyTemperatureInterval'];
        yield 'power off' => ['power_off', 'powerOffCommand'];
        yield 'push message' => ['push_message', 'pushMessage'];
        yield 'make call' => ['make_call', 'makeCall'];
        yield 'reset device' => ['reset_device', 'resetCommand'];
        yield 'firmware version' => ['firmwareVersion', 'firmwareVersion'];
        yield 'device status' => ['deviceStatus', 'deviceStatus'];
        yield 'device password' => ['device_password', 'devicePassword'];
        yield 'language timezone' => ['language_timezone', 'languageTimezone'];
        yield 'whitelist enabled' => ['whitelist_enabled', 'rejectUnknownCalls'];
        yield 'sound profile' => ['sound_profile', 'profile'];
        yield 'do not disturb' => ['do_not_disturb', 'doNotDisturb'];
    }

    /**
     * @dataProvider fourPTouchConfigAliasProvider
     */
    public function testFourPTouchPublicAliasesResolveToNativeConfigKeys(string $publicKey, string $nativeKey): void
    {
        $config = DeviceConfigurationCatalog::configForProtocol('four-p-touch', $publicKey);

        self::assertIsArray($config);
        self::assertSame($nativeKey, $config['key'] ?? null);
    }

    public function testFourPTouchWalkTimeAndTemperatureIntervalBuildNativeFields(): void
    {
        $walkTime = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'walkTime', [
            'ranges' => ['08:10-09:30', '10:10-11:30'],
        ]);
        self::assertSame('WALKTIME', $walkTime['command']);
        self::assertSame(['fields' => ['08:10-09:30', '10:10-11:30']], $walkTime['payload']);

        $temperature = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'bodyTemperatureInterval', [
            'enabled' => true,
            'intervalHours' => 2,
        ]);
        self::assertSame('bodytemp', $temperature['command']);
        self::assertSame(['fields' => ['1', '2']], $temperature['payload']);
    }

    public function testFourPTouchWalkTimeAllowsEmptyRanges(): void
    {
        $walkTime = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'walkTime', [
            'ranges' => [],
        ]);

        self::assertSame('WALKTIME', $walkTime['command']);
        self::assertSame(['fields' => []], $walkTime['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $walkTime['command'], $walkTime['payload']);
        self::assertSame('[3G*8800000015*0008*WALKTIME]', $wire);
    }

    public function testFourPTouchCommandsExposeSplitHealthRequests(): void
    {
        $commands = DeviceCommandCatalog::commandsForProtocol('four-p-touch');

        self::assertCount(6, $commands);
        self::assertSame('CR', $commands[0]['command']);
        self::assertSame('VERNO', $commands[4]['command']);
        self::assertSame('TS', $commands[5]['command']);
        self::assertContains('bphrt', $commands[1]['expectedReplyTypes']);
        self::assertContains('btemp2', $commands[3]['expectedReplyTypes']);
    }

    public function testFourPTouchTakePillsHandlesMultipleReminders(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'takePills', [
            'reminderSettings' => [
                ['time' => '11:25', 'enabled' => true, 'frequency' => 2, 'custom' => ''],
                ['time' => '14:30', 'enabled' => false, 'frequency' => 1, 'custom' => ''],
                ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '1010'],
            ],
            'number' => 3,
            'reminderText' => 'meds',
            'voiceData' => '',
        ]);

        self::assertSame('TAKEPILLS', $payload['command']);
        self::assertSame(
            ['11:25-1-2-14:30-0-1-18:00-1-3-1010', '3', '006D006500640073', ''],
            $payload['payload']['fields'] ?? [],
        );

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('TAKEPILLS,11:25-1-2-14:30-0-1-18:00-1-3-1010,3,006D006500640073,]', $wire);
    }

    public function testFourPTouchTakePillsHandlesSingleReminderBackwardCompatible(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'takePills', [
            'reminderSettings' => [
                'time' => '11:25',
                'enabled' => true,
                'frequency' => 2,
                'custom' => '',
            ],
            'number' => 1,
            'reminderText' => 'meds',
        ]);

        self::assertSame('TAKEPILLS', $payload['command']);
        self::assertSame(['11:25-1-2', '1', '006D006500640073', ''], $payload['payload']['fields'] ?? []);
    }

    public function testFourPTouchTakePillsAllowsClearingAllReminders(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'takePills', [
            'reminderSettings' => [],
            'reminderText' => '',
            'voiceData' => '',
        ]);

        self::assertSame('TAKEPILLS', $payload['command']);
        self::assertSame(['00:00-0-1', '1', '004D', ''], $payload['payload']['fields'] ?? []);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('TAKEPILLS,00:00-0-1,1,004D,]', $wire);
    }

    public function testFourPTouchSosNumber1BuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sosNumber1', ['phone' => '123456789']);
        self::assertSame('SOS1', $payload['command']);
        self::assertSame(['fields' => ['123456789']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('SOS1,123456789', $wire);
    }

    public function testFourPTouchSosNumber1AllowsEmptyPhoneToClearSlot(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sosNumber1', ['phone' => '']);

        self::assertSame('SOS1', $payload['command']);
        self::assertSame(['fields' => []], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertSame('[3G*8800000015*0004*SOS1]', $wire);
    }

    public function testFourPTouchSosNumber1RejectsArrayPhone(): void
    {
        self::assertSame(
            'phone must be a string',
            DeviceConfigurationCatalog::validate('four-p-touch', 'sosNumber1', ['phone' => ['123456789']])
        );
    }

    public function testFourPTouchSosNumber2BuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sosNumber2', ['phone' => '987654321']);
        self::assertSame('SOS2', $payload['command']);
        self::assertSame(['fields' => ['987654321']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('SOS2,987654321', $wire);
    }

    public function testFourPTouchSosNumber3BuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sosNumber3', ['phone' => '555555555']);
        self::assertSame('SOS3', $payload['command']);
        self::assertSame(['fields' => ['555555555']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('SOS3,555555555', $wire);
    }

    public function testFourPTouchMonitorNumberBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'monitorNumber', ['phone' => '13100010002']);
        self::assertSame('MONITOR', $payload['command']);
        self::assertSame(['fields' => ['13100010002']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('MONITOR,13100010002', $wire);
    }

    public function testFourPTouchDevicePasswordBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'devicePassword', ['password' => '111111']);
        self::assertSame('PW', $payload['command']);
        self::assertSame(['fields' => ['111111']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('PW,111111', $wire);
    }

    public function testFourPTouchSosSmsAlertsBuildsNativeFields(): void
    {
        $on = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sosSmsAlerts', ['enabled' => true]);
        self::assertSame('SOSSMS', $on['command']);
        self::assertSame(['fields' => ['1']], $on['payload']);

        $off = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sosSmsAlerts', ['enabled' => false]);
        self::assertSame(['fields' => ['0']], $off['payload']);
    }

    public function testFourPTouchLowBatterySmsAlertsBuildsNativeFields(): void
    {
        $on = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'lowBatterySmsAlerts', ['enabled' => true]);
        self::assertSame('LOWBAT', $on['command']);
        self::assertSame(['fields' => ['1']], $on['payload']);

        $off = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'lowBatterySmsAlerts', ['enabled' => false]);
        self::assertSame(['fields' => ['0']], $off['payload']);
    }

    public function testFourPTouchRemoveWatchAlarmBuildsNativeFields(): void
    {
        $on = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'removeWatchAlarm', ['enabled' => true]);
        self::assertSame('REMOVE', $on['command']);
        self::assertSame(['fields' => ['1']], $on['payload']);

        $off = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'removeWatchAlarm', ['enabled' => false]);
        self::assertSame(['fields' => ['0']], $off['payload']);
    }

    public function testFourPTouchRemoveWatchSmsAlertsBuildsNativeFields(): void
    {
        $on = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'removeWatchSmsAlerts', ['enabled' => true]);
        self::assertSame('REMOVESMS', $on['command']);
        self::assertSame(['fields' => ['1']], $on['payload']);

        $off = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'removeWatchSmsAlerts', ['enabled' => false]);
        self::assertSame(['fields' => ['0']], $off['payload']);
    }

    public function testFourPTouchFallDownAlertBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'fallDownAlert', [
            'enabled' => true,
            'callCenterOnFall' => false,
        ]);
        self::assertSame('FALLDOWN', $payload['command']);
        self::assertSame(['fields' => ['1', '0']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('FALLDOWN,1,0', $wire);
    }

    public function testFourPTouchFallDownAlertDefaultsCallCenterOffWhenMissing(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'fallDownAlert', [
            'enabled' => true,
        ]);

        self::assertSame('FALLDOWN', $payload['command']);
        self::assertSame(['fields' => ['1', '0']], $payload['payload']);
    }

    public function testFourPTouchFallDownSensitivityDefaultsFirmwareScaleToEight(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'fallDownSensitivity', [
            'sensitivity' => 5,
        ]);

        self::assertSame('LSSET', $payload['command']);
        self::assertSame(['fields' => ['5+8']], $payload['payload']);
        self::assertNull(DeviceConfigurationCatalog::validate('four-p-touch', 'fallDownSensitivity', [
            'sensitivity' => 5,
        ]));
    }

    public function testFourPTouchHealthAutoMeasurementBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'healthAutoMeasurement', [
            'enabled' => true,
            'intervalMinutes' => 5,
        ]);
        self::assertSame('HEALTHAUTOSET', $payload['command']);
        self::assertSame(['fields' => ['1', '1', '5']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('HEALTHAUTOSET,1,1,5', $wire);
    }

    public function testFourPTouchHealthAutoMeasurementAcceptsZeroMinutesWhenDisabled(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'healthAutoMeasurement', [
            'enabled' => false,
            'intervalMinutes' => 0,
        ]);

        self::assertSame('HEALTHAUTOSET', $payload['command']);
        self::assertSame(['fields' => ['1', '0', '0']], $payload['payload']);
    }

    public function testFourPTouchSleepTimeBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sleepTime', [
            'range' => '21:10-7:30',
        ]);
        self::assertSame('SLEEPTIME', $payload['command']);
        self::assertSame(['fields' => ['21:10-7:30']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('SLEEPTIME,21:10-7:30', $wire);
    }

    public function testFourPTouchMakeCallBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'makeCall', ['phone' => '00000000000']);
        self::assertSame('CALL', $payload['command']);
        self::assertSame(['fields' => ['00000000000']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('CALL,00000000000', $wire);
    }

    public function testFourPTouchCenterNumberBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'centerNumber', ['phone' => '00000000000']);
        self::assertSame('CENTER', $payload['command']);
        self::assertSame(['fields' => ['00000000000']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('CENTER,00000000000', $wire);
    }

    public function testFourPTouchPushMessageBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'pushMessage', ['message' => 'hello']);
        self::assertSame('MESSAGE', $payload['command']);
        self::assertSame('00680065006c006c006f', strtolower($payload['payload']['fields'][0] ?? ''));

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('MESSAGE,00680065006C006C006F', $wire);
    }

    public function testFourPTouchPushMessageRejectsEmptyMessage(): void
    {
        self::assertSame(
            'message is required',
            DeviceConfigurationCatalog::validate('four-p-touch', 'pushMessage', ['message' => ''])
        );
    }

    public function testFourPTouchResetCommandBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'resetCommand', []);
        self::assertSame('RESET', $payload['command']);
        self::assertSame(['fields' => []], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertSame('[3G*8800000015*0005*RESET]', $wire);
    }

    public function testFourPTouchPowerOffCommandBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'powerOffCommand', []);
        self::assertSame('POWEROFF', $payload['command']);
        self::assertSame(['fields' => []], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertSame('[3G*8800000015*0008*POWEROFF]', $wire);
    }

    public function testFourPTouchFindDeviceCommandBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'findDeviceCommand', []);
        self::assertSame('FIND', $payload['command']);
        self::assertSame(['fields' => []], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertSame('[3G*8800000015*0004*FIND]', $wire);
    }

    public function testFourPTouchDoNotDisturbBuildsNativeFields(): void
    {
        $on = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'doNotDisturb', ['enabled' => true]);
        self::assertSame('SILENCETIME', $on['command']);
        self::assertSame(['fields' => ['1']], $on['payload']);

        $off = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'doNotDisturb', ['enabled' => false]);
        self::assertSame(['fields' => ['0']], $off['payload']);
    }

    public function testFourPTouchFirmwareVersionBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'firmwareVersion', []);
        self::assertSame('VERNO', $payload['command']);
        self::assertSame(['fields' => []], $payload['payload']);
    }

    public function testFourPTouchDeviceStatusBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'deviceStatus', []);
        self::assertSame('TS', $payload['command']);
        self::assertSame(['fields' => []], $payload['payload']);
    }

    public function testFourPTouchAlarmClockBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'alarmClock', [
            'alarms' => [
                ['time' => '0730', 'enabled' => true, 'frequency' => 1],
            ],
        ]);
        self::assertSame('REMIND', $payload['command']);
        self::assertSame(['fields' => ['07:30-1-1']], $payload['payload']);
    }

    public function testFourPTouchAlarmClockAllowsEmptyAlarms(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'alarmClock', [
            'alarms' => [],
        ]);

        self::assertSame('REMIND', $payload['command']);
        self::assertSame(['fields' => []], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertSame('[3G*8800000015*0006*REMIND]', $wire);
    }

    public function testFourPTouchAlarmClockWithMultipleAlarmsBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'alarmClock', [
            'alarms' => [
                ['time' => '08:10', 'enabled' => true, 'frequency' => 1],
                ['time' => '14:30', 'enabled' => false, 'frequency' => 2],
                ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '0111110'],
            ],
        ]);
        self::assertSame('REMIND', $payload['command']);
        self::assertSame(['fields' => ['08:10-1-1', '14:30-0-2', '18:00-1-3-0111110']], $payload['payload']);
    }

    public function testFourPTouchAlarmClockRejectsInvalidCustomMask(): void
    {
        self::assertSame(
            'alarm custom days must be a 7-digit 0/1 mask',
            DeviceConfigurationCatalog::validate('four-p-touch', 'alarmClock', [
                'alarms' => [
                    ['time' => '08:10', 'enabled' => true, 'frequency' => 3, 'custom' => '111110'],
                ],
            ])
        );
    }

    public function testFourPTouchAlarmClockRejectsMoreThanThreeAlarms(): void
    {
        self::assertSame(
            'alarms must not contain more than 3 items',
            DeviceConfigurationCatalog::validate('four-p-touch', 'alarmClock', [
                'alarms' => [
                    ['time' => '08:10', 'enabled' => true, 'frequency' => 1],
                    ['time' => '14:30', 'enabled' => false, 'frequency' => 2],
                    ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '0111110'],
                    ['time' => '20:00', 'enabled' => true, 'frequency' => 1],
                ],
            ])
        );
    }

    public function testFourPTouchPhonebookBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'phonebook', [
            'contacts' => [['phone' => '123456789', 'name' => 'Ana']],
        ]);
        self::assertSame('PHB', $payload['command']);
        self::assertSame(['fields' => ['123456789', '0041006E0061']], $payload['payload']);
    }

    public function testFourPTouchPhonebookAllowsEmptyContacts(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'phonebook', [
            'contacts' => [],
        ]);

        self::assertSame('PHB', $payload['command']);
        self::assertSame(['fields' => []], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertSame('[3G*8800000015*0003*PHB]', $wire);
    }

    public function testFourPTouchPhonebookRejectsMoreThanFiveContacts(): void
    {
        self::assertSame(
            'contacts must not contain more than 5 items',
            DeviceConfigurationCatalog::validate('four-p-touch', 'phonebook', [
                'contacts' => [
                    ['phone' => '1', 'name' => 'A'],
                    ['phone' => '2', 'name' => 'B'],
                    ['phone' => '3', 'name' => 'C'],
                    ['phone' => '4', 'name' => 'D'],
                    ['phone' => '5', 'name' => 'E'],
                    ['phone' => '6', 'name' => 'F'],
                ],
            ])
        );
    }

    public function testFourPTouchPhonebookRejectsLongAsciiPhone(): void
    {
        self::assertSame(
            'phone must not exceed 20 ASCII characters',
            DeviceConfigurationCatalog::validate('four-p-touch', 'phonebook', [
                'contacts' => [
                    ['phone' => '123456789012345678901', 'name' => 'Ana'],
                ],
            ])
        );
    }

    public function testFourPTouchPhonebookRejectsNonAsciiPhone(): void
    {
        self::assertSame(
            'phone must contain ASCII characters only',
            DeviceConfigurationCatalog::validate('four-p-touch', 'phonebook', [
                'contacts' => [
                    ['phone' => '+3519☃', 'name' => 'Ana'],
                ],
            ])
        );
    }

    public function testFourPTouchPhonebookRejectsLongName(): void
    {
        self::assertSame(
            'name must not exceed 10 Unicode characters',
            DeviceConfigurationCatalog::validate('four-p-touch', 'phonebook', [
                'contacts' => [
                    ['phone' => '123456789', 'name' => 'ABCDEFGHIJK'],
                ],
            ])
        );
    }

    public function testFourPTouchSoundProfileBuildsNativeFields(): void
    {
        $vibrateAndRing = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sound_profile', ['mode' => 1]);
        self::assertSame('profile', $vibrateAndRing['command']);
        self::assertSame(['fields' => ['1']], $vibrateAndRing['payload']);

        $ringOnly = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sound_profile', ['mode' => 2]);
        self::assertSame(['fields' => ['2']], $ringOnly['payload']);

        $vibrateOnly = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sound_profile', ['mode' => 3]);
        self::assertSame(['fields' => ['3']], $vibrateOnly['payload']);

        $silent = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sound_profile', ['mode' => 4]);
        self::assertSame(['fields' => ['4']], $silent['payload']);
    }

    public function testFourPTouchSoundProfileRejectsInvalidMode(): void
    {
        self::assertSame(
            'mode must be between 1 and 4',
            DeviceConfigurationCatalog::validate('four-p-touch', 'sound_profile', ['mode' => 0])
        );
    }

    public function testFourPTouchRejectUnknownCallsBuildsNativeFields(): void
    {
        $on = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'rejectUnknownCalls', ['enabled' => true]);
        self::assertSame('DEVREFUSEPHONESWITCH', $on['command']);
        self::assertSame(['fields' => ['1']], $on['payload']);

        $off = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'rejectUnknownCalls', ['enabled' => false]);
        self::assertSame(['fields' => ['0']], $off['payload']);
    }

    public function testFourPTouchSosNumberRejectsEmptyPhone(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sosNumber1', ['phone' => '']);
        self::assertSame(['fields' => []], $payload['payload']);
        self::assertSame(
            'phone is required',
            DeviceConfigurationCatalog::validate('four-p-touch', 'monitorNumber', ['phone' => ''])
        );
        self::assertSame(
            'phone is required',
            DeviceConfigurationCatalog::validate('four-p-touch', 'makeCall', ['phone' => ''])
        );
        self::assertSame(
            'phone is required',
            DeviceConfigurationCatalog::validate('four-p-touch', 'centerNumber', ['phone' => ''])
        );
    }

    private function sampleWavBase64(): string
    {
        $sampleRate = 8000;
        $channels = 1;
        $bitsPerSample = 16;
        $samples = array_fill(0, 800, 0);
        $data = '';
        foreach ($samples as $sample) {
            $data .= pack('v', $sample);
        }

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
