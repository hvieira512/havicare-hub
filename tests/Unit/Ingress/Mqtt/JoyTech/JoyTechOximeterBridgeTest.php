<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\JoyTech;

use Hub\Ingress\Mqtt\JoyTech\JoyTechFrame;
use Hub\Ingress\Mqtt\JoyTech\JoyTechOximeterBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Do gateway ao MQTT, com as tramas que o XM-101 mandou à sonda a 2026-10-19. */
final class JoyTechOximeterBridgeTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const OXIMETER = '748b34838278';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /** 98 %, 84 bpm, barra 9. */
    private const READING = 'faaaaaaf000f88625400094fa5f55f';
    /** À procura do dedo: tudo a zero e a barra com o 0x20. */
    private const SEARCHING = 'faaaaaaf000f880000002318d2f55f';

    private float $now;

    protected function setUp(): void
    {
        $this->now = (float)strtotime('2026-10-19T13:30:00Z');
    }

    /** Os primeiros valores ainda estão a estabilizar: sai a mediana dos últimos 5 s, com a hora da última trama. */
    public function testAMeasurementLeavesOnceWithTheMedianOfItsLastFiveSeconds(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $bridge->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => true]));
        $this->stream($bridge, 2.0, self::SEARCHING);
        $this->stream($bridge, 5.0, self::reading(99, 66));
        $this->stream($bridge, 4.0, self::reading(98, 83));
        $this->stream($bridge, 1.0, self::reading(97, 87));
        $lastAt = $this->now - 0.1;

        $this->now = $lastAt + 4.9;
        $bridge->dispatchQueued();
        self::assertSame([], $mqtt->telemetry, 'ainda não acabou');

        $this->now = $lastAt + 5.0;
        $bridge->dispatchQueued();
        $bridge->dispatchQueued();

        self::assertSame([['spo2Percent' => 98]], array_column(self::telemetry($mqtt, 'blood_oxygen'), 'data'));
        self::assertSame([['bpm' => 83]], array_column(self::telemetry($mqtt, 'heart_rate'), 'data'));
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', (int)$lastAt), self::telemetry($mqtt, 'blood_oxygen')[0]['occurredAt']);
        self::assertSame('fff1:88', self::telemetry($mqtt, 'heart_rate')[0]['source']['nativeType']);
    }

    /** Desligar acaba a medição sem esperar pelo silêncio. */
    public function testDisconnectingEndsTheMeasurement(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $this->stream($bridge, 4.0, self::READING);

        $bridge->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => false]));

        self::assertSame([['spo2Percent' => 98]], array_column(self::telemetry($mqtt, 'blood_oxygen'), 'data'));
        self::assertSame([['bpm' => 84]], array_column(self::telemetry($mqtt, 'heart_rate'), 'data'));
    }

    /** Menos de 3 s de leituras é o dedo a entrar e a sair, e não uma medição. */
    public function testAShortMeasurementIsNotPublished(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $this->stream($bridge, 2.5, self::READING);

        $bridge->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => false]));

        self::assertSame([], $mqtt->telemetry);
    }

    /** Com o 0x20 na barra o aparelho ainda procura o pulso, mesmo que já mostre números. */
    public function testFramesStillSearchingAreNotReadings(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $this->stream($bridge, 10.0, JoyTechFrame::encode(0x88, pack('C5', 98, 84, 0, 0x23, 0)));

        $this->now += 6;
        $bridge->dispatchQueued();

        self::assertSame([], $mqtt->telemetry);
    }

    /** Fora do que o aparelho mede — SpO2 acima de 100, barra fora de 0 a 9 — não é leitura. */
    public function testValuesOutsideTheDeviceRangeAreNotReadings(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $this->stream($bridge, 4.0, JoyTechFrame::encode(0x88, pack('C5', 127, 84, 0, 9, 0)));
        $this->stream($bridge, 4.0, JoyTechFrame::encode(0x88, pack('C5', 98, 84, 0, 0x4a, 0)));

        $this->now += 6;
        $bridge->dispatchQueued();

        self::assertSame([], $mqtt->telemetry);
    }

    /** Depois de uma medição, o dedo de volta é outra. */
    public function testTheNextMeasurementIsANewOne(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $this->stream($bridge, 4.0, self::READING);
        $this->now += 6;
        $bridge->dispatchQueued();
        $this->stream($bridge, 4.0, self::reading(96, 72));
        $this->now += 6;
        $bridge->dispatchQueued();

        self::assertSame([['spo2Percent' => 98], ['spo2Percent' => 96]], array_column(self::telemetry($mqtt, 'blood_oxygen'), 'data'));
    }

    /** Cerca de 11 tramas por segundo, como o aparelho. */
    private function stream(JoyTechOximeterBridge $bridge, float $seconds, string $hex): void
    {
        for ($elapsed = 0.0; $elapsed < $seconds; $elapsed += 0.1) {
            $bridge->handleReceivedMessage(self::TOPIC, self::message('frame', ['char' => 'fff1', 'hex' => $hex]));
            $this->now += 0.1;
        }
    }

    private static function reading(int $spo2, int $pulse): string
    {
        return JoyTechFrame::encode(0x88, pack('C5', $spo2, $pulse, 0, 9, 64));
    }

    /** @return list<array<string, mixed>> */
    private static function telemetry(RecordingHubMqttBridge $mqtt, string $type): array
    {
        return array_column($mqtt->ofType($type), 'payload');
    }

    /** @param array<string, mixed> $payload */
    private static function message(string $kind, array $payload): string
    {
        return json_encode([
            'gatewayId' => self::GATEWAY,
            'source' => 'joytech-node',
            'kind' => $kind,
            'device' => ['mac' => self::OXIMETER, 'model' => 'XM-101'],
            'payload' => $payload,
            'tzOffsetMinutes' => 60,
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(RecordingHubMqttBridge $mqtt): JoyTechOximeterBridge
    {
        return new JoyTechOximeterBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'BLE Gateway', 'gateway'),
                self::OXIMETER => IngressFixtures::device('JoyTech', 'HC-101', 'pulse_oximeter'),
            ]),
            $mqtt,
            IngressFixtures::links(),
            'havicare-hub/null/0/gw/+/raw',
            null,
            null,
            fn (): float => $this->now,
        );
    }
}
