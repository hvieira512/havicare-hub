<?php

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class CapabilityRegistryTest extends TestCase
{
    /**
     * Uma configuração é um downlink à espera de acontecer, e é assim que fica por
     * omissão: só quem se marca com o `HubAppliedCapability` é que o hub aplica sozinho.
     */
    public function testACapabilityTravelsToTheDeviceUnlessItSaysOtherwise(): void
    {
        $registry = new CapabilityRegistry();

        self::assertTrue($registry->travelsToDevice('alarm_clock'));
        self::assertTrue($registry->travelsToDevice('push_message'));
        // Sem contrato registado também viaja: as capacidades simples resolvem-se pelos
        // metadados do catálogo e saem num comando na mesma.
        self::assertTrue($registry->travelsToDevice('uma_capacidade_que_nao_existe'));
    }

    public function testWonlexPushMessageMapsToMessageNoticePayload(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(
            ['pushMessage' => ['message' => 'Hello World']],
            $registry->toNative('wonlex-json', 'push_message', ['message' => 'Hello World'])
        );
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
            ['profile' => ['mode' => 1]],
            $registry->toNative('four-p-touch', 'sound_profile', [
                'mode' => 1,
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
            'sosNumbers' => ['+351210000000'],
        ]);

        self::assertSame('351', $native['familyNumber']['contacts'][0]['areaCode']);
        self::assertTrue($native['familyNumber']['contacts'][0]['sosSwitch']);
        self::assertSame(
            [['name' => 'Care', 'phone' => '+351210000000']],
            $registry->fromNative('phonebook', 'familyNumber', $native['familyNumber'])
        );
    }

    public function testWonlexPhonebookTruncatesNamesLongerThanFourCharacters(): void
    {
        $registry = new CapabilityRegistry();
        $native = $registry->toNative('wonlex-json', 'phonebook', [
            'contacts' => [[
                'name' => 'Rodrigo',
                'phone' => '+351938854803',
            ]],
        ]);

        self::assertSame('Rodr', $native['familyNumber']['contacts'][0]['name'] ?? null);
    }

    public function testFourPTouchPhonebookTruncatesUnicodeNamesToDeclaredLimit(): void
    {
        $registry = new CapabilityRegistry();
        $native = $registry->toNative('four-p-touch', 'phonebook', [
            'contacts' => [[
                'name' => 'Áéíóú123456',
                'phone' => '123456789',
            ]],
        ]);

        self::assertSame('Áéíóú12345', $native['phonebook']['contacts'][0]['name'] ?? null);
    }

    public function testWonlexSwitchFieldsAreNormalizedBidirectionally(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(
            ['wonlexFallWarnSwitch' => ['switchState' => false]],
            $registry->toNative('wonlex-json', 'fall_detection', ['enabled' => false])
        );
        self::assertSame(
            ['enabled' => true],
            $registry->fromNative(
                'fall_detection',
                'wonlexFallWarnSwitch',
                ['switchState' => 1],
                'wonlex-json'
            )
        );
        self::assertSame(
            [
                'remindValue' => 120,
                'enabled' => true,
                'exerciseEnabled' => false,
            ],
            $registry->fromNative(
                'heart_rate_high_alert',
                'wonlexHeartRateHighRemind',
                [
                    'switchState' => 1,
                    'remindValue' => 120,
                    'exerciseSwitchState' => 0,
                ],
                'wonlex-json'
            )
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
            $registry->fromNative('alarm_clock', 'alarmClock', [
                'alarmClockList' => [[
                    'label' => 'Medicine',
                    'startTime' => '08:00',
                    'week' => '1111111',
                    'status' => '1',
                ]],
            ], 'wonlex-json')
        );

        self::assertSame(
            '08:00',
            $registry->fromNative('alarm_clock', 'alarmClock', [
                'alarms' => [['time' => '08:00', 'enabled' => true, 'mode' => 1, 'custom' => '']],
            ], 'four-p-touch')[0]['time'] ?? null
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
            $registry->fromNative('alarm_clock', 'alarmClock', [
                'alarmClockList' => [[
                    'label' => 'Disabled',
                    'startTime' => '08:00',
                    'week' => '0000000',
                    'status' => '0',
                ]],
            ], 'wonlex-json')
        );
    }
}
