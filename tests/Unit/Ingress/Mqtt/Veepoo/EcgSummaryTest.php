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
 * O que um exame de ECG desta pulseira diz, além do traçado.
 *
 * O relatório final que o fabricante documenta não existe neste firmware, e o que há são as
 * tramas de estado, uma por segundo. Os valores destes testes são de uma medição real, com os
 * artefactos que ela trouxe: a mediana sobrevive-lhes, a média deixava-os entrar.
 */
final class EcgSummaryTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /** O exame sai num envelope só, com o traçado e o que ele mediu. */
    public function testTheExamCarriesItsOwnReadings(): void
    {
        $data = $this->ecg(self::captured());

        self::assertSame([12, -4, 33], $data['samples']);
        self::assertSame(500, $data['frequencyHz']);
        // Com um número par de leituras fica a de cima das duas do meio -- um valor que o
        // aparelho mediu mesmo, e não uma média que ninguém leu.
        self::assertSame(60, $data['heartRateBpm']);
        self::assertSame(137, $data['hrvMilliseconds']);
        self::assertSame(394, $data['qtcMilliseconds']);
        // Os intervalos R-R vêm em unidades de dez milissegundos, como nos blocos diários:
        // 100 são os 1000 ms de um coração a 60 batimentos.
        self::assertSame(1000, $data['rrIntervalMilliseconds']);
    }

    /**
     * As tramas a zero são o sinal a assentar, e não leituras.
     *
     * Nos primeiros segundos o firmware repete a trama inteira a zeros. Contá-las puxava a
     * mediana para baixo de tudo o que o exame mediu de verdade.
     */
    public function testFramesWhileTheSignalSettlesDoNotCount(): void
    {
        $data = $this->ecg([
            ['wearStatus' => 'wearPass', 'HR2PerMinute' => 0, 'Hrv' => 0, 'QTC' => 0, 'RR1PerSecond' => 0],
            ['wearStatus' => 'wearPass', 'HR2PerMinute' => 0, 'Hrv' => 0, 'QTC' => 0, 'RR1PerSecond' => 0],
            ['wearStatus' => 'wearPass', 'HR2PerMinute' => 60, 'Hrv' => 120, 'QTC' => 390, 'RR1PerSecond' => 100],
        ]);

        self::assertSame(60, $data['heartRateBpm']);
        self::assertSame(390, $data['qtcMilliseconds']);
    }

    /**
     * Um QTc de 712 ms e um HRV de 8 ms são artefactos, e a mediana não os deixa passar.
     *
     * Ambos saíram da medição real. Um QTc acima de 600 ms não é uma leitura de um coração
     * saudável a 59 batimentos -- é o algoritmo a falhar um complexo.
     */
    public function testAnOutlierDoesNotMoveTheResult(): void
    {
        $data = $this->ecg([
            ['wearStatus' => 'wearPass', 'HR2PerMinute' => 59, 'Hrv' => 117, 'QTC' => 394, 'RR1PerSecond' => 100],
            ['wearStatus' => 'wearPass', 'HR2PerMinute' => 59, 'Hrv' => 8, 'QTC' => 712, 'RR1PerSecond' => 100],
            ['wearStatus' => 'wearPass', 'HR2PerMinute' => 58, 'Hrv' => 141, 'QTC' => 391, 'RR1PerSecond' => 103],
        ]);

        self::assertSame(394, $data['qtcMilliseconds'], 'o 712 fica de fora do intervalo plausível');
        self::assertSame(117, $data['hrvMilliseconds']);
    }

    /**
     * O `--` é o sentinela de «sem leitura» do firmware, e chega em texto no meio de números.
     */
    public function testTheFirmwareSentinelIsNotAReading(): void
    {
        $data = $this->ecg([
            ['wearStatus' => 'wearPass', 'HR2PerMinute' => 60, 'Hrv' => '--', 'QTC' => 390, 'RR1PerSecond' => 100],
            ['wearStatus' => 'wearPass', 'HR2PerMinute' => 60, 'Hrv' => 106, 'QTC' => 390, 'RR1PerSecond' => 100],
        ]);

        self::assertSame(106, $data['hrvMilliseconds']);
    }

    /**
     * O que esta pulseira não mede não aparece.
     *
     * A respiração e a velocidade da onda de pulso vêm nas tramas e vieram a zero nas 34 da
     * medição real, do princípio ao fim. Publicá-las dava uma respiração de zero ciclos por
     * minuto a quem estava claramente a respirar.
     */
    public function testWhatTheBandNeverMeasuresIsNotPublished(): void
    {
        $data = $this->ecg(self::captured());

        self::assertArrayNotHasKey('breathsPerMinute', $data);
        self::assertArrayNotHasKey('pulseWaveVelocity', $data);
    }

    /** Sem tramas de estado o exame é só o traçado, como era antes. */
    public function testAnExamWithoutStatusFramesIsStillATracing(): void
    {
        $data = $this->ecg([]);

        self::assertSame([12, -4, 33], $data['samples']);
        self::assertArrayNotHasKey('heartRateBpm', $data);
    }

    /**
     * As tramas por segundo continuam a não ser telemetria por si.
     *
     * São trinta e quatro por exame, e publicá-las uma a uma enchia o histórico do aparelho
     * com o decorrer da medição em vez do resultado dela. Também não podem dar um aviso de
     * tipo sem normalização: são reconhecidas, e resumidas no fim.
     */
    public function testTheLiveFramesAreNotPublishedOneByOne(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        foreach (self::captured() as $frame) {
            $bridge->handleReceivedMessage(self::TOPIC, json_encode([
                'source' => 'veepoo-node',
                'kind' => 'measurement',
                'device' => ['mac' => self::BRACELET],
                'payload' => ['sdkType' => 42] + $frame,
            ], JSON_THROW_ON_ERROR));
        }

        self::assertSame([], $mqtt->telemetry);
    }

    /**
     * Tramas de uma medição real, tal como saíram do gateway.
     *
     * @return list<array<string, mixed>>
     */
    private static function captured(): array
    {
        return [
            ['wristbandStatus' => 'open', 'wearStatus' => 'wearPass', 'HR1PerSecond' => 0, 'HR2PerMinute' => 0, 'Hrv' => '--', 'RR1PerSecond' => 0, 'RR2Per6Second' => 0, 'BR2PerSecond' => 0, 'BR2PerMinute' => 0, 'M_ID' => 0, 'QTC' => 0, 'PWV' => 0],
            ['wristbandStatus' => 'open', 'wearStatus' => 'wearPass', 'HR1PerSecond' => 62, 'HR2PerMinute' => 61, 'Hrv' => 137, 'RR1PerSecond' => 99, 'RR2Per6Second' => 96, 'BR2PerSecond' => 0, 'BR2PerMinute' => 0, 'M_ID' => 21, 'QTC' => 396, 'PWV' => 0],
            ['wristbandStatus' => 'open', 'wearStatus' => 'wearPass', 'HR1PerSecond' => 59, 'HR2PerMinute' => 58, 'Hrv' => 117, 'RR1PerSecond' => 100, 'RR2Per6Second' => 95, 'BR2PerSecond' => 0, 'BR2PerMinute' => 0, 'M_ID' => 21, 'QTC' => 394, 'PWV' => 0],
            ['wristbandStatus' => 'open', 'wearStatus' => 'wearPass', 'HR1PerSecond' => 61, 'HR2PerMinute' => 59, 'Hrv' => 8, 'RR1PerSecond' => 124, 'RR2Per6Second' => 148, 'BR2PerSecond' => 0, 'BR2PerMinute' => 0, 'M_ID' => 21, 'QTC' => 712, 'PWV' => 0],
            ['wristbandStatus' => 'open', 'wearStatus' => 'wearPass', 'HR1PerSecond' => 68, 'HR2PerMinute' => 60, 'Hrv' => 148, 'RR1PerSecond' => 83, 'RR2Per6Second' => 81, 'BR2PerSecond' => 0, 'BR2PerMinute' => 0, 'M_ID' => 21, 'QTC' => 388, 'PWV' => 0],
        ];
    }

    /**
     * @param list<array<string, mixed>> $status
     * @return array<string, mixed>
     */
    private function ecg(array $status): array
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'ecg_wave',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['samples' => [12, -4, 33], 'samplingHz' => 500, 'status' => $status],
        ], JSON_THROW_ON_ERROR));

        foreach ($mqtt->telemetry as $telemetry) {
            if (($telemetry['payload']['type'] ?? '') === 'ecg') {
                return $telemetry['payload']['data'];
            }
        }

        self::fail('o exame não foi publicado');
    }

    private function bridge(RecordingHubMqttBridge $mqtt): Bridge
    {
        return new Bridge(
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
