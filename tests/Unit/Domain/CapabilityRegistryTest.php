<?php

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class CapabilityRegistryTest extends TestCase
{
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

    public function testWonlexPhonebookRejectsNamesLongerThanFourCharacters(): void
    {
        $registry = new CapabilityRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name must contain at most 4 characters');

        $registry->toNative('wonlex-json', 'phonebook', [
            'contacts' => [[
                'name' => 'Rodri',
                'phone' => '+351938854803',
            ]],
        ]);
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
}
