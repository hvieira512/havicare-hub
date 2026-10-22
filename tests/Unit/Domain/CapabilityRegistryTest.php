<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class CapabilityRegistryTest extends TestCase
{
    /** Por omissão uma configuração é um downlink; só a sensibilidade das fraldas o hub aplica sozinho. */
    public function testACapabilityTravelsToTheDeviceUnlessItSaysOtherwise(): void
    {
        $registry = new CapabilityRegistry();

        self::assertTrue($registry->travelsToDevice('alarm_clock'));
        self::assertTrue($registry->travelsToDevice('push_message'));
        // Sem contrato registado também viaja: as capacidades simples resolvem-se pelos
        // metadados do catálogo e saem num comando na mesma.
        self::assertTrue($registry->travelsToDevice('uma_capacidade_que_nao_existe'));
    }

    /** O `data` e o `fields` são a trama nativa já montada, e vindos da API chegariam ao relógio sem a validação do construtor. */
    public function testAPublicValueCannotCarryARawNativePayload(): void
    {
        $registry = new CapabilityRegistry();

        $raw = [
            ['four-p-touch', 'location_reporting_interval', ['fields' => ['5']]],
            ['vivistar-iw', 'auto_vitals_interval', ['fields' => ['1', '0']]],
            ['wonlex-json', 'low_battery_alert', ['data' => ['Battery' => -1]]],
        ];
        foreach ($raw as [$protocol, $key, $value]) {
            try {
                $registry->toNative($protocol, $key, $value);
                self::fail("{$protocol} {$key} aceitou a trama nativa");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testVivistarAutoVitalsIntervalMapsToAutoHealthMeasurement(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(
            ['autoHealthMeasurement' => ['enabled' => false, 'intervalMinutes' => 0]],
            $registry->toNative('vivistar-iw', 'auto_vitals_interval', [
                'enabled' => false,
                'intervalMinutes' => 0,
            ])
        );
    }

    public function testFourPTouchSoundProfileMapsToTheNativeSoundProfilePayload(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(
            ['profile' => ['mode' => 'ring_and_vibrate']],
            $registry->toNative('four-p-touch', 'sound_profile', [
                'mode' => 'ring_and_vibrate',
            ])
        );
    }

    public function testWonlexPhonebookEncodesNativeFieldsButReturnsGenericContacts(): void
    {
        $registry = new CapabilityRegistry();
        $native = $registry->toNative('wonlex-json', 'phonebook', [
            'contacts' => [[
                'familyNumberId' => '8c67b51b',
                'name' => 'Care',
                'phone' => '+351210000000',
            ]],
            'numbers' => ['+351210000000'],
        ]);

        self::assertSame('351', $native['familyNumber']['contacts'][0]['areaCode']);
        self::assertTrue($native['familyNumber']['contacts'][0]['sosSwitch']);
        self::assertSame(
            [['name' => 'Care', 'phone' => '+351210000000']],
            $registry->fromNative('wonlex-json', 'phonebook', 'familyNumber', $native['familyNumber'])
        );
    }

    public function testWonlexPhonebookKeepsTheWholeNameInTheNativeFrame(): void
    {
        $registry = new CapabilityRegistry();
        $native = $registry->toNative('wonlex-json', 'phonebook', [
            'contacts' => [[
                'name' => 'Rodrigo',
                'phone' => '+351938854803',
            ]],
        ]);

        self::assertSame('Rodrigo', $native['familyNumber']['contacts'][0]['name'] ?? null);
    }

    public function testFourPTouchPhonebookKeepsNamesTheDeviceAccepts(): void
    {
        $registry = new CapabilityRegistry();
        $native = $registry->toNative('four-p-touch', 'phonebook', [
            'contacts' => [[
                'name' => 'Áéíóú123456',
                'phone' => '123456789',
            ]],
        ]);

        self::assertSame(
            'Áéíóú123456',
            $native['phonebook']['contacts'][0]['name'] ?? null,
            'o PHBX2 não tem limite de nome, e truncar em silêncio escondia a diferença'
        );
    }

    /** A Wonlex guarda a forma pública: o `switchState` é só do fio. */
    public function testWonlexSwitchesKeepThePublicShape(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(
            ['wonlexFallWarnSwitch' => ['enabled' => false]],
            $registry->toNative('wonlex-json', 'fall_detection', ['enabled' => false])
        );
        self::assertSame(
            ['enabled' => true, 'thresholdBpm' => 120],
            $registry->fromNative('wonlex-json', 'heart_rate_high_alert', 'wonlexHeartRateHighRemind', ['enabled' => true, 'thresholdBpm' => 120])
        );
    }

    public function testAlarmClockReadUsesProtocolWhenNativeKeysOverlap(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(
            [[
                'label' => 'Medicine',
                'time' => '08:00',
                'enabled' => true,
                'recurrence' => ['kind' => 'daily'],
            ]],
            $registry->fromNative('wonlex-json', 'alarm_clock', 'alarmClock', [
                'alarmClockList' => [[
                    'label' => 'Medicine',
                    'startTime' => '08:00',
                    'week' => '1111111',
                    'status' => '1',
                ]],
            ])
        );

        self::assertSame(
            '08:00',
            $registry->fromNative('four-p-touch', 'alarm_clock', 'alarmClock', [
                'alarms' => [['time' => '08:00', 'enabled' => true, 'mode' => 1, 'custom' => '']],
            ])[0]['time'] ?? null
        );
    }

    public function testWonlexAlarmClockZeroWeekMaskIsNotReportedAsOnce(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(
            [[
                'label' => 'Disabled',
                'time' => '08:00',
                'enabled' => false,
                'recurrence' => ['kind' => 'custom', 'days' => []],
            ]],
            $registry->fromNative('wonlex-json', 'alarm_clock', 'alarmClock', [
                'alarmClockList' => [[
                    'label' => 'Disabled',
                    'startTime' => '08:00',
                    'week' => '0000000',
                    'status' => '0',
                ]],
            ])
        );
    }
}
