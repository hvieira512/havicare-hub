<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\FourPTouch\FourPTouchGenericHandler;
use Hub\Domain\Capability\CapabilityCatalog;
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

        foreach (['watch.medication_reminders', 'pill_dispenser.medication_reminders', 'pill_dispenser.medication_period', 'pill_dispenser.alarm_volume', 'pill_dispenser.alarm_ringtone', 'pill_dispenser.mute_alarm'] as $key) {
            self::assertSame('reminders', $sections[$key] ?? null, $key);
        }
        foreach (['watch.alarm_clock', 'watch.fall_detection', 'watch.low_battery_alert', 'watch.help_call', 'pill_dispenser.emergency_call', 'pill_dispenser.help_call'] as $key) {
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

    public function testFourPTouchAliasesAreResolvedByTheDedicatedHelper(): void
    {
        self::assertSame('sos_contacts', FourPTouchGenericHandler::nativeKeyToGenericKey('sosContacts'));
        self::assertSame('call_whitelist', FourPTouchGenericHandler::nativeKeyToGenericKey('whitelistGroup1'));
        self::assertSame('whitelist_enabled', FourPTouchGenericHandler::nativeKeyToGenericKey('rejectUnknownCalls'));
        self::assertSame('whitelist_enabled', FourPTouchGenericHandler::nativeKeyToGenericKey('whitelistSwitch'));
        self::assertSame('alarm_clock', FourPTouchGenericHandler::nativeKeyToGenericKey('alarmClock'));
        self::assertSame('alarmClock', FourPTouchGenericHandler::publicKeyToNativeKey('alarm_clock'));
        self::assertSame('uploadInterval', FourPTouchGenericHandler::publicKeyToNativeKey('location_reporting_interval'));
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
}
