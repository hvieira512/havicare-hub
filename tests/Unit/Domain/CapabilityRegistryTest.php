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

    public function testWonlexFamilyContactsPreserveInternationalAndSosFields(): void
    {
        $registry = new CapabilityRegistry();
        $native = $registry->toNative('wonlex-json', 'call_whitelist', [
            'contacts' => [[
                'familyNumberId' => '8c67b51b',
                'name' => 'Care',
                'phone' => '210000000',
                'areaCode' => '351',
                'sosSwitch' => true,
            ]],
        ]);

        self::assertSame('351', $native['familyNumber']['contacts'][0]['areaCode']);
        self::assertTrue($native['familyNumber']['contacts'][0]['sosSwitch']);
        self::assertSame(
            $native['familyNumber']['contacts'],
            $registry->fromNative('call_whitelist', 'familyNumber', $native['familyNumber'])
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
