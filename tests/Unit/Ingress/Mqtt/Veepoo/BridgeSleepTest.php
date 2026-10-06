<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Tests\Support\Doubles\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * O registo de sono chega ao hub e tem de sair dele.
 *
 * O gateway publica-o em espécie própria, fora dos blocos de cinco minutos.
 */
final class BridgeSleepTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    public function testTheNightIsPublishedAsSleepAndAsItsScores(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::sleep());

        $types = array_map(
            static fn(array $e): string => (string)($e['payload']['type'] ?? ''),
            $mqtt->telemetry,
        );

        self::assertContains('sleep', $types);
        self::assertContains('sleep_quality', $types);
    }

    /** E leva a identidade do aparelho, como toda a telemetria que sai daqui. */
    public function testTheRecordCarriesTheDeviceAndTheGateway(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::sleep());

        $sleep = array_values(array_filter(
            $mqtt->telemetry,
            static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'sleep',
        ));

        self::assertCount(1, $sleep);
        self::assertSame('MF91', $sleep[0]['payload']['device']['model']);
        self::assertSame(self::GATEWAY, $sleep[0]['payload']['source']['gatewayId']);
        self::assertSame(480, $sleep[0]['payload']['data']['totalDurationMinutes']);
    }

    /**
     * O `payload` é a lista das noites e a curva vem em inteiros, um por minuto -- e não um objecto
     * com a curva em texto, como a documentação do fabricante deixa supor.
     */
    public function testANightArrivesWrappedInAListWithTheCurveInIntegers(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::sleepAsTheBraceletSendsIt());

        $sleep = array_values(array_filter(
            $mqtt->telemetry,
            static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'sleep',
        ));

        self::assertCount(1, $sleep, 'a noite tem de sair, venha embrulhada em lista ou não');
        self::assertSame(10, $sleep[0]['payload']['data']['totalDurationMinutes']);
    }

    /**
     * A trama declara o desvio do fuso e o normalizador desconta-o: uma noite começada à 01:00 em
     * Lisboa não sai como 01:00 UTC.
     */
    public function testTheInstantsAreConvertedFromTheBraceletClockToUtc(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::sleepAsTheBraceletSendsIt());

        $sleep = array_values(array_filter(
            $mqtt->telemetry,
            static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'sleep',
        ));

        self::assertCount(1, $sleep);
        self::assertSame(
            gmdate('Y') . '-09-16T00:00:00Z',
            $sleep[0]['payload']['data']['startTime'],
            'a pulseira diz 01:00 no relógio dela, que com +60 minutos são 00:00 em UTC',
        );
    }

    /**
     * A forma da noite que a MF91 devolveu, com dez minutos em vez de 639: uma entrada de curva por
     * minuto, e as contagens somam os troços declarados.
     */
    private static function sleepAsTheBraceletSendsIt(): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'sleep',
            'device' => ['mac' => self::BRACELET],
            'tzOffsetMinutes' => 60,
            'payload' => [[
                'fallAsleepTime' => '09-16-01-00',
                'exitSleepTime' => '09-16-01-10',
                'sleepQuality' => 2,
                'deepSleepTime' => 2,
                'lightSleepTime' => 6,
                'otherSleepTime' => 2,
                'sleepTotalTime' => 10,
                'sleepCurve' => [0, 0, 1, 1, 1, 1, 2, 2, 1, 1],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** Uma trama de sono vazia não publica uma noite de nada. */
    public function testAnEmptyRecordPublishesNoTelemetry(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'sleep',
            'device' => ['mac' => self::BRACELET],
            'payload' => [],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], $mqtt->telemetry);
    }

    private static function sleep(): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'sleep',
            'device' => ['mac' => self::BRACELET],
            'payload' => [
                'fallAsleepTime' => '09-14-23-10',
                'exitSleepTime' => '09-15-07-30',
                'nightScore' => 88,
                'deepSleepScore' => 82,
                'sleepEfficiencyScore' => 75,
                'fallAsleepEfficiencyScore' => 90,
                'sleepTimeScore' => 70,
                'sleepQuality' => 3,
                'deepSleepTime' => 120,
                'lightSleepTime' => 330,
                'otherSleepTime' => 30,
                'sleepTotalTime' => 480,
                'firstDeepSleepTime' => 22,
                'nightTotalTime' => 18,
                'nightDeepSleepMeanValue' => 6,
                'insomniaScore' => 65,
                'insomniaCount' => 2,
                'sleepCurve' => '00112234',
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(RecordingHubMqttBridge $mqtt): VeepooBridge
    {
        return new VeepooBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'Veepoo Gateway', 'gateway'),
                self::BRACELET => IngressFixtures::device('Wonlex', 'MF91', 'bracelet'),
            ]),
            $mqtt,
            IngressFixtures::links(true),
            null,
            new ArrayObservationStateStore(),
            'havicare-hub/null/0/gw/+/raw',
        );
    }
}
