<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\FourPTouch\FourPTouchGenericHandler;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

final class CapabilityCatalogTest extends TestCase
{
    public function testDefinitionsRemainStableAfterBeingSplitByDeviceType(): void
    {
        // A ordem dentro de cada tipo segue o agrupamento dos ficheiros de definições; o ecrã
        // reordena por secção e etiqueta.
        $expected = [
            // O `device_status` é o `TS` dos 4P Touch, e a `connectivity` chega na resposta a ele.
            'watch' => [75, '8116e8ab83dd1fc22267f63ca134fe77dcbb979cc6b8376d402e55192bb22af5'],
            'ncs' => [2, 'd09943de9daace8dd8dfbbe279d42028501bd114ce5cefe7f51ca6170f3ab1b9'],
            'radar' => [15, '568b9f853799c52eea961a7b40d6f002961b9e48989d0d9629611049a21ddd0e'],
            'gateway' => [4, '4570cd67448bac0d35f327326ab0221e3a5d64534310ccb7b0eb479d20ec3028'],
            'diaper_sensor' => [8, '42c78c7d9722820650efd37ba508ce95f223fb31651e05aa8cd008fce660ec4e'],
            // As W6/W6B só anunciam bateria, movimento, proximidade e botão; o resto é da Veepoo MF91.
            'bracelet' => [42, 'd0a3561ab20dda3fe89663b815399e286998454aed9fc3de9f195c8515738d14'],
            // Ficam de fora a reposição de fábrica, desligar a cifra e mudar o servidor, que nos podem
            // tirar o aparelho.
            'pill_dispenser' => [38, '9c02bd16aee9914055c934fcf1e8d3209be4eed55fb44de9f63f47b5c1c6eec7'],
        ];

        // Um tipo de dispositivo sem hash aqui fica sem guarda.
        self::assertSame(CapabilityCatalog::deviceTypes(), array_keys($expected));

        foreach ($expected as $deviceType => [$count, $hash]) {
            $definitions = CapabilityCatalog::definitionsForDeviceType($deviceType);

            self::assertCount($count, $definitions, $deviceType);
            self::assertSame(
                $hash,
                hash('sha256', json_encode($definitions, JSON_UNESCAPED_UNICODE)),
                $deviceType,
            );
        }
    }

    /**
     * Um despertador, o plano de medicação e o toque do lembrete não são alarmes de perigo: vivem
     * em «Lembretes», e «Alarmes e alertas» fica com o que dispara e com os seus interruptores.
     */
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
        self::assertSame([
            'telemetry' => 'Telemetria',
            'health' => 'Saúde',
            'contacts' => 'Contactos',
            'alarms' => 'Alarmes e alertas',
            'reminders' => 'Lembretes',
            'settings_system' => 'Sistema',
        ], CapabilityCatalog::sections());

        foreach (CapabilityCatalog::deviceTypes() as $deviceType) {
            $definitions = CapabilityCatalog::definitionsForDeviceType($deviceType);
            $keys = array_column($definitions, 'key');

            self::assertSame($keys, array_values(array_unique($keys)), $deviceType);

            foreach ($definitions as $definition) {
                self::assertSame($deviceType, $definition['deviceType']);
                self::assertArrayHasKey($definition['section'], CapabilityCatalog::sections());
                self::assertNotSame('', trim($definition['key']));
                self::assertNotSame('', trim($definition['label']));
                self::assertIsBool($definition['isTelemetry']);
                self::assertIsBool($definition['isConfigurable']);
                self::assertIsBool($definition['isRequestable']);
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
        self::assertSame('Alerta de remoção do relógio', $definitions['remove_watch_alarm']['label']);
        self::assertSame('SMS de remoção do relógio', $definitions['remove_watch_sms_alert']['label']);

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

    /**
     * O `is_telemetry` da base é, na prática, `section = 'telemetry'`: o repositório filtra por
     * um e o ecrã lê o outro.
     */
    public function testTelemetryFlagAndSectionCannotDisagree(): void
    {
        $divergentes = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            $naSecção = $definition['section'] === 'telemetry';
            if ($naSecção !== $definition['isTelemetry']) {
                $divergentes[] = sprintf(
                    '%s:%s (section=%s, isTelemetry=%s)',
                    $definition['deviceType'],
                    $definition['key'],
                    $definition['section'],
                    $definition['isTelemetry'] ? 'true' : 'false',
                );
            }
        }

        self::assertSame([], $divergentes, 'isTelemetry tem de ser verdadeiro exactamente na secção telemetry');
    }

    /** Uma capacidade de telemetria não é configurável: são os dois lados do mesmo aparelho. */
    public function testTelemetryIsNeverConfigurable(): void
    {
        $configuráveis = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            if ($definition['isTelemetry'] && $definition['isConfigurable']) {
                $configuráveis[] = $definition['deviceType'] . ':' . $definition['key'];
            }
        }

        self::assertSame([], $configuráveis);
    }
}
