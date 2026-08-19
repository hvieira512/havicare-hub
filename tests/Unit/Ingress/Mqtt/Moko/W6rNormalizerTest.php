<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\W6rNormalizer;
use PHPUnit\Framework\TestCase;

final class W6rNormalizerTest extends TestCase
{
    private const DEVICE = [
        'imei' => 'fbd87c59ba8b',
        'supplier' => 'MOKO',
        'model' => 'W6R',
        'commercialName' => 'MOKO W6R',
    ];

    /** @return array<string, mixed> */
    private function decoded(string $pressMode = 'single', int $count = 5, ?array $info = null): array
    {
        $frameType = ['single' => 0x20, 'double' => 0x21, 'long' => 0x22, 'inactivity' => 0x23][$pressMode];

        return [
            'mac' => 'fbd87c59ba8b',
            'alarm' => [
                'pressMode' => $pressMode,
                'frameType' => $frameType,
                'triggerCount' => $count,
                'triggered' => true,
                'deviceId' => '000000000001',
                'firmwareType' => 0,
            ],
            'info' => $info,
        ];
    }

    public function testRisingTriggerCountEmitsAHelpCallCarryingThePressType(): void
    {
        $result = (new W6rNormalizer())->normalize(
            $this->decoded('long', 6),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 5,
        );

        self::assertCount(1, $result['events']);
        $event = $result['events'][0];
        self::assertSame('help_call', $event['type']);
        self::assertSame('long', $event['data']['pressType']);
        self::assertSame(6, $event['data']['triggerCount']);
        self::assertSame(1, $event['data']['presses']);
        self::assertSame('d48c49f7909c', $event['source']['gatewayId']);
        self::assertSame('moko-w6r', $event['source']['protocol']);
        self::assertSame('fbd87c59ba8b', $event['device']['id']);
    }

    public function testTheFirstSightingEstablishesTheBaselineInsteadOfReplayingHistory(): void
    {
        // The counter is broadcast continuously, so a device already at 42
        // presses must not produce an alarm the moment the hub restarts.
        $result = (new W6rNormalizer())->normalize(
            $this->decoded('single', 42),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: null,
        );

        self::assertSame([], $result['events']);
    }

    public function testAnUnchangedCounterIsNotAPress(): void
    {
        $result = (new W6rNormalizer())->normalize(
            $this->decoded('single', 5),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 5,
        );

        self::assertSame([], $result['events']);
    }

    public function testSeveralPressesBetweenSightingsAreCounted(): void
    {
        $result = (new W6rNormalizer())->normalize(
            $this->decoded('single', 9),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 5,
        );

        self::assertSame(4, $result['events'][0]['data']['presses']);
    }

    public function testACounterResetIsOnePressRatherThanANegativeDelta(): void
    {
        // Replacing the battery restarts the device and zeroes its counters.
        $result = (new W6rNormalizer())->normalize(
            $this->decoded('single', 1),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 40,
        );

        self::assertSame(1, $result['events'][0]['data']['presses']);
    }

    public function testInactivityIsNotAHelpCall(): void
    {
        // The mode is decoded but deliberately not alarmed on: a calm sleeper
        // would trip it every night.
        $result = (new W6rNormalizer())->normalize(
            $this->decoded('inactivity', 3),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 2,
        );

        self::assertSame([], $result['events']);
    }

    /**
     * The accelerometer is present in the advertisement and deliberately dropped.
     * It published every couple of seconds carrying nothing but gravity and the
     * sensor's 4 mg of noise, and nothing consumed it: proximity is decided from
     * RSSI alone. Asserting on the input still containing `accelerationMg` is the
     * point -- it proves the field is ignored rather than merely absent.
     */
    public function testBatteryBecomesTelemetryAndTheAccelerometerIsIgnored(): void
    {
        $result = (new W6rNormalizer())->normalize(
            $this->decoded('single', 5, [
                'accelerationMg' => ['x' => 40, 'y' => -124, 'z' => 984],
                'batteryPercent' => 80,
            ]),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 5,
        );

        self::assertSame(['battery'], array_keys($result['telemetry']));
        self::assertSame(80, $result['telemetry']['battery']['data']['percent']);
    }

    public function testVoltageIsUsedWhenThereIsNoPercentage(): void
    {
        $result = (new W6rNormalizer())->normalize(
            $this->decoded('single', 5, ['batteryVoltageMv' => 3276]),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 5,
        );

        self::assertSame(3276, $result['telemetry']['battery']['data']['voltageMv']);
    }

    public function testAnAlarmOnlyFrameProducesNoTelemetry(): void
    {
        $result = (new W6rNormalizer())->normalize(
            $this->decoded('single', 6),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 5,
        );

        self::assertSame([], $result['telemetry']);
        self::assertCount(1, $result['events']);
    }
}
