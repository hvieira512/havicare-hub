<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\FourPTouch\FourPTouchGenericHandler;
use PHPUnit\Framework\TestCase;

final class CapabilityCatalogTest extends TestCase
{
    /** Despertador, plano de medicação e toque do lembrete vivem em «Lembretes»; «Alarmes e alertas» fica com o que dispara. */
    public function testRemindersLiveApartFromAlarms(): void
    {
        $sections = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            $sections[$definition['deviceType'] . '.' . $definition['key']] = $definition['section'];
        }

        foreach (['watch.alarm_clock', 'watch.medication_reminders', 'pill_dispenser.medication_reminders', 'pill_dispenser.medication_period', 'pill_dispenser.alarm_volume', 'pill_dispenser.alarm_ringtone', 'pill_dispenser.mute_alarm'] as $key) {
            self::assertSame('reminders', $sections[$key] ?? null, $key);
        }
        foreach (['watch.fall_detection', 'watch.low_battery_alert', 'watch.help_call', 'pill_dispenser.emergency_call', 'pill_dispenser.help_call'] as $key) {
            self::assertSame('alarms', $sections[$key] ?? null, $key);
        }
    }

    public function testDefinitionsHaveUniqueKeysAndRequiredMetadata(): void
    {
        foreach (CapabilityCatalog::deviceTypes() as $deviceType) {
            $definitions = CapabilityCatalog::definitionsForDeviceType($deviceType);
            $keys = array_column($definitions, 'key');

            self::assertSame($keys, array_values(array_unique($keys)), $deviceType);

            foreach ($definitions as $definition) {
                self::assertContains($definition['section'], CapabilityCatalog::sections());
                self::assertNotSame('', trim($definition['key']));
            }
        }
    }

    public function testWonlexControlsAreRequestableActionsAndWeatherIsNotAdvertised(): void
    {
        $definitions = [];
        foreach (CapabilityCatalog::definitionsForDeviceType('watch') as $definition) {
            $definitions[$definition['key']] = $definition;
        }

        foreach (['reset_device', 'restart_device', 'power_off', 'find_device', 'push_message', 'make_call'] as $key) {
            self::assertFalse($definitions[$key]['isConfigurable']);
            self::assertTrue($definitions[$key]['isRequestable']);
        }
        self::assertArrayNotHasKey('weather_data', $definitions);
    }

    public function testMonitorNumberIsARequestableActionAndNotAContactSetting(): void
    {
        $definitions = [];
        foreach (CapabilityCatalog::definitionsForDeviceType('watch') as $definition) {
            $definitions[$definition['key']] = $definition;
        }

        // O relógio liga para o número no instante em que recebe o MONITOR, tal como no CALL.
        self::assertFalse($definitions['monitor_number']['isConfigurable']);
        self::assertTrue($definitions['monitor_number']['isRequestable']);
        self::assertSame('settings_system', $definitions['monitor_number']['section']);
    }

    public function testWonlexSystemReportsAreNotAdvertisedAsTelemetry(): void
    {
        $definitions = [];
        foreach (CapabilityCatalog::definitionsForDeviceType('watch') as $definition) {
            $definitions[$definition['key']] = $definition;
        }

        foreach (['call_log', 'sms', 'ecg_analysis'] as $key) {
            self::assertArrayNotHasKey($key, $definitions);
        }

        self::assertSame('settings_system', $definitions['device_state']['section']);
        self::assertFalse($definitions['device_state']['isTelemetry']);
        self::assertTrue($definitions['device_state']['isEvent']);
        self::assertContains('device_state', CapabilityCatalog::keysForProtocol('wonlex-json'));
        self::assertNotContains('device_state', CapabilityCatalog::telemetryKeysForProtocol('wonlex-json'));
    }

    public function testWatchTaxonomyExcludesInternalSynchronizationAndGroupsCallRulesWithContacts(): void
    {
        $definitions = [];
        foreach (CapabilityCatalog::definitionsForDeviceType('watch') as $definition) {
            $definitions[$definition['key']] = $definition;
        }

        self::assertArrayNotHasKey('device_binding', $definitions);
        self::assertArrayNotHasKey('device_settings_sync', $definitions);
        self::assertArrayNotHasKey('call_in_restriction', $definitions);
        self::assertSame('contacts', $definitions['whitelist_enabled']['section']);

        self::assertNull(CapabilityCatalog::mapConfigurationKey('deviceConfig'));
    }

    /** Cada chave que o mapeamento aceita, com o nome genérico em que cai. */
    public function testConfigurationKeyMappingIsFrozen(): void
    {
        $expected = [
            'familyNumber' => 'phonebook',
            'SOSNumber' => 'sos_contacts',
            'wonlexLowPower' => 'low_battery_alert',
            'wonlexFallWarnSwitch' => 'fall_detection',
            'fallDetection' => 'fall_detection',
            'wonlexSOSSwitch' => 'sos_sms_alert',
            'wonlexBloodOxygenWarn' => 'blood_oxygen_alert',
            'wonlexTemperatureExceedRemind' => 'temperature_high_alert',
            'wonlexTemperatureBelowRemind' => 'temperature_low_alert',
            'wonlexBPEarlyWarning' => 'blood_pressure_alert',
            'wonlexHeartRateHighRemind' => 'heart_rate_high_alert',
            'wonlexHeartRateLowRemind' => 'heart_rate_low_alert',
            'autoHealthMeasurement' => 'auto_vitals_interval',
            'fallSensitivity' => 'fall_sensitivity',
            'wonlexHeartRateInterval' => 'heart_rate_measurement_interval',
            'wonlexBPInterval' => 'blood_pressure_measurement_interval',
            'wonlexBOInterval' => 'blood_oxygen_measurement_interval',
            'wonlexBodyTemperatureInterval' => 'temperature_measurement_interval',
            'wonlexBreatheInterval' => 'breath_rate_measurement_interval',
            'wonlexECGInterval' => 'ecg_measurement_interval',
            'wonlexHRVInterval' => 'hrv_measurement_interval',
            'wonlexPPGInterval' => 'ppg_measurement_interval',
            'wonlexRRInterval' => 'rr_interval_measurement_interval',
            'wonlexContinuousHRSwitch' => 'heart_rate_continuous',
            'wonlexContinuousBOCheck' => 'blood_oxygen_continuous',
            'wonlexPPGBPTrend' => 'blood_pressure_trend',
            'wonlexContinuousTempSwitch' => 'temperature_continuous',
            'wonlexStepTarget' => 'step_goal',
            'wonlexSleepIntervalOrSwitch' => 'sleep_monitoring',
            'wonlexStepInterval' => 'step_reporting_interval',
            'locationInterval' => 'location_reporting_interval',
            'workingMode' => 'working_mode',
            'wonlexCallInLimitSwitch' => 'whitelist_enabled',
            'rejectUnknownCalls' => 'whitelist_enabled',
            'factoryResetCommand' => 'reset_device',
            'restartCommand' => 'restart_device',
            'powerOffCommand' => 'power_off',
            'findDeviceCommand' => 'find_device',
            'pushMessage' => 'push_message',
            ' familyNumber ' => 'phonebook',
            'unknownNativeKey' => null,
            '' => null,
        ];
        $identity = [
            'alarm_clock', 'phonebook', 'call_whitelist', 'whitelist_enabled', 'diaper_sensitivity', 'proximity_alert',
            'heart_rate_continuous', 'blood_pressure_trend', 'temperature_continuous', 'hrv_continuous',
            'blood_sugar_continuous', 'blood_lipids_continuous', 'stress_continuous', 'sleep_monitoring',
            'blood_oxygen_alert', 'blood_oxygen_window', 'heart_rate_alert', 'skin_tone', 'personal_info', 'find_device',
            'medication_reminders', 'medication_period', 'early_dispense', 'missed_dispense', 'child_lock',
            'emergency_call', 'date_format', 'time_format', 'auto_clock', 'key_tone', 'retrieval_warning',
            'retrieval_timeout', 'loaded_cells', 'alarm_volume', 'alarm_ringtone', 'do_not_disturb', 'device_language',
            'time_zone', 'sync_configuration', 'dispense_now', 'calibrate_clock', 'mute_alarm', 'reset_tray',
            'restart_device', 'heart_rate_measurement', 'impedance_measurement', 'weight_unit', 'select_scale_user',
            'scale_users', 'read_scale_history', 'read_blood_pressure_history', 'vital_sign_sensitivity', 'working_mode',
            'breath_rate_alert', 'weak_vital_signs_alarm', 'fall_alarm', 'sitting_on_floor_alarm', 'radar_installation',
            'detection_boundary', 'radar_areas', 'measure_boundary', 'read_device_state', 'reset_device',
        ];
        foreach ($identity as $key) {
            $expected[$key] = $key;
        }

        $actual = [];
        foreach (array_keys($expected) as $key) {
            $actual[$key] = CapabilityCatalog::mapConfigurationKey((string)$key);
        }

        self::assertSame($expected, $actual);
    }

    public function testFourPTouchAliasesAreResolvedByTheDefinitions(): void
    {
        self::assertSame('sos_contacts', CapabilityCatalog::mapConfigurationKey('sosContacts'));
        self::assertSame('call_whitelist', CapabilityCatalog::mapConfigurationKey('whitelistGroup1'));
        self::assertSame('whitelist_enabled', CapabilityCatalog::mapConfigurationKey('rejectUnknownCalls'));
        self::assertSame('alarm_clock', CapabilityCatalog::mapConfigurationKey('alarmClock'));
        self::assertSame('alarmClock', DeviceConfigurationCatalog::nativeKey('four-p-touch', 'alarm_clock'));
        self::assertSame('uploadInterval', DeviceConfigurationCatalog::nativeKey('four-p-touch', 'location_reporting_interval'));
        // Os dois grupos da lista branca não fazem uma chave nativa só.
        self::assertSame('call_whitelist', DeviceConfigurationCatalog::nativeKey('four-p-touch', 'call_whitelist'));
    }

    public function testFourPTouchDoNotDisturbCarriesTimeRanges(): void
    {
        self::assertSame(
            ['doNotDisturb' => ['ranges' => ['21:10-07:30']]],
            (new FourPTouchGenericHandler())->toNative('do_not_disturb', ['ranges' => ['21:10-07:30']])
        );
    }

    public function testFourPTouchFallbackCanRehydrateFallSensitivity(): void
    {
        $handler = new FourPTouchGenericHandler();

        self::assertSame(
            ['sensitivity' => 6, 'levels' => 8],
            $handler->fromNative('fall_sensitivity', 'fallDownSensitivity', [
                'sensitivityLevel' => 6,
                'totalLevels' => 8,
            ]),
        );
    }

    public function testFourPTouchFallbackDoesNotInventFirmwareScale(): void
    {
        $handler = new FourPTouchGenericHandler();

        self::assertSame(
            ['sensitivity' => 6],
            $handler->fromNative('fall_sensitivity', 'fallDownSensitivity', [
                'sensitivityLevel' => 6,
            ]),
        );
    }

    /** O `TS` e o `VERNO` da 4P Touch publicam a versão; o `device_status` é só o pedido. */
    public function testOnlyTheFourPTouchPublishesTheWatchFirmwareVersion(): void
    {
        self::assertContains('firmware_version', CapabilityCatalog::telemetryKeysForProtocol('four-p-touch'));
        self::assertNotContains('device_status', CapabilityCatalog::telemetryKeysForProtocol('four-p-touch'));
        self::assertNotContains('firmware_version', CapabilityCatalog::telemetryKeysForProtocol('vivistar-iw'));
        self::assertNotContains('firmware_version', CapabilityCatalog::telemetryKeysForProtocol('wonlex-json'));
    }

    public function testTheFourPTouchHasNoConfigurationNamedAfterARequest(): void
    {
        self::assertNull(CapabilityCatalog::mapConfigurationKey('firmwareVersion'));
        self::assertNull(CapabilityCatalog::mapConfigurationKey('deviceStatus'));
    }
}
