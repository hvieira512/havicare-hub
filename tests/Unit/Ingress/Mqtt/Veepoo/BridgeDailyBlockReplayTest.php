<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Ingress\Mqtt\Gateway\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Veepoo\Bridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * Um bloco que já saiu não volta a sair.
 *
 * A MF91 não empurra nada: guarda blocos de cinco minutos e só responde a quem lhe pergunta.
 * O gateway relê por isso o dia corrente de cinco em cinco minutos, e os dias retidos sempre
 * que arranca -- é o desenho, e é o único que o aparelho permite. O que daí resulta é o mesmo
 * bloco entregue ao hub vezes sem conta.
 *
 * Sem porta, cada entrega republica as mesmas medições, com o mesmo instante, no MQTT e no
 * histórico. Quem integra não distingue a repetição de uma leitura nova sem manter o índice
 * de tudo o que já viu, e no histórico da dashboard -- que guarda cem entradas -- as
 * repetições expulsam o que era real.
 */
final class BridgeDailyBlockReplayTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /** Cinco minutos de frequência cardíaca: o bloco mais simples que produz telemetria. */
    private const BLOCK = [
        'date' => '2026-09-09-16-35',
        'pulseReat' => [70, 71, 72, 73, 74],
    ];

    public function testTheSameBlockDeliveredTwiceIsPublishedOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage(self::TOPIC, self::message([self::BLOCK]));
        $first = count($mqtt->telemetry);
        $bridge->handleReceivedMessage(self::TOPIC, self::message([self::BLOCK]));

        self::assertSame(5, $first, 'o bloco tem cinco minutos de batimentos');
        self::assertCount($first, $mqtt->telemetry, 'a segunda entrega não acrescenta nada');
    }

    /**
     * O bloco do minuto a decorrer chega incompleto e é preenchido na leitura seguinte. A
     * porta compara o conteúdo e não só o carimbo: com a data sozinha, a primeira versão
     * congelava o bloco e os minutos que faltavam nunca chegavam a sair.
     */
    public function testABlockThatGrewIsPublishedAgain(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage(self::TOPIC, self::message([
            ['date' => '2026-09-09-16-35', 'pulseReat' => [70, 71]],
        ]));
        $bridge->handleReceivedMessage(self::TOPIC, self::message([self::BLOCK]));

        self::assertCount(7, $mqtt->telemetry, 'dois minutos, e depois os cinco completos');
    }

    /** Blocos diferentes do mesmo dia continuam todos a sair. */
    public function testDistinctBlocksAreAllPublished(): void
    {
        $mqtt = new RecordingHubMqttBridge();

        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::message([
            self::BLOCK,
            ['date' => '2026-09-09-16-40', 'pulseReat' => [75, 76, 77, 78, 79]],
        ]));

        self::assertCount(10, $mqtt->telemetry);
    }

    /** A porta é por aparelho: duas pulseiras com o mesmo bloco não se calam uma à outra. */
    public function testTheGateIsPerDevice(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $state = new ArrayObservationStateStore();

        $this->bridge($mqtt, $state)->handleReceivedMessage(self::TOPIC, self::message([self::BLOCK]));
        $this->bridge($mqtt, $state)->handleReceivedMessage(
            'havicare-hub/null/0/gw/bef341903987/raw',
            self::message([self::BLOCK], 'dba376003185'),
        );

        self::assertCount(10, $mqtt->telemetry);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private static function message(array $blocks, string $mac = self::BRACELET): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'daily_block',
            'device' => ['mac' => $mac],
            'payload' => $blocks,
            'tzOffsetMinutes' => 0,
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(
        RecordingHubMqttBridge $mqtt,
        ?ArrayObservationStateStore $state = null,
    ): Bridge {
        return new Bridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'Veepoo Gateway', 'gateway'),
                self::BRACELET => IngressFixtures::device('Wonlex', 'MF91', 'bracelet'),
                'dba376003185' => IngressFixtures::device('Wonlex', 'MF91', 'bracelet'),
            ]),
            $mqtt,
            IngressFixtures::links(true),
            null,
            $state ?? new ArrayObservationStateStore(),
            'havicare-hub/null/0/gw/+/raw',
        );
    }
}
