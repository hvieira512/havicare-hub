<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Tests\Support\Doubles\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** O gateway publica o registo de sono em espécie própria, fora dos blocos de cinco minutos, e o hub tem de o fazer sair. */
final class BridgeSleepTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /** O `payload` é a lista das noites com a curva em inteiros, um por minuto, e não o objecto com texto que a documentação sugere. */
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

    /** O normalizador desconta o desvio de fuso da trama: uma noite começada à 01:00 em Lisboa não sai como 01:00 UTC. */
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
