<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\Configuration\Payload\FourPTouchPayloadBuilder;
use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\SupplierCapabilityTemplate;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use Hub\Protocol\Adapter\VivistarAdapter;
use Hub\Protocol\Adapter\WonlexAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\WavFixture;

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
        foreach (
            [
            ['protocol' => 'vivistar-iw', 'key' => 'sosContacts'],
            ['protocol' => 'wonlex-json', 'key' => 'SOSNumber'],
            ] as $supplierConfig
        ) {
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
        self::assertSame('call_whitelist', $callWhitelist['input'] ?? null);
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

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function vivistarWorkingModeProvider(): iterable
    {
        yield 'normal' => [['mode' => 'normal'], ['1']];
        yield 'power saving' => [['mode' => 'power_saving'], ['2']];
        yield 'emergency' => [['mode' => 'emergency'], ['3']];
        yield 'custom' => [['mode' => 'custom', 'intervalSeconds' => 60, 'gpsEnabled' => false], ['8', '60', '0']];
    }

    /**
     * @param array<string, mixed> $desired
     * @param list<string> $fields
     */
    #[DataProvider('vivistarWorkingModeProvider')]
    public function testVivistarWorkingModeNameBuildsItsNativeNumber(array $desired, array $fields): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('vivistar-iw', 'workingMode', $desired);

        self::assertSame('BP33', $payload['command']);
        self::assertSame($fields, $payload['payload']['fields'] ?? []);
    }

    public function testVivistarWorkingModeOptionsAreTheNames(): void
    {
        $config = DeviceConfigurationCatalog::configForProtocol('vivistar-iw', 'workingMode');

        self::assertSame(
            [['value' => 'normal'], ['value' => 'power_saving'], ['value' => 'emergency'], ['value' => 'custom', 'fields' => [
                'intervalSeconds' => ['type' => 'integer', 'min' => 30],
                'gpsEnabled' => ['type' => 'boolean'],
            ]]],
            $config['options']['mode'] ?? null,
        );
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

    public function testFourPTouchFallSensitivityMetadataIncludesNativeLevelOptions(): void
    {
        $config = DeviceConfigurationCatalog::configForProtocol('four-p-touch', 'fallDownSensitivity');
        self::assertIsArray($config);
        self::assertSame('fallSensitivityLevels', $config['input'] ?? null);
        self::assertSame(range(1, 8), array_column($config['options']['sensitivity'] ?? [], 'value'));
        self::assertSame([6, 8], array_column($config['options']['levels'] ?? [], 'value'));
    }

    public function testPublicAlarmClockAliasMapsToGenericCapability(): void
    {
        self::assertSame('alarm_clock', CapabilityCatalog::mapConfigurationKey('alarm_clock'));

        self::assertSame('call_whitelist', CapabilityCatalog::mapConfigurationKey('call_whitelist'));
        self::assertSame('sos_contacts', CapabilityCatalog::mapConfigurationKey('SOSNumber'));
        self::assertSame('whitelist_enabled', CapabilityCatalog::mapConfigurationKey('rejectUnknownCalls'));

        $fourPTouch = DeviceConfigurationCatalog::configForProtocol('four-p-touch', 'alarmClock');
        self::assertIsArray($fourPTouch);
        self::assertSame('alarmClock', $fourPTouch['key'] ?? null);

        $wonlexAlarm = DeviceConfigurationCatalog::configForProtocol('wonlex-json', 'alarmClock');
        self::assertSame(10, $wonlexAlarm['limit'] ?? null);
    }

    public function testWonlexLocationIntervalBuildsJsonPayload(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'locationInterval', ['intervalSeconds' => 300]);
        self::assertSame('locationInterval', $payload['command']);

        $wire = DeviceCommandCatalog::buildDownlink('wonlex-json', '865028000000308', $payload['command'], $payload['payload']);
        $decoded = (new WonlexAdapter())->decodeIncoming($wire);

        self::assertIsArray($decoded);
        self::assertSame('locationInterval', $decoded['type'] ?? null);
        self::assertSame(300, $decoded['data']['intervalTime'] ?? null);
    }

    public function testWonlexScalarHealthRequestsUseOnlyDocumentedRequiredFields(): void
    {
        foreach (
            [
            'dnHeartRate',
            'dnBP',
            'dnBO',
            'dnTemperature',
            'dnBreathe',
            'dnRR',
            ] as $nativeType
        ) {
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

    public function testWonlexRequestedWaveformsUseExplicitShortSamplingParameters(): void
    {
        foreach (
            [
            'dnECG' => '500',
            'dnHRV' => '100',
            'dnPPG' => '200',
            ] as $nativeType => $frequency
        ) {
            $wire = DeviceCommandCatalog::buildDownlink(
                'wonlex-json',
                '868705080300697',
                $nativeType,
                [],
                ['ident' => 123456]
            );
            $decoded = (new WonlexAdapter())->decodeIncoming($wire);

            self::assertSame($frequency, $decoded['data']['frequency'] ?? null, $nativeType);
            self::assertSame(30, $decoded['data']['oneTime'] ?? null, $nativeType);
            self::assertMatchesRegularExpression(
                '/^\d{8}$/',
                (string)($decoded['data']['collectionLogo'] ?? ''),
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
        $ppg = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexPPGInterval', ['intervalMinutes' => 60]);
        self::assertSame('deviceMeasuringFrequency', $ppg['command']);
        self::assertSame(['configs' => ['upPPG' => ['interval' => '60']]], $ppg['payload']);

        $rr = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexRRInterval', ['intervalMinutes' => 15]);
        self::assertSame('deviceMeasuringFrequency', $rr['command']);
        self::assertSame(['configs' => ['upRR' => ['interval' => '15']]], $rr['payload']);

        $bloodPressure = DeviceConfigurationCatalog::commandPayload(
            'wonlex-json',
            'wonlexBPInterval',
            ['intervalMinutes' => 0]
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

    /** O HW20Pro não mostra o `msgNotice` nem lhe responde, e confirma os outros comandos. */
    public function testWonlexDoesNotOfferPushMessage(): void
    {
        self::assertNull(DeviceConfigurationCatalog::configForProtocol('wonlex-json', 'pushMessage'));
        self::assertNotContains('push_message', CapabilityCatalog::keysForProtocol('wonlex-json'));
        self::assertNotContains('push_message', SupplierCapabilityTemplate::keysForModel('Wonlex', 'HW20PRO', 'watch'));
    }

    /** O HW20Pro ignora o `dnMedicationPlan` documentado: não responde nem toca, e confirma os outros comandos. */
    public function testWonlexDoesNotOfferTheMedicationPlan(): void
    {
        self::assertNull(DeviceConfigurationCatalog::configForProtocol('wonlex-json', 'dnMedicationPlan'));
        self::assertNull(DeviceConfigurationCatalog::configForProtocol('wonlex-json', 'medication_reminders'));
        self::assertNull(DeviceConfigurationCatalog::configForProtocol('wonlex-json', 'weatherData'));
    }

    public function testWonlexStructuredDeviceConfigBuildsNestedPayloads(): void
    {
        $toggle = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexPPGBPTrend', ['enabled' => true]);
        self::assertSame('deviceConfig', $toggle['command']);
        self::assertSame(['configs' => ['PPGBPTrend' => ['switchState' => 1]]], $toggle['payload']);

        $steps = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexStepTarget', ['steps' => 7500]);
        self::assertSame('deviceConfig', $steps['command']);
        self::assertSame(['configs' => ['StepTarget' => ['steps' => 7500]]], $steps['payload']);
    }

    public function testWonlexAdultHealthPayloadsUseDocumentedWireKeys(): void
    {
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
        $customAlarm = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'alarmClock', [
            'items' => [[
                'label' => 'Audio test',
                'time' => '20:03',
                'enabled' => true,
                'recurrence' => ['kind' => 'custom', 'days' => [1, 3, 7]],
                'url' => $audioUrl,
            ]],
        ]);
        self::assertSame('1010001', $customAlarm['payload']['alarmClockList'][0]['week']);
        self::assertSame('Audio test', $customAlarm['payload']['alarmClockList'][0]['label']);
        self::assertSame($audioUrl, $customAlarm['payload']['alarmClockList'][0]['url']);

        $family = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'familyNumber', [
            'contacts' => [['name' => 'Care', 'phone' => '+351210000000', 'areaCode' => '351']],
        ]);
        self::assertSame('+351210000000', $family['payload']['familyNumbers'][0]['phone']);

        $sos = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'SOSNumber', [
            'numbers' => ['+351210000000'],
        ]);
        self::assertArrayHasKey('sosNumbers', $sos['payload']);
        self::assertSame('+351210000000', $sos['payload']['sosNumbers'][0]['phone']);
    }

    public function testWonlexAlarmClockRejectsValuesOutsideTheDocumentedContract(): void
    {
        $valid = [
            'label' => 'Medicine',
            'time' => '08:00',
            'enabled' => true,
            'recurrence' => ['kind' => 'daily'],
        ];

        self::assertSame(
            'alarmClockList must contain at most 10 items',
            DeviceConfigurationCatalog::validate('wonlex-json', 'alarm_clock', [
                'items' => array_fill(0, 11, $valid),
            ])
        );
        self::assertNull(DeviceConfigurationCatalog::validate('wonlex-json', 'alarm_clock', [
            'items' => [array_diff_key($valid, ['label' => true])],
        ]));
        self::assertSame(
            'alarm startTime must use 24-hour HH:MM format',
            DeviceConfigurationCatalog::validate('wonlex-json', 'alarm_clock', [
                'items' => [array_replace($valid, ['time' => '99:77'])],
            ])
        );
        self::assertSame(
            'alarm week must contain exactly 7 zero-or-one characters',
            DeviceConfigurationCatalog::validate('wonlex-json', 'alarm_clock', [
                'items' => [array_replace($valid, ['week' => 'abc'])],
            ])
        );
        self::assertSame(
            'recurrence once is not supported by Wonlex alarmClock',
            DeviceConfigurationCatalog::validate('wonlex-json', 'alarm_clock', [
                'items' => [array_replace($valid, ['recurrence' => ['kind' => 'once']])],
            ])
        );
        self::assertSame(
            'custom recurrence requires at least one day',
            DeviceConfigurationCatalog::validate('wonlex-json', 'alarm_clock', [
                'items' => [array_replace($valid, ['recurrence' => ['kind' => 'custom', 'days' => []]])],
            ])
        );
    }

    public function testWonlexAlarmRejectsNonHttpAudioUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('alarm url must be a valid HTTP or HTTPS URL');

        DeviceConfigurationCatalog::commandPayload('wonlex-json', 'alarmClock', [
            'items' => [[
                'label' => 'Audio test',
                'time' => '20:04',
                'enabled' => true,
                'recurrence' => ['kind' => 'daily'],
                'url' => 'file:///tmp/alarm.mp3',
            ]],
        ]);
    }

    public function testWonlexSleepAndThresholdConfigsBuildStructuredPayloads(): void
    {
        $sleep = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexSleepIntervalOrSwitch', [
            'enabled' => true,
            'startTime' => '22:00',
            'endTime' => '10:00',
            'targetMinutes' => 480,
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
            'enabled' => true,
            'thresholdPercent' => 90,
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
            'enabled' => true,
            'thresholdBpm' => 120,
            'exerciseEnabled' => true,
            'exerciseMinBpm' => 100,
            'exerciseMaxBpm' => 140,
            'exerciseThresholdBpm' => 140,
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
            'enabled' => true,
            'systolicMmHg' => 135,
            'diastolicMmHg' => 90,
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

    /** @return iterable<string, array{string, array<string, mixed>, string, string}> */
    public static function wonlexOptionalAlertFields(): iterable
    {
        $heartRate = ['enabled' => true, 'thresholdBpm' => 50, 'exerciseEnabled' => true, 'exerciseMinBpm' => 100, 'exerciseMaxBpm' => 140, 'exerciseThresholdBpm' => 140];
        $heartRateWire = ['enabled' => 'switchState', 'thresholdBpm' => 'remindValue', 'exerciseEnabled' => 'exerciseSwitchState', 'exerciseMinBpm' => 'exerciseHRMin', 'exerciseMaxBpm' => 'exerciseHRMax', 'exerciseThresholdBpm' => 'exerciseRemindValue'];
        foreach (['wonlexHeartRateHighRemind' => 'HROvertopRemind', 'wonlexHeartRateLowRemind' => 'HeartRateBelowRemind'] as $key => $config) {
            foreach ($heartRateWire as $field => $wire) {
                yield "{$key} without {$field}" => [$key, array_diff_key($heartRate, [$field => true]), $config, $wire];
            }
        }
        yield 'blood pressure without systolic' => ['wonlexBPEarlyWarning', ['enabled' => true, 'diastolicMmHg' => 90], 'BPEarlyWarning', 'hpWarn'];
        yield 'blood pressure without diastolic' => ['wonlexBPEarlyWarning', ['enabled' => true, 'systolicMmHg' => 135], 'BPEarlyWarning', 'LPWarn'];
        yield 'blood oxygen without threshold' => ['wonlexBloodOxygenWarn', ['enabled' => true], 'bloodOxygenWarn', 'reminderValue'];
        yield 'high temperature without threshold' => ['wonlexTemperatureExceedRemind', ['enabled' => true], 'TemperatureExceedRemind', 'RemindValue'];
        yield 'low temperature without threshold' => ['wonlexTemperatureBelowRemind', ['enabled' => true], 'TemperatureBelowRemind', 'RemindValue'];
        yield 'sleep without start time' => ['wonlexSleepIntervalOrSwitch', ['enabled' => true, 'endTime' => '07:00', 'targetMinutes' => 480], 'SleepIntervalOrSwitch', 'sleepStartTime'];
        yield 'sleep without end time' => ['wonlexSleepIntervalOrSwitch', ['enabled' => true, 'startTime' => '22:00', 'targetMinutes' => 480], 'SleepIntervalOrSwitch', 'sleepEndTime'];
    }

    /**
     * A spec marca estes campos com `[*]`, opcionais: o que falta não vai no `configs`.
     *
     * @param array<string, mixed> $value
     */
    #[DataProvider('wonlexOptionalAlertFields')]
    public function testWonlexOptionalAlertFieldIsLeftOutWhenMissing(string $key, array $value, string $config, string $wire): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('wonlex-json', $key, $value);

        self::assertArrayNotHasKey($wire, $payload['payload']['configs'][$config]);
        self::assertCount(count($value), $payload['payload']['configs'][$config]);
    }

    public function testWonlexOptionalSleepTimeIsStillValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexSleepIntervalOrSwitch', ['enabled' => true, 'startTime' => '25:00', 'targetMinutes' => 480]);
    }

    public function testWonlexOptionalAlertFieldIsStillRangeChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexHeartRateLowRemind', ['enabled' => true, 'thresholdBpm' => -1]);
    }

    public function testWonlexTemperatureAndBatteryThresholdsBuildTheirWireNames(): void
    {
        self::assertSame(
            ['configs' => ['TemperatureExceedRemind' => ['switchState' => 1, 'RemindValue' => 38.5]]],
            DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexTemperatureExceedRemind', ['enabled' => true, 'thresholdCelsius' => 38.5])['payload'],
        );
        self::assertSame(
            ['configs' => ['LowPower' => ['Battery' => 20]]],
            DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexLowPower', ['thresholdPercent' => 20])['payload'],
        );
    }

    /** O 4P Touch recebe o perfil e o idioma em números; a API fala por nomes. */
    public function testFourPTouchSoundProfileAndLanguageAreEnums(): void
    {
        self::assertSame(['fields' => ['3']], DeviceConfigurationCatalog::commandPayload('four-p-touch', 'profile', ['mode' => 'vibrate'])['payload']);
        self::assertSame(
            ['fields' => ['3', '1']],
            DeviceConfigurationCatalog::commandPayload('four-p-touch', 'languageTimezone', ['language' => 'pt', 'utcOffsetHours' => 1])['payload'],
        );
        self::assertSame(
            ['fields' => ['14', '-3.5']],
            DeviceConfigurationCatalog::commandPayload('four-p-touch', 'languageTimezone', ['language' => 'zh-Hant', 'utcOffsetHours' => -3.5])['payload'],
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

    public function testFourPTouchSosContactsGoOutInASingleFrame(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sosContacts', [
            'numbers' => ['111111111', '222222222'],
        ]);

        self::assertSame('SOS', $payload['command']);
        self::assertSame(['fields' => ['111111111', '222222222', '']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);

        self::assertSame('[3G*8800000015*0018*SOS,111111111,222222222,]', $wire);
    }

    public function testFourPTouchTakePillsBuildsVoiceReminderFields(): void
    {
        if (!FourPTouchPayloadBuilder::supportsVoiceTranscoding()) {
            self::markTestSkipped('ffmpeg with AMR-NB support is not available');
        }
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'takePills', [
            'reminderSettings' => [
                'time' => '11:25',
                'enabled' => true,
                'frequency' => 2,
                'custom' => '',
            ],
            'number' => 1,
            'reminderText' => 'meds',
            'voiceData' => WavFixture::silenceBase64(),
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
        self::assertSame($encodedAudio, substr($wire, -strlen($encodedAudio) - 1, -1));
    }

    public function testFourPTouchTakePillsStripsLegacyDataUrlsFromVoiceData(): void
    {
        if (!FourPTouchPayloadBuilder::supportsVoiceTranscoding()) {
            self::markTestSkipped('ffmpeg with AMR-NB support is not available');
        }
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'takePills', [
            'reminderSettings' => [
                'time' => '11:25',
                'enabled' => true,
                'frequency' => 2,
                'custom' => '',
            ],
            'number' => 1,
            'reminderText' => 'meds',
            'voiceData' => 'data:audio/wav;base64,' . WavFixture::silenceBase64(),
            'voiceMimeType' => 'audio/wav',
        ]);

        self::assertStringStartsWith("#!AMR\n", $payload['payload']['fields'][3] ?? '');
    }

    public function testFourPTouchTakePillsEscapesReservedVoiceBytes(): void
    {
        $escape = \Closure::bind(
            static fn(string $bytes): string => FourPTouchPayloadBuilder::escapeVoiceBinary($bytes),
            null,
            FourPTouchPayloadBuilder::class,
        );

        self::assertNotNull($escape);
        self::assertSame(
            "A\x7D\x01\x7D\x02\x7D\x03\x7D\x04\x7D\x05Z",
            $escape("A\x7D[],*Z")
        );
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

    public function testFourPTouchTakePillsRejectsVoiceAudioWhenFfmpegIsUnavailable(): void
    {
        $originalPath = getenv('PATH');
        putenv('PATH=/path-without-ffmpeg');
        try {
            $error = DeviceConfigurationCatalog::validate('four-p-touch', 'takePills', [
                'reminderSettings' => [[
                    'time' => '11:25',
                    'enabled' => true,
                    'frequency' => 2,
                    'custom' => '',
                ]],
                'reminderText' => 'meds',
                'voiceData' => WavFixture::silenceBase64(),
                'voiceMimeType' => 'audio/wav',
            ]);
        } finally {
            $originalPath === false ? putenv('PATH') : putenv("PATH={$originalPath}");
        }

        self::assertSame('ffmpeg with AMR-NB support is required for TAKEPILLS voice audio', $error);
    }

    public function testFourPTouchTakePillsRejectsInvalidReminderSettings(): void
    {
        foreach (
            [
            ['time' => '25:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
            ['time' => '11:25', 'enabled' => true, 'frequency' => 4, 'custom' => ''],
            ['time' => '11:25', 'enabled' => true, 'frequency' => 3, 'custom' => '1010'],
            ] as $settings
        ) {
            $error = DeviceConfigurationCatalog::validate('four-p-touch', 'takePills', [
                'reminderSettings' => [$settings],
                'reminderText' => 'meds',
            ]);
            self::assertNotNull($error);
        }

        self::assertNotNull(DeviceConfigurationCatalog::validate('four-p-touch', 'takePills', [
            'reminderSettings' => ['11:25-1-9'],
            'reminderText' => 'meds',
        ]));
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
        self::assertArrayNotHasKey('options', $config);
        self::assertSame('medication_reminders', CapabilityCatalog::mapConfigurationKey('takePills'));
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
        yield 'restart device' => ['restart_device', 'restartCommand'];
        yield 'language timezone' => ['language_timezone', 'languageTimezone'];
        yield 'whitelist enabled' => ['whitelist_enabled', 'rejectUnknownCalls'];
        yield 'sound profile' => ['sound_profile', 'profile'];
        yield 'do not disturb' => ['do_not_disturb', 'doNotDisturb'];
    }

    #[DataProvider('fourPTouchConfigAliasProvider')]
    public function testFourPTouchPublicAliasesResolveToNativeConfigKeys(string $publicKey, string $nativeKey): void
    {
        $config = DeviceConfigurationCatalog::configForProtocol('four-p-touch', $publicKey);

        self::assertIsArray($config);
        self::assertSame($nativeKey, $config['key'] ?? null);
    }

    /** A chave pública de cada protocolo resolve-se pela mesma tabela que traduz a nativa. */
    #[DataProvider('publicKeyProvider')]
    public function testPublicKeysResolveToTheDefinitionThatDeclaresThem(string $protocol, string $publicKey, string $nativeKey): void
    {
        self::assertSame($nativeKey, DeviceConfigurationCatalog::nativeKey($protocol, $publicKey));
        self::assertSame($publicKey, CapabilityCatalog::mapConfigurationKey($nativeKey));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function publicKeyProvider(): iterable
    {
        yield 'wonlex phonebook' => ['wonlex-json', 'phonebook', 'familyNumber'];
        yield 'wonlex fall detection' => ['wonlex-json', 'fall_detection', 'wonlexFallWarnSwitch'];
        yield 'vivistar push message' => ['vivistar-iw', 'push_message', 'pushMessage'];
        yield 'vivistar alarm clock' => ['vivistar-iw', 'alarm_clock', 'reminders'];
        yield 'four-p-touch sos contacts' => ['four-p-touch', 'sos_contacts', 'sosContacts'];
        yield 'dispenser identity' => ['zayata-m228', 'medication_reminders', 'medication_reminders'];
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

    public function testFourPTouchDoNotDisturbSendsUpToFourTimeRanges(): void
    {
        $config = DeviceConfigurationCatalog::configForProtocol('four-p-touch', 'doNotDisturb');
        self::assertSame('timeRanges', $config['input'] ?? null);
        self::assertSame(4, $config['limit'] ?? null);

        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'doNotDisturb', [
            'ranges' => ['21:10-07:30', '', '12:00-13:00', '14:00-15:00', '16:00-17:00'],
        ]);

        self::assertSame('SILENCETIME', $payload['command']);
        self::assertSame(['fields' => ['21:10-07:30', '12:00-13:00', '14:00-15:00', '16:00-17:00']], $payload['payload']);
    }

    /** O campo impõe os mínimos da spec: 60 s no UPLOAD e 5 minutos no HEALTHAUTOSET. */
    public function testFourPTouchIntervalFieldsDeclareTheProtocolMinimums(): void
    {
        self::assertSame(60, DeviceConfigurationCatalog::configForProtocol('four-p-touch', 'uploadInterval')['options']['min'] ?? null);
        self::assertSame(5, DeviceConfigurationCatalog::configForProtocol('four-p-touch', 'healthAutoMeasurement')['options']['min'] ?? null);
    }

    public function testFourPTouchRejectUnknownCallsIsAPlainToggle(): void
    {
        self::assertSame('toggle', DeviceConfigurationCatalog::configForProtocol('four-p-touch', 'rejectUnknownCalls')['input'] ?? null);
    }

    public function testFourPTouchCommandsExposeSplitHealthRequests(): void
    {
        $replies = array_column(DeviceCommandCatalog::commandsForProtocol('four-p-touch'), 'expectedReplyTypes', 'feature');

        self::assertEqualsCanonicalizing(
            ['location', 'heart_rate', 'blood_pressure', 'temperature', 'firmware_version', 'device_status'],
            array_keys($replies)
        );
        self::assertContains('bphrt', $replies['heart_rate']);
        self::assertContains('bphrt', $replies['blood_pressure']);
        self::assertContains('btemp2', $replies['temperature']);
    }

    public function testFourPTouchTakePillsHandlesMultipleReminders(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'takePills', [
            'reminderSettings' => [
                ['time' => '11:25', 'enabled' => true, 'frequency' => 2, 'custom' => ''],
                ['time' => '14:30', 'enabled' => false, 'frequency' => 1, 'custom' => ''],
                ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '1010101'],
            ],
            'number' => 3,
            'reminderText' => 'meds',
            'voiceData' => '',
        ]);

        self::assertSame('TAKEPILLS', $payload['command']);
        self::assertSame(
            ['11:25-1-2-14:30-0-1-18:00-1-3-1010101', '3', '006D006500640073', ''],
            $payload['payload']['fields'] ?? [],
        );

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('TAKEPILLS,11:25-1-2-14:30-0-1-18:00-1-3-1010101,3,006D006500640073,]', $wire);
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

    public function testFourPTouchSosContactsFillTheThreeSlots(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sosContacts', [
            'numbers' => ['123456789', '987654321', '555555555'],
        ]);

        self::assertSame('SOS', $payload['command']);
        self::assertSame(['fields' => ['123456789', '987654321', '555555555']], $payload['payload']);
        self::assertSame(
            ['SOS'],
            DeviceConfigurationCatalog::configForProtocol('four-p-touch', 'sosContacts')['expectedReplyTypes'] ?? null
        );

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertSame('[3G*8800000015*0021*SOS,123456789,987654321,555555555]', $wire);
    }

    public function testFourPTouchSosContactsClearEverySlotWhenTheListIsEmpty(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sosContacts', ['numbers' => []]);

        self::assertSame('SOS', $payload['command']);
        self::assertSame(['fields' => ['', '', '']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertSame('[3G*8800000015*0006*SOS,,,]', $wire);
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function singleFrameProvider(): iterable
    {
        yield 'monitor number' => ['monitorNumber', ['phone' => '13100010002'], '[3G*8800000015*0013*MONITOR,13100010002]'];
        yield 'sleep time' => ['sleepTime', ['range' => '21:10-07:30'], '[3G*8800000015*0015*SLEEPTIME,21:10-07:30]'];
        yield 'make call' => ['makeCall', ['phone' => '00000000000'], '[3G*8800000015*0010*CALL,00000000000]'];
        yield 'center number' => ['centerNumber', ['phone' => '00000000000'], '[3G*8800000015*0012*CENTER,00000000000]'];
        yield 'restart' => ['restartCommand', [], '[3G*8800000015*0005*RESET]'];
        yield 'power off' => ['powerOffCommand', [], '[3G*8800000015*0008*POWEROFF]'];
        yield 'find device' => ['findDeviceCommand', [], '[3G*8800000015*0004*FIND]'];
    }

    /** @param array<string, mixed> $desired */
    #[DataProvider('singleFrameProvider')]
    public function testFourPTouchSingleFrameConfigurationsBuildTheirWire(string $key, array $desired, string $wire): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', $key, $desired);

        self::assertSame(
            $wire,
            DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload'])
        );
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

    public function testFourPTouchPushMessageBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'pushMessage', ['message' => 'hello']);
        self::assertSame('MESSAGE', $payload['command']);
        self::assertSame('00680065006c006c006f', strtolower($payload['payload']['fields'][0] ?? ''));

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertStringContainsString('MESSAGE,00680065006C006C006F', $wire);
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

    public function testFourPTouchAlarmClockMapsGenericMondayAndSundayToNativeMask(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'alarmClock', [
            'alarms' => [[
                'time' => '08:10',
                'enabled' => true,
                'recurrence' => ['kind' => 'custom', 'days' => [1, 7]],
            ]],
        ]);

        self::assertSame(['fields' => ['08:10-1-3-1100000']], $payload['payload']);
    }

    public function testFourPTouchPhonebookBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'phonebook', [
            'contacts' => [['phone' => '123456789', 'name' => 'Ana']],
        ]);
        self::assertSame('PHBX2', $payload['command']);
        self::assertSame(['fields' => ['1', '0041006E0061', '123456789']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertSame('[3G*8800000015*001E*PHBX2,1,0041006E0061,123456789]', $wire);
    }

    public function testFourPTouchPhonebookClearingRemovesEveryStoredIndex(): void
    {
        $payloads = DeviceConfigurationCatalog::commandPayloads(
            'four-p-touch',
            'phonebook',
            ['contacts' => []],
            ['previousContacts' => [
                1 => ['name' => 'Ana', 'phone' => '123456789'],
                2 => ['name' => 'Rui', 'phone' => '987654321'],
            ]],
        );

        self::assertSame(
            [
                ['command' => 'DPHBX', 'payload' => ['fields' => ['1']]],
                ['command' => 'DPHBX', 'payload' => ['fields' => ['2']]],
            ],
            $payloads,
            'limpar a lista remove cada índice guardado, um comando por contacto'
        );
    }

    public function testFourPTouchPhonebookWithNothingToChangeEmitsNoCommand(): void
    {
        self::assertSame(
            [],
            DeviceConfigurationCatalog::commandPayloads('four-p-touch', 'phonebook', ['contacts' => []]),
            'uma lista vazia num aparelho sem contactos não tem nada para entregar'
        );
    }

    public function testFourPTouchPhonebookResyncSweepsTheDeviceBeforeWriting(): void
    {
        $hugo = ['name' => 'Hugo', 'phone' => '+351938854803'];

        $payloads = DeviceConfigurationCatalog::commandPayloads(
            'four-p-touch',
            'phonebook',
            ['contacts' => [$hugo]],
            ['previousContacts' => [1 => $hugo], 'resync' => true],
        );

        $commands = array_column($payloads, 'command');
        self::assertSame('DPHBX', $commands[0], 'a reparação varre antes de escrever');
        self::assertCount(
            100,
            $payloads,
            'sem o resync este pedido não daria comando nenhum, porque nada mudou'
        );
        self::assertSame(
            ['command' => 'PHBX2', 'payload' => ['fields' => ['1', '004800750067006F', '+351938854803']]],
            $payloads[99]
        );
    }

    public function testFourPTouchSoundProfileBuildsNativeFields(): void
    {
        $silent = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'sound_profile', ['mode' => 'silent']);
        self::assertSame('profile', $silent['command']);
        self::assertSame(['fields' => ['4']], $silent['payload']);
    }

    public function testFourPTouchSingleNumberCommandsRejectAnEmptyPhone(): void
    {
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

    /** @return iterable<string, array{string, string, string}> */
    public static function nativeToggleProvider(): iterable
    {
        yield 'sos sms alerts' => ['four-p-touch', 'sosSmsAlerts', 'SOSSMS'];
        yield 'low battery sms alerts' => ['four-p-touch', 'lowBatterySmsAlerts', 'LOWBAT'];
        yield 'remove watch alarm' => ['four-p-touch', 'removeWatchAlarm', 'REMOVE'];
        yield 'remove watch sms alerts' => ['four-p-touch', 'removeWatchSmsAlerts', 'REMOVESMS'];
        yield 'reject unknown calls' => ['four-p-touch', 'rejectUnknownCalls', 'DEVREFUSEPHONESWITCH'];
    }

    /**
     * Um interruptor é o comando mais um `1` ou `0`; só o nome nativo distingue estes, a forma é a mesma.
     */
    #[DataProvider('nativeToggleProvider')]
    public function testATogglePutsItsOwnCommandOnTheWireWithOneOrZero(
        string $protocol,
        string $key,
        string $command,
    ): void {
        $on = DeviceConfigurationCatalog::commandPayload($protocol, $key, ['enabled' => true]);
        self::assertSame($command, $on['command']);
        self::assertSame(['fields' => ['1']], $on['payload']);

        $off = DeviceConfigurationCatalog::commandPayload($protocol, $key, ['enabled' => false]);
        self::assertSame($command, $off['command']);
        self::assertSame(['fields' => ['0']], $off['payload']);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, string}> */
    public static function rejectedConfigurationProvider(): iterable
    {
        yield 'vivistar push message without text' => [
            'vivistar-iw', 'pushMessage', ['message' => ''],
            'message is required',
        ];
        yield 'vivistar alarm without a type' => [
            'vivistar-iw', 'alarm_clock', ['items' => [[
                'time' => '09:00',
                'enabled' => true,
                'recurrence' => ['kind' => 'custom', 'days' => [1]],
            ]]],
            'type is required',
        ];
        yield 'vivistar working mode below the interval floor' => [
            'vivistar-iw', 'workingMode', ['mode' => 'custom', 'intervalSeconds' => 10, 'gpsEnabled' => true],
            'intervalSeconds must be at least 30 for the custom mode',
        ];
        yield 'vivistar working mode by its native number' => [
            'vivistar-iw', 'workingMode', ['mode' => 1],
            'mode must be one of normal, power_saving, emergency, custom',
        ];
        // O contrário do caso da Vivistar acima: aqui o tipo é que não é suportado.
        yield '4P Touch alarm with a type' => [
            'four-p-touch', 'alarm_clock', ['alarms' => [[
                'time' => '09:00',
                'enabled' => true,
                'type' => 1,
                'recurrence' => ['kind' => 'once'],
            ]]],
            'type is not supported for four-p-touch alarm_clock',
        ];
        yield '4P Touch upload interval below the protocol minimum' => [
            'four-p-touch', 'uploadInterval', ['intervalSeconds' => 59],
            'intervalSeconds must be an integer between 60 and 65535',
        ];
        yield '4P Touch health measurement below five minutes' => [
            'four-p-touch', 'healthAutoMeasurement', ['enabled' => true, 'intervalMinutes' => 4],
            'intervalMinutes must be at least 5',
        ];
        yield '4P Touch sos contact given an array' => [
            'four-p-touch', 'sosContacts', ['numbers' => [['123456789']]],
            'numbers items must be strings',
        ];
        yield '4P Touch push message without text' => [
            'four-p-touch', 'pushMessage', ['message' => ''],
            'message is required',
        ];
        yield '4P Touch alarm with a six-digit custom mask' => [
            'four-p-touch', 'alarmClock', ['alarms' => [
                ['time' => '08:10', 'enabled' => true, 'frequency' => 3, 'custom' => '111110'],
            ]],
            'alarm custom days must be a 7-digit 0/1 mask',
        ];
        yield '4P Touch with a fourth alarm' => [
            'four-p-touch', 'alarmClock', ['alarms' => [
                ['time' => '08:10', 'enabled' => true, 'frequency' => 1],
                ['time' => '14:30', 'enabled' => false, 'frequency' => 2],
                ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '0111110'],
                ['time' => '20:00', 'enabled' => true, 'frequency' => 1],
            ]],
            'alarms must not contain more than 3 items',
        ];
        yield '4P Touch past the hundredth contact' => [
            'four-p-touch', 'phonebook', ['contacts' => array_map(
                static fn(int $i): array => ['phone' => (string)(910000000 + $i), 'name' => "C{$i}"],
                range(1, 101)
            )],
            'contacts must not exceed 100 items',
        ];
        yield '4P Touch contact phone over twenty characters' => [
            'four-p-touch', 'phonebook', ['contacts' => [
                ['phone' => '123456789012345678901', 'name' => 'Ana'],
            ]],
            'phone must not exceed 20 ASCII characters',
        ];
        yield '4P Touch contact phone outside ASCII' => [
            'four-p-touch', 'phonebook', ['contacts' => [
                ['phone' => '+3519☃', 'name' => 'Ana'],
            ]],
            'phone must contain ASCII characters only',
        ];
        yield '4P Touch sound profile outside the vendor range' => [
            'four-p-touch', 'sound_profile', ['mode' => 4],
            'mode must be one of ring_and_vibrate, ring, vibrate, silent',
        ];
    }

    /**
     * A mensagem da validação é o que chega ao utilizador, por isso afirma-se ela e não só a recusa.
     *
     * @param array<string, mixed> $desired
     */
    #[DataProvider('rejectedConfigurationProvider')]
    public function testValidationRefusesTheConfigurationAndSaysWhy(
        string $protocol,
        string $key,
        array $desired,
        string $message,
    ): void {
        self::assertSame($message, DeviceConfigurationCatalog::validate($protocol, $key, $desired));
    }

    /** Uma entrada transiente é uma acção: a `/requests` aceita-a e a `PATCH` das configurações recusa-a. */
    public function testTransientEntriesAreAnnouncedAsRequestsAndTheRestAsConfig(): void
    {
        $transientSeen = 0;
        foreach (['four-p-touch', 'vivistar-iw', 'wonlex-json'] as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
                $transient = ($entry['transient'] ?? false) === true;
                $transientSeen += $transient ? 1 : 0;
                self::assertSame(
                    $transient ? 'request' : 'config',
                    (string)($entry['kind'] ?? ''),
                    $protocol . '/' . (string)($entry['key'] ?? '')
                );
            }
        }

        self::assertGreaterThan(0, $transientSeen, 'sem entradas transientes o teste não afirma nada');
    }
}
