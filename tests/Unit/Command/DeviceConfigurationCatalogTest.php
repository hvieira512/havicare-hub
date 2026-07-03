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
            'voiceData' => 'QUJDRA==',
        ]);

        self::assertSame('TAKEPILLS', $payload['command']);
        self::assertSame(['fields' => ['11:25-1-2', '1', '006D006500640073', 'QUJDRA==']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('TAKEPILLS,11:25-1-2,1,006D006500640073,', $wire);
        self::assertStringEndsWith(',QUJDRA==]', $wire);
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
            'voiceData' => 'data:audio/webm;base64,QUJDRA==',
        ]);

        self::assertSame(['fields' => ['11:25-1-2', '1', '006D006500640073', 'QUJDRA==']], $payload['payload']);
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

        self::assertCount(4, $commands);
        self::assertSame('CR', $commands[0]['command']);
        self::assertSame('fourPHeartRate', $commands[1]['id']);
        self::assertSame('hrtstart', $commands[1]['command']);
        self::assertSame('fourPBloodPressure', $commands[2]['id']);
        self::assertSame('fourPBodyTemperature', $commands[3]['id']);
        self::assertContains('bphrt', $commands[1]['expectedReplyTypes']);
        self::assertContains('bphrt', $commands[2]['expectedReplyTypes']);
        self::assertContains('btemp2', $commands[3]['expectedReplyTypes']);
    }
}
