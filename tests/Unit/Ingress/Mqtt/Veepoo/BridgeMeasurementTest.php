<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Ingress\Mqtt\Gateway\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Veepoo\Bridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * As medições pedidas ao momento, por oposição ao histórico de blocos.
 *
 * O gateway reenvia o que o SDK lhe dá sem decidir o que significa, e é aqui que o tipo do
 * SDK vira uma grandeza do hub. Antes disto só a frequência cardíaca era reconhecida: uma
 * saturação, uma glicemia ou uma temperatura medidas a pedido chegavam e eram deitadas fora
 * em silêncio, e o pedido ficava eternamente por cumprir no ecrã.
 */
final class BridgeMeasurementTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /**
     * @return list<array{0: string, 1: array<string, mixed>, 2: string, 3: array<string, mixed>}>
     */
    public static function measurements(): array
    {
        return [
            'frequência cardíaca' => ['heart_rate', ['sdkType' => 51, 'heartRate' => 74], 'heart_rate', ['bpm' => 74]],
            'oxigénio' => ['oxigénio', ['sdkType' => 31, 'bloodOxygen' => 97], 'blood_oxygen', ['spo2Percent' => 97]],
            // A pulseira reporta em mmol/L; o nome do campo do hub diz mg/dL e manda.
            'glicemia' => ['glicemia', ['sdkType' => 22, 'bloodGlucose' => 6.44], 'blood_sugar', ['glucoseMgDl' => 116.0]],
            'temperatura' => [
                'temperatura',
                ['sdkType' => 6, 'bodyTemperature' => 36.2, 'bodySurfaceTemperature' => 33.5],
                'temperature',
                ['bodyCelsius' => 36.2, 'surfaceCelsius' => 33.5],
            ],
            'stress' => ['stress', ['sdkType' => 58, 'pressure' => 23], 'stress', ['score' => 23]],
            'tensão' => [
                'tensão',
                ['sdkType' => 18, 'bloodPressureHigh' => 101, 'bloodPressureLow' => 74],
                'blood_pressure',
                ['systolicMmHg' => 101, 'diastolicMmHg' => 74],
            ],
            // O estado de quem a manda vibrar. Fecha o pedido: é o `expectedReplyTypes` do
            // comando, e sem ele o registo ficava à espera de uma resposta que já tinha vindo.
            'a procurar' => ['a procurar', ['sdkType' => 17, 'value' => 'search'], 'find_device', ['state' => 'searching']],
            'parada' => ['parada', ['sdkType' => 17, 'value' => 'find'], 'find_device', ['state' => 'stopped']],
            'desistiu' => ['desistiu', ['sdkType' => 17, 'value' => 'timeout'], 'find_device', ['state' => 'timed_out']],
            // O acumulado do dia, que é o que `activity` significa nos relógios. A app mostra
            // estes três números no ecrã principal, e as calorias vêm em décimas.
            'totais do dia' => [
                'totais do dia',
                ['sdkType' => 9, 'step' => 216, 'calorie' => 146, 'distance' => 187, 'day' => 'today'],
                'activity',
                ['steps' => 216, 'distanceMeters' => 187, 'caloriesKcal' => 14.6],
            ],
            // Medição real feita na app do fabricante, conferida no ecrã dela valor a valor.
            // Os nomes do hub levam a unidade; os do fabricante não distinguem percentagem
            // de quilos, e `muscleRate` ao lado de `muscleMass` obriga a adivinhar.
            'composição corporal' => [
                'composição corporal',
                // Em texto, que é como o SDK os entrega -- foi assim que chegaram do aparelho.
                [
                    'sdkType' => 32,
                    'BMI' => '28.9', 'bodyFatPercentage' => '31.9', 'fatMass' => '30.3', 'leanBodyMass' => '64.6',
                    'muscleRate' => '58.0', 'muscleMass' => '55.1', 'subcutaneousFat' => '22.4',
                    'bodyMoisture' => '54.5', 'waterContent' => '51.7', 'skeletalMuscleRate' => '33.5',
                    'boneMass' => '2.9', 'proportionOfProtein' => '12.4', 'proteinAmount' => '11.8',
                    'basalMetabolicRate' => '2235.5',
                ],
                'body_composition',
                [
                    'bmi' => 28.9, 'bodyFatPercent' => 31.9, 'fatMassKg' => 30.3, 'leanMassKg' => 64.6,
                    'musclePercent' => 58.0, 'muscleMassKg' => 55.1, 'subcutaneousFatPercent' => 22.4,
                    'bodyWaterPercent' => 54.5, 'waterMassKg' => 51.7, 'skeletalMusclePercent' => 33.5,
                    'boneMassKg' => 2.9, 'proteinPercent' => 12.4, 'proteinMassKg' => 11.8,
                    'basalMetabolicRateKcal' => 2235.5,
                ],
            ],
        ];
    }

    /** Enquanto mede, a pulseira repete a trama com zeros; zero não é composição nenhuma. */
    public function testBodyCompositionInProgressIsNotPublished(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::message([
            'sdkType' => 32, 'BMI' => 0, 'bodyFatPercentage' => 0, 'basalMetabolicRate' => 0,
        ]));

        self::assertSame([], array_values(array_filter(
            $mqtt->telemetry,
            static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'body_composition',
        )));
    }

    /**
     * @dataProvider measurements
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $expected
     */
    public function testLiveMeasurementBecomesTelemetry(
        string $_label,
        array $payload,
        string $expectedType,
        array $expected,
    ): void {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::message($payload));

        $telemetry = array_values(array_filter(
            $mqtt->telemetry,
            static fn(array $entry): bool => ($entry['payload']['type'] ?? null) === $expectedType,
        ));

        self::assertCount(1, $telemetry);
        self::assertSame($expected, $telemetry[0]['payload']['data']);
    }

    /**
     * Enquanto o sensor procura o sinal, o firmware repete a trama com o valor a zero. É
     * ausência de leitura e não uma leitura de zero -- publicá-la dava uma saturação de 0%
     * ou uma glicemia nula a meio de uma medição que estava a correr bem.
     */
    public function testZeroWhileMeasuringIsNotPublished(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $stillSearching = [
            ['sdkType' => 31, 'bloodOxygen' => 0],
            ['sdkType' => 22, 'bloodGlucose' => 0],
            ['sdkType' => 58, 'pressure' => 0],
            ['sdkType' => 6, 'bodyTemperature' => 0.0],
            ['sdkType' => 28, 'bloodPressureHigh' => 0, 'bloodPressureLow' => 0],
        ];
        foreach ($stillSearching as $payload) {
            $bridge->handleReceivedMessage(self::TOPIC, self::message($payload));
        }

        self::assertSame([], $mqtt->telemetry);
    }

    /**
     * O ECG falha por contacto e não se cala a dizê-lo.
     *
     * O firmware reporta `wearStatus: wearNotPass` e conta quedas de derivação
     * (`leadOffType`), mandando terminar a medição ao fim de quatro. Enquanto isso manda
     * dezenas de tramas seguidas com tudo a zero. O operador tem de saber porque é que o
     * exame não sai -- mas uma vez, não sessenta, ou o aviso afoga o histórico do aparelho.
     */
    public function testEcgWithoutSkinContactIsReportedOnceAndNotAsTelemetry(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        for ($i = 0; $i < 5; $i++) {
            $bridge->handleReceivedMessage(self::TOPIC, self::message([
                'sdkType' => 42,
                'wristbandStatus' => 'open',
                'wearStatus' => 'wearNotPass',
                'HR2PerMinute' => 0,
            ]));
        }

        self::assertSame([], $mqtt->telemetry);

        $failures = array_values(array_filter(
            $mqtt->events,
            static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'device.measurement_failed',
        ));

        self::assertCount(1, $failures);
        self::assertSame(['reason' => 'not_worn'], $failures[0]['payload']['error']);
    }

    /**
     * A onda de ECG chega em array e não em objeto.
     *
     * O aparelho manda quatro pacotes por segundo enquanto mede, e o gateway junta-os num
     * traçado só antes de os entregar -- uma medição é um exame, não trezentos fragmentos
     * avulsos no histórico.
     */
    public function testEcgWaveformBecomesOneTracing(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'ecg_wave',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['samples' => [12, -4, 33, 128, -71]],
        ], JSON_THROW_ON_ERROR));

        $ecg = array_values(array_filter(
            $mqtt->telemetry,
            static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'ecg',
        ));

        self::assertCount(1, $ecg);
        self::assertSame(['samples' => [12, -4, 33, 128, -71]], $ecg[0]['payload']['data']);
    }

    /**
     * Um traçado todo a zeros não é um exame.
     *
     * A pulseira grava os trinta segundos mesmo sem sinal -- pousada numa secretária devolve
     * dezasseis mil amostras a zero. Publicá-las dava um ECG no histórico de quem nunca fez
     * nenhum; o que aconteceu foi uma medição sem sinal, e é isso que o operador tem de ler.
     */
    public function testAnAllZeroTracingIsReportedAsFailureAndNotAsAnExam(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'ecg_wave',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['samples' => array_fill(0, 500, 0)],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], $mqtt->telemetry);

        $failures = array_values(array_filter(
            $mqtt->events,
            static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'device.measurement_failed',
        ));
        self::assertCount(1, $failures);
        self::assertSame(['reason' => 'no_signal'], $failures[0]['payload']['error']);
    }

    /**
     * O traçado inteiro vai para o MQTT, e para a dashboard vai só o resumo.
     *
     * São dezasseis mil amostras por exame. Quem integra quer o traçado; a dashboard guarda
     * cem entradas por aparelho e não desenha ondas -- enchê-la com o traçado completo era
     * despejar megabytes no Redis para mostrar «Dados de ECG».
     */
    public function testTheDashboardKeepsTheSummaryAndNotTheWholeTracing(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $appended = [];
        $store = $this->createMock(DashboardStoreContract::class);
        $store->method('append')->willReturnCallback(
            static function (string $imei, string $list, array $payload) use (&$appended): void {
                $appended[] = $payload;
            }
        );

        $this->bridge($mqtt, $store)->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'ecg_wave',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['samples' => [5, -3, 7, 2], 'samplingHz' => 500],
        ], JSON_THROW_ON_ERROR));

        $ecg = array_values(array_filter(
            $mqtt->telemetry,
            static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'ecg',
        ));
        self::assertSame([5, -3, 7, 2], $ecg[0]['payload']['data']['samples']);

        $shown = array_values(array_filter(
            $appended,
            static fn(array $e): bool => ($e['type'] ?? null) === 'ecg',
        ));
        self::assertCount(1, $shown);
        self::assertArrayNotHasKey('samples', $shown[0]['data']);
        self::assertSame(4, $shown[0]['data']['sampleCount']);
        self::assertSame(500, $shown[0]['data']['samplingHz']);
    }

    /** Um traçado vazio não é um exame: é uma medição que não chegou a produzir sinal. */
    public function testEmptyEcgWaveformIsNotPublished(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'ecg_wave',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['samples' => []],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], $mqtt->telemetry);
    }

    /** @param array<string, mixed> $payload */
    private static function message(array $payload): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'measurement',
            'device' => ['mac' => self::BRACELET],
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(RecordingHubMqttBridge $mqtt, ?DashboardStoreContract $store = null): Bridge
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
            null,
            $store,
        );
    }
}
