<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Ingress\Mqtt\Veepoo\MeasurementNormalizer;
use Hub\State\DeviceStoreContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\Doubles\VeepooFixtures;

/** É aqui que o tipo do SDK, que o gateway reenvia sem interpretar, vira uma grandeza do hub. */
final class BridgeMeasurementTest extends TestCase
{
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: array<string, mixed>}>
     */
    public static function measurements(): array
    {
        return [
            'heart rate' => [['sdkType' => 51, 'heartRate' => 74], 'heart_rate', ['bpm' => 74]],
            'blood oxygen' => [['sdkType' => 31, 'bloodOxygen' => 97], 'blood_oxygen', ['spo2Percent' => 97]],
            // A pulseira reporta em mmol/L; o nome do campo do hub diz mg/dL e manda.
            'blood sugar' => [['sdkType' => 22, 'bloodGlucose' => 6.44], 'blood_sugar', ['glucoseMgDl' => 116.0]],
            'temperature' => [
                ['sdkType' => 6, 'bodyTemperature' => 36.2, 'bodySurfaceTemperature' => 33.5],
                'temperature',
                ['bodyCelsius' => 36.2, 'surfaceCelsius' => 33.5],
            ],
            'stress' => [['sdkType' => 58, 'pressure' => 23], 'stress', ['stressScore' => 23]],
            'blood pressure' => [
                ['sdkType' => 18, 'bloodPressureHigh' => 101, 'bloodPressureLow' => 74],
                'blood_pressure',
                ['systolicMmHg' => 101, 'diastolicMmHg' => 74],
            ],
            // O estado de quem a manda vibrar; fecha o pedido, porque é o `expectedReplyTypes` do comando.
            'searching' => [['sdkType' => 17, 'value' => 'search'], 'find_device', ['state' => 'searching']],
            // O acumulado do dia, que é o que `activity` significa nos relógios. A app mostra
            // estes três números no ecrã principal, e as calorias vêm em décimas.
            'daily totals' => [
                ['sdkType' => 9, 'step' => 216, 'calorie' => 146, 'distance' => 187, 'day' => 'today'],
                'activity',
                ['steps' => 216, 'distanceMeters' => 187, 'caloriesKcal' => 14.6],
            ],
            // Medição real na app do fabricante, conferida valor a valor. Os nomes do hub levam a
            // unidade: `muscleRate` ao lado de `muscleMass` obriga a adivinhar.
            'body composition' => [
                // Em texto, que é como o SDK os entrega.
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
        VeepooFixtures::bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::message([
            'sdkType' => 32, 'BMI' => 0, 'bodyFatPercentage' => 0, 'basalMetabolicRate' => 0,
        ]));

        self::assertSame([], $mqtt->ofType('body_composition'));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $expected
     */
    #[DataProvider('measurements')]
    public function testLiveMeasurementBecomesTelemetry(
        array $payload,
        string $expectedType,
        array $expected,
    ): void {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = VeepooFixtures::bridge($mqtt);
        $bridge->handleReceivedMessage(self::TOPIC, self::message($payload));

        // Uma medição sai quando o pedido que a mandou fazer se fecha; uma leitura
        // instantânea não tem pedido a fechar e já saiu.
        $operation = MeasurementNormalizer::operationForSdkType((int)$payload['sdkType']);
        if ($operation !== null) {
            $bridge->handleReceivedMessage(self::TOPIC, self::confirmation($operation));
        }

        $telemetry = $mqtt->ofType($expectedType);

        self::assertCount(1, $telemetry);
        self::assertSame($expected, $telemetry[0]['payload']['data']);

        // O `nativeType` é o tipo original do fabricante: é por ele que quem integra vai à
        // documentação da Veepoo.
        self::assertSame(
            'type-' . $payload['sdkType'],
            $telemetry[0]['payload']['source']['nativeType'],
        );
    }

    /** Enquanto o sensor procura sinal o firmware repete a trama a zero, que é ausência de leitura e não 0% de saturação. */
    public function testZeroWhileMeasuringIsNotPublished(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = VeepooFixtures::bridge($mqtt);

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

    /** Com `wearNotPass` ou `leadOffType` vêm dezenas de tramas a zero, e o operador sabe porquê uma vez, não sessenta. */
    public function testEcgWithoutSkinContactIsReportedOnceAndNotAsTelemetry(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = VeepooFixtures::bridge($mqtt);

        for ($i = 0; $i < 5; $i++) {
            $bridge->handleReceivedMessage(self::TOPIC, self::message([
                'sdkType' => 42,
                'wristbandStatus' => 'open',
                'wearStatus' => 'wearNotPass',
                'HR2PerMinute' => 0,
            ]));
        }

        self::assertSame([], $mqtt->telemetry);

        $failures = $mqtt->ofType('device.measurement_failed', 'events');

        self::assertCount(1, $failures);
        self::assertSame(['reason' => 'not_worn'], $failures[0]['payload']['error']);
    }

    /** A pulseira grava os trinta segundos mesmo sem sinal, e o que o operador tem de ler é a falta dele. */
    public function testAnAllZeroTracingIsReportedAsFailureAndNotAsAnExam(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        VeepooFixtures::bridge($mqtt)->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'ecg_wave',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['samples' => array_fill(0, 500, 0)],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], $mqtt->telemetry);

        $failures = $mqtt->ofType('device.measurement_failed', 'events');
        self::assertCount(1, $failures);
        self::assertSame(['reason' => 'no_signal'], $failures[0]['payload']['error']);
    }

    /** São dezasseis mil amostras por exame, e a dashboard guarda cem entradas por aparelho e não desenha ondas. */
    public function testTheDashboardKeepsTheSummaryAndNotTheWholeTracing(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $appended = [];
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('append')->willReturnCallback(
            static function (string $imei, string $list, array $payload) use (&$appended): void {
                $appended[] = $payload;
            }
        );

        VeepooFixtures::bridge($mqtt, store: $store)->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'ecg_wave',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['samples' => [5, -3, 7, 2], 'samplingHz' => 500],
        ], JSON_THROW_ON_ERROR));

        $ecg = $mqtt->ofType('ecg');
        self::assertSame([5, -3, 7, 2], $ecg[0]['payload']['data']['samples']);

        $shown = array_values(array_filter(
            $appended,
            static fn(array $e): bool => ($e['type'] ?? null) === 'ecg',
        ));
        self::assertCount(1, $shown);
        self::assertArrayNotHasKey('samples', $shown[0]['data']);
        self::assertSame(4, $shown[0]['data']['sampleCount']);
        // `frequencyHz` e não `samplingHz`: é o nome que o contrato e os relógios usam para a
        // mesma coisa, e dois nomes obrigavam quem integra a tratar por dois o que é um.
        self::assertSame(500, $shown[0]['data']['frequencyHz']);
    }

    /** Um traçado vazio não é um exame: é uma medição que não chegou a produzir sinal. */
    public function testEmptyEcgWaveformIsNotPublished(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        VeepooFixtures::bridge($mqtt)->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'ecg_wave',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['samples' => []],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], $mqtt->telemetry);
    }

    /** O firmware repete a trama a cada segundo enquanto mede, e o resultado é o valor em que ela assenta. */
    public function testAMeasurementYieldsTheValueItSettledOn(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = VeepooFixtures::bridge($mqtt);

        foreach ([0, 79, 85, 90, 91, 90] as $bpm) {
            $bridge->handleReceivedMessage(self::TOPIC, self::message(['sdkType' => 51, 'heartRate' => $bpm]));
        }

        self::assertSame([], $mqtt->telemetry, 'enquanto mede, a medição ainda não é um resultado');

        $bridge->handleReceivedMessage(self::TOPIC, self::confirmation('measure.heartRate.start'));

        $readings = $mqtt->ofType('heart_rate');
        self::assertCount(1, $readings);
        self::assertSame(90, $readings[0]['payload']['data']['bpm']);
    }

    /** O valor sai mesmo sem confirmação, com um prazo acima da medição mais lenta, a composição corporal, de dois minutos. */
    public function testAReadingIsNotLostWhenTheConfirmationNeverArrives(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $now = 1000;
        $bridge = VeepooFixtures::bridge($mqtt, clock: static function () use (&$now): float {
            return (float)$now;
        });

        $bridge->handleReceivedMessage(self::TOPIC, self::message(['sdkType' => 51, 'heartRate' => 90]));
        $bridge->dispatchQueued();
        self::assertSame([], $mqtt->telemetry);

        $now += 200;
        $bridge->dispatchQueued();

        $readings = $mqtt->ofType('heart_rate');
        self::assertCount(1, $readings);
        self::assertSame(90, $readings[0]['payload']['data']['bpm']);
    }

    private static function confirmation(string $operation): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'command_result',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['dedupeKey' => $operation . '-key', 'operation' => $operation],
        ], JSON_THROW_ON_ERROR);
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
}
