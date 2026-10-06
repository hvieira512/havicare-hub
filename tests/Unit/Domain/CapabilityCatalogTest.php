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
            'watch' => [69, 'f5f4233f0087097003e031ff68c2af49180dd334b20d5ae56f911e1f31abf408'],
            'ncs' => [1, '213f35a9295bacacfdaa5570451707a23ee59416ebc3ac1de062f1b6ca7685a4'],
            'radar' => [9, '45dfaa71313e4da275fca1da9536b826bf0fe6a442cf462d3d2534db1499fa65'],
            'gateway' => [3, '044f4b1de47b562638442dc3fc8be22b3ab76043721211a47f478ee68124a91f'],
            'diaper_sensor' => [7, '1aabeb619dd84c1e60cb25bc6d43fe88ea8b3b708365ad38f39a3c13bf5c4fd2'],
            // As W6/W6B só anunciam bateria, movimento, proximidade e botão; o resto é da Veepoo MF91.
            'bracelet' => [41, 'b79cd852c68f3900fef80612e135d2e46652e12ca8eb50a52fdd9bae8d39e463'],
            // Ficam de fora a reposição de fábrica, desligar a cifra e mudar o servidor, que nos podem
            // tirar o aparelho.
            'pill_dispenser' => [37, 'ecee40eac03cdaf7efb85bcb0a2584579a2958b59ff7b2cfdfbda8a5dc41aca0'],
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

    public function testDefinitionsHaveUniqueKeysAndRequiredMetadata(): void
    {
        self::assertSame([
            'telemetry' => 'Telemetria',
            'health' => 'Saúde',
            'contacts' => 'Contactos',
            'alarms' => 'Alarmes',
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
