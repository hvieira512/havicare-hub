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

    public function testVivistarSOSContactsMetadataIncludesCategoryAndLimit(): void
    {
        $config = DeviceConfigurationCatalog::configForProtocol('vivistar-iw', 'sosContacts');
        self::assertIsArray($config);
        self::assertSame('contacts', $config['category'] ?? null);
        self::assertSame(3, $config['limit'] ?? null);
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

    public function testPublicAlarmClockAliasMapsToGenericCapability(): void
    {
        self::assertSame('alarm_clock', GenericModelCapabilityCatalog::mapConfigurationKey('alarm_clock'));

        $config = DeviceConfigurationCatalog::configForProtocol('vivistar-iw', 'reminders');
        self::assertIsArray($config);
        self::assertSame('reminders', $config['key'] ?? null);
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

    public function testWonlexMeasurementIntervalsBuildNestedConfigPayloads(): void
    {
        $ppg = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexPPGInterval', ['interval' => 60]);
        self::assertSame('deviceMeasuringFrequency', $ppg['command']);
        self::assertSame(['configs' => ['upPPG' => ['interval' => '60']]], $ppg['payload']);

        $rr = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexRRInterval', ['interval' => 15]);
        self::assertSame('deviceMeasuringFrequency', $rr['command']);
        self::assertSame(['configs' => ['upRR' => ['interval' => '15']]], $rr['payload']);
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
        self::assertStringStartsWith('IyFBTVIK', $payload['payload']['fields'][3] ?? '');

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('TAKEPILLS,11:25-1-2,1,006D006500640073,', $wire);
        self::assertStringContainsString(',' . ($payload['payload']['fields'][3] ?? '') . ']', $wire);
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

        self::assertStringStartsWith('IyFBTVIK', $payload['payload']['fields'][3] ?? '');
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
        self::assertSame(['11:25-1-2', '1', '006D006500640073'], $payload['payload']['fields'] ?? []);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('TAKEPILLS,11:25-1-2,1,006D006500640073]', $wire);
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
            'sensitivityLevel' => 5,
            'totalLevels' => 8,
        ]);

        self::assertSame('LSSET', $payload['command']);
        self::assertSame(['fields' => ['5+8']], $payload['payload']);
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
            ['11:25-1-2-14:30-0-1-18:00-1-3-1010', '3', '006D006500640073'],
            $payload['payload']['fields'] ?? [],
        );

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('TAKEPILLS,11:25-1-2-14:30-0-1-18:00-1-3-1010,3,006D006500640073]', $wire);
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
        self::assertSame(['11:25-1-2', '1', '006D006500640073'], $payload['payload']['fields'] ?? []);
    }

    public function testFourPTouchSosNumber1BuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sosNumber1', ['phone' => '123456789']);
        self::assertSame('SOS1', $payload['command']);
        self::assertSame(['fields' => ['123456789']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('SOS1,123456789', $wire);
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
            'contacts' => [['phone' => '123456789']],
        ]);
        self::assertSame('PHB', $payload['command']);
        self::assertSame(['fields' => ['1,123456789']], $payload['payload']);
    }

    public function testFourPTouchPhonebookRejectsEmptyContacts(): void
    {
        self::assertSame(
            'contacts must be a non-empty array',
            DeviceConfigurationCatalog::validate('four-p-touch', 'phonebook', ['contacts' => []])
        );
    }

    public function testFourPTouchSoundProfileBuildsNativeFields(): void
    {
        $vibrateAndRing = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'soundProfile', ['mode' => 1]);
        self::assertSame('profile', $vibrateAndRing['command']);
        self::assertSame(['fields' => ['1']], $vibrateAndRing['payload']);

        $ringOnly = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'soundProfile', ['mode' => 2]);
        self::assertSame(['fields' => ['2']], $ringOnly['payload']);

        $vibrateOnly = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'soundProfile', ['mode' => 3]);
        self::assertSame(['fields' => ['3']], $vibrateOnly['payload']);

        $silent = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'soundProfile', ['mode' => 4]);
        self::assertSame(['fields' => ['4']], $silent['payload']);
    }

    public function testFourPTouchSoundProfileRejectsInvalidMode(): void
    {
        self::assertSame(
            'mode must be between 1 and 4',
            DeviceConfigurationCatalog::validate('four-p-touch', 'soundProfile', ['mode' => 0])
        );
    }

    public function testFourPTouchCallInRestrictionBuildsNativeFields(): void
    {
        $on = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'callInRestriction', ['enabled' => true]);
        self::assertSame('DEVREFUSEPHONESWITCH', $on['command']);
        self::assertSame(['fields' => ['1']], $on['payload']);

        $off = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'callInRestriction', ['enabled' => false]);
        self::assertSame(['fields' => ['0']], $off['payload']);
    }

    public function testFourPTouchSosNumberRejectsEmptyPhone(): void
    {
        self::assertSame(
            'phone is required',
            DeviceConfigurationCatalog::validate('four-p-touch', 'sosNumber1', ['phone' => ''])
        );
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
