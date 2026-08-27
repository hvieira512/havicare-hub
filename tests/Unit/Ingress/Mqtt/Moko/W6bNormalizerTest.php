<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\W6bNormalizer;
use PHPUnit\Framework\TestCase;

final class W6bNormalizerTest extends TestCase
{
    private const DEVICE = [
        'imei' => 'fbd87c59ba8b',
        'supplier' => 'MOKO',
        'model' => 'W6B',
        'commercialName' => 'MOKO W6B',
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
        $result = (new W6bNormalizer())->normalize(
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
        self::assertSame('moko-w6b', $event['source']['protocol']);
        self::assertSame('fbd87c59ba8b', $event['device']['id']);
    }

    public function testTheFirstSightingEstablishesTheBaselineInsteadOfReplayingHistory(): void
    {
        // O contador é anunciado continuamente, e por isso um aparelho já com 42 toques não
        // pode dar alarme no instante em que o hub reinicia.
        $result = (new W6bNormalizer())->normalize(
            $this->decoded('single', 42),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: null,
        );

        self::assertSame([], $result['events']);
    }

    public function testAnUnchangedCounterIsNotAPress(): void
    {
        $result = (new W6bNormalizer())->normalize(
            $this->decoded('single', 5),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 5,
        );

        self::assertSame([], $result['events']);
    }

    public function testSeveralPressesBetweenSightingsAreCounted(): void
    {
        $result = (new W6bNormalizer())->normalize(
            $this->decoded('single', 9),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 5,
        );

        self::assertSame(4, $result['events'][0]['data']['presses']);
    }

    public function testACounterResetIsOnePressRatherThanANegativeDelta(): void
    {
        // Trocar a bateria reinicia o aparelho e põe os contadores a zero.
        $result = (new W6bNormalizer())->normalize(
            $this->decoded('single', 1),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 40,
        );

        self::assertSame(1, $result['events'][0]['data']['presses']);
    }

    public function testInactivityIsNotAHelpCall(): void
    {
        // O modo é descodificado mas não dá alarme, de propósito: quem dorme calmo
        // dispará-lo-ia todas as noites.
        $result = (new W6bNormalizer())->normalize(
            $this->decoded('inactivity', 3),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 2,
        );

        self::assertSame([], $result['events']);
    }

    public function testBatteryAndMotionBecomeTelemetry(): void
    {
        $result = (new W6bNormalizer())->normalize(
            $this->decoded('single', 5, [
                'accelerationMg' => ['x' => 40, 'y' => -124, 'z' => 984],
                'batteryPercent' => 80,
            ]),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 5,
        );

        self::assertSame(['battery', 'motion'], array_keys($result['telemetry']));
        self::assertSame(80, $result['telemetry']['battery']['data']['percent']);
        self::assertSame(40, $result['telemetry']['motion']['data']['xMg']);
        self::assertSame(-124, $result['telemetry']['motion']['data']['yMg']);
        // sqrt(40^2 + 124^2 + 984^2) = 992.59
        self::assertSame(993, $result['telemetry']['motion']['data']['magnitudeMg']);
    }

    public function testVoltageIsUsedWhenThereIsNoPercentage(): void
    {
        $result = (new W6bNormalizer())->normalize(
            $this->decoded('single', 5, ['batteryVoltageMv' => 3276]),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 5,
        );

        self::assertSame(3276, $result['telemetry']['battery']['data']['voltageMv']);
    }

    public function testAnAlarmOnlyFrameProducesNoTelemetry(): void
    {
        $result = (new W6bNormalizer())->normalize(
            $this->decoded('single', 6),
            self::DEVICE,
            'd48c49f7909c',
            previousTriggerCount: 5,
        );

        self::assertSame([], $result['telemetry']);
        self::assertCount(1, $result['events']);
    }
}
