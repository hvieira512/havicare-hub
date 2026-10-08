<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Qinglanst;

use Hub\Ingress\Mqtt\Qinglanst\QinglanstBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * O radar repete a postura e os vitais em cada trama, uma por segundo: uma queda que dura dá um
 * evento, e não um por segundo.
 */
final class BridgeRepeatedDetectionTest extends TestCase
{
    private const RADAR = 'radar-canonical-1';
    private const RADAR_UID = 'radar-topic-uid';

    public function testAPostureThatLastsRaisesOneEvent(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        foreach ([2, 2, 2, 2] as $posture) {
            $bridge->handleReceivedMessage(self::topic(), self::message('position', self::person($posture)));
        }

        self::assertSame(['fall'], array_column($mqtt->events, 'type'));
    }

    /** Quando a postura muda e volta, é uma queda nova. */
    public function testAPostureThatEndsAndReturnsRaisesItAgain(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        foreach ([2, 4, 2] as $posture) {
            $bridge->handleReceivedMessage(self::topic(), self::message('position', self::person($posture)));
        }

        self::assertSame(['fall', 'fall'], array_column($mqtt->events, 'type'));
    }

    /** A suspeita que passa a confirmada é outro acontecimento, e mais grave. */
    public function testASuspectedFallThatIsConfirmedRaisesBoth(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        foreach ([2, 5, 5] as $posture) {
            $bridge->handleReceivedMessage(self::topic(), self::message('position', self::person($posture)));
        }

        self::assertSame([false, true], array_column(array_column(array_column($mqtt->events, 'payload'), 'data'), 'confirmed'));
    }

    /** O valor muda a cada segundo, mas a frequência alta é a mesma enquanto dura. */
    public function testAHighHeartRateThatLastsRaisesOneEvent(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        foreach ([130, 134, 131] as $bpm) {
            $bridge->handleReceivedMessage(self::topic(), self::message('heartbreath', self::heartBreath($bpm)));
        }

        self::assertSame(['heart_rate_high'], array_column($mqtt->events, 'type'));
        self::assertSame(['bpm' => 130], $mqtt->events[0]['payload']['data']);
    }

    /** A posição não apaga o que os vitais levantaram: cada trama responde pelo seu lado. */
    public function testEachMessageTypeKeepsItsOwnDetections(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage(self::topic(), self::message('heartbreath', self::heartBreath(130)));
        $bridge->handleReceivedMessage(self::topic(), self::message('position', self::person(4)));
        $bridge->handleReceivedMessage(self::topic(), self::message('heartbreath', self::heartBreath(132)));

        self::assertSame(['heart_rate_high'], array_column($mqtt->events, 'type'));
    }

    /** A planta guardada diz que área é: o nome que lhe deram e o tipo do fabricante, em inglês. */
    public function testAnAreaCrossingCarriesTheAreaFromTheLayout(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $layouts = static fn (string $imei): ?array => $imei === self::RADAR
            ? ['areas' => [['key' => 2, 'type' => 4, 'name' => 'Porta'], ['key' => 0, 'type' => 5, 'name' => 'Cama']]]
            : null;
        $bridge = $this->bridge($mqtt, $layouts);

        $bridge->handleReceivedMessage(self::topic(), self::message('position', self::person(1, lastEvent: 4, region: 2)));

        self::assertSame('zone_exit', $mqtt->events[0]['type']);
        self::assertSame(
            ['zone' => 'area', 'personIndex' => 1, 'areaId' => 2, 'areaName' => 'Porta', 'areaType' => 'door'],
            $mqtt->events[0]['payload']['data'],
        );
    }

    /** Sem planta sincronizada fica só o número da área. */
    public function testWithoutALayoutTheAreaKeepsOnlyItsNumber(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt, static fn (string $imei): ?array => null)
            ->handleReceivedMessage(self::topic(), self::message('position', self::person(1, lastEvent: 3, region: 2)));

        self::assertSame('zone_entry', $mqtt->events[0]['type']);
        self::assertSame(['zone' => 'area', 'personIndex' => 1, 'areaId' => 2], $mqtt->events[0]['payload']['data']);
    }

    private function bridge(RecordingHubMqttBridge $mqtt, ?\Closure $layouts = null): QinglanstBridge
    {
        return new QinglanstBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::RADAR => IngressFixtures::radar() + ['deviceId' => self::RADAR_UID],
            ]),
            $mqtt,
            layouts: $layouts,
        );
    }

    private static function topic(): string
    {
        return 'radar/1001/' . self::RADAR_UID;
    }

    /** @param list<int> $bytes */
    private static function message(string $messageType, array $bytes): string
    {
        return json_encode([
            'payload' => [
                'deviceCode' => self::RADAR_UID,
                $messageType => base64_encode(implode('', array_map('chr', $bytes))),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Uma pessoa na trama de posições: a postura no byte 13, o último movimento no 14 e a área
     * no 15.
     *
     * @return list<int>
     */
    private static function person(int $postureCode, int $lastEvent = 0, int $region = 0): array
    {
        $bytes = array_fill(0, 16, 0);
        $bytes[0] = 1;
        $bytes[1] = 4;
        $bytes[2] = 5;
        $bytes[13] = $postureCode;
        $bytes[14] = $lastEvent;
        $bytes[15] = $region;

        return $bytes;
    }

    /** @return list<int> */
    private static function heartBreath(int $bpm): array
    {
        $bytes = array_fill(0, 16, 0);
        $bytes[1] = 16;
        $bytes[2] = $bpm;

        return $bytes;
    }
}
