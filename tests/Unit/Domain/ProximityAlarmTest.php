<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\ProximityAlarm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** As regras do alarme de proximidade de um gateway: o que se aceita gravar e quando dispara. */
final class ProximityAlarmTest extends TestCase
{
    private const WINDOW = ['state' => 'measured', 'rssiMaxDbm' => -52, 'samples' => 4];

    public function testAGatewayNobodyConfiguredWatchesNothing(): void
    {
        self::assertSame('off', ProximityAlarm::defaults()['mode']);
        self::assertFalse(ProximityAlarm::watches(ProximityAlarm::defaults(), 'fbd87c59ba8b'));
    }

    public function testAllWatchesEveryDeviceTheGatewayRelays(): void
    {
        $settings = ['mode' => 'active'] + ProximityAlarm::defaults();

        self::assertTrue(ProximityAlarm::watches($settings, 'fbd87c59ba8b'));
    }

    public function testAChosenListWatchesOnlyThoseDevices(): void
    {
        $settings = ['mode' => 'active', 'devices' => ['fbd87c59ba8b']] + ProximityAlarm::defaults();

        self::assertTrue(ProximityAlarm::watches($settings, 'FBD87C59BA8B'));
        self::assertFalse(ProximityAlarm::watches($settings, 'fa05c2c70fc6'));
    }

    public function testFiresOnTheWindowMaximumAtTheThreshold(): void
    {
        $settings = ['triggerDbm' => -52] + ProximityAlarm::defaults();

        self::assertTrue(ProximityAlarm::fires($settings, self::WINDOW));
        self::assertFalse(ProximityAlarm::fires(['triggerDbm' => -51] + $settings, self::WINDOW));
    }

    /** Um máximo de uma ou duas leituras é pouco melhor do que uma leitura crua. */
    public function testTooFewSamplesNeverFire(): void
    {
        $settings = ['minSamples' => 5] + ProximityAlarm::defaults();

        self::assertFalse(ProximityAlarm::fires($settings, self::WINDOW));
    }

    public function testASilentPairNeverFires(): void
    {
        self::assertFalse(ProximityAlarm::fires(ProximityAlarm::defaults(), ['state' => 'unknown', 'samples' => 0]));
    }

    public function testSanitizeFillsTheDefaultsAndNormalisesTheDevices(): void
    {
        self::assertSame(
            ['mode' => 'calibrating', 'devices' => ['fbd87c59ba8b'], 'triggerDbm' => -60, 'minSamples' => 3, 'cooldownSeconds' => 60],
            ProximityAlarm::sanitize(['mode' => 'calibrating', 'devices' => [' FBD87C59BA8B ', 'fbd87c59ba8b'], 'triggerDbm' => -60]),
        );
    }

    /** @param array<string, mixed> $value */
    #[DataProvider('invalid')]
    public function testSanitizeRejectsWhatTheRulesCannotUse(array $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProximityAlarm::sanitize($value);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalid(): array
    {
        return [
            'unknown mode' => [['mode' => 'on']],
            'threshold too strong' => [['triggerDbm' => -20]],
            'threshold too weak' => [['triggerDbm' => -110]],
            'more samples than the window holds' => [['minSamples' => 11]],
            'no samples' => [['minSamples' => 0]],
            'negative cooldown' => [['cooldownSeconds' => -1]],
            'devices neither all nor a list' => [['devices' => 'some']],
        ];
    }
}
