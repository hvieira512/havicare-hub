<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Ingress\Mqtt\Veepoo\DailyBlockNormalizer;
use PHPUnit\Framework\TestCase;

final class DailyBlockNormalizerTest extends TestCase
{
    private const DEVICE = ['id' => 'dba376003185', 'supplier' => 'Wonlex', 'model' => 'MF91'];

    public function testEachMinuteBecomesItsOwnReading(): void
    {
        $out = (new DailyBlockNormalizer())->normalize([
            'date' => '2026-09-08-10-05',
            'pulseReat' => [72, 74, 0, 76, 255],
        ], self::DEVICE, 'bef341903987');

        $heart = array_values(array_filter($out, static fn(array $e): bool => $e['type'] === 'heart_rate'));

        // 0 e 255 são sentinelas de «não medido» e não podem virar batimentos.
        self::assertCount(3, $heart);
        self::assertSame(['bpm' => 72], $heart[0]['data']);
        self::assertSame('2026-09-08T10:05:00Z', $heart[0]['occurredAt']);
        self::assertSame('2026-09-08T10:06:00Z', $heart[1]['occurredAt']);
        self::assertSame('2026-09-08T10:08:00Z', $heart[2]['occurredAt']);
        self::assertSame(self::DEVICE, $heart[0]['device']);
        self::assertSame('veepoo-ble', $heart[0]['source']['protocol']);
    }

    public function testBloodPressureAndActivity(): void
    {
        $out = (new DailyBlockNormalizer())->normalize([
            'date' => '2026-09-08-10-05',
            'bloodPressure' => ['bloodPressureHigh' => 118, 'bloodPressureLow' => 76],
            'step' => ['stepCount' => 0, 'distance' => 0, 'calorie' => 0, 'wear' => 2],
        ], self::DEVICE, 'bef341903987');

        $byType = [];
        foreach ($out as $e) {
            $byType[$e['type']] = $e['data'];
        }

        self::assertSame(['systolicMmHg' => 118, 'diastolicMmHg' => 76], $byType['blood_pressure']);
        // Zero passos é uma leitura verdadeira, ao contrário de zero batimentos.
        self::assertSame(['steps' => 0, 'distanceMeters' => 0, 'caloriesKcal' => 0], $byType['activity']);
    }

    public function testEmptyBlockFromAnUnwornBandProducesNothingButActivity(): void
    {
        // Captura real da MF91 pousada na secretária.
        $out = (new DailyBlockNormalizer())->normalize([
            'currentPackageNum' => 1,
            'date' => '2026-09-08-00-05',
            'step' => ['stepCount' => 0, 'amountOfExercise' => 0, 'distance' => 0, 'calorie' => 0, 'wear' => 2],
            'sleepData' => [0, 0, 0, 0, 0, 255],
            'pulseReat' => [0, 0, 0, 0, 0],
            'respirationRate' => [255, 255, 255, 255, 255],
            'HRVData' => [0, 0, 0, 0, 0],
            'bloodPressure' => ['bloodPressureHigh' => 0, 'bloodPressureLow' => 0],
        ], self::DEVICE, 'bef341903987');

        self::assertSame(['activity'], array_column($out, 'type'));
    }

    public function testRrIntervalsCarryTheBlockTimestamp(): void
    {
        $out = (new DailyBlockNormalizer())->normalize([
            'date' => '2026-09-09-03-20',
            // Como o firmware os envia: unidades de dez milissegundos, com 0 e 255 a marcar
            // as posições sem leitura.
            'rr50' => [81, 0, 79, 255],
        ], self::DEVICE, 'bef341903987');

        $byType = [];
        foreach ($out as $e) {
            $byType[$e['type']][] = $e;
        }

        // Os cinquenta R-R vão numa mensagem só: são uma amostra dentro do bloco e a posição
        // na lista não diz o instante, por isso partilham o carimbo do bloco.
        self::assertCount(1, $byType['rr_interval']);
        self::assertSame('2026-09-09T03:20:00Z', $byType['rr_interval'][0]['occurredAt']);
        self::assertSame(
            ['intervals' => [['milliseconds' => 810], ['milliseconds' => 790]]],
            $byType['rr_interval'][0]['data'],
        );
    }

    /**
     * O MET tem uma casa decimal implícita e o stress não.
     *
     * Publicar o inteiro em cru dava nove equivalentes metabólicos a quem está sentado a uma
     * secretária -- corrida a bom ritmo. A app do fabricante mostra 0,9 para o mesmo bloco.
     */
    public function testMetIsScaledButStressIsNot(): void
    {
        $out = (new DailyBlockNormalizer())->normalize([
            'date' => '2026-09-09-09-00',
            'meiTuo' => [9],
            'pressure' => [39],
        ], self::DEVICE, 'bef341903987');

        $byType = array_column($out, 'data', 'type');

        self::assertSame(['value' => 0.9], $byType['met']);
        self::assertSame(['score' => 39], $byType['stress']);
    }

    /**
     * O bloco traz `sleepData` e o hub não o publica.
     *
     * A documentação promete seis estados de sono; num histórico de 733 blocos o firmware só
     * devolveu 0, 112, 136, 144, 200 e 208, e a distribuição é a mesma de manhã e de tarde --
     * não distingue sequer o dia da noite. Traduzi-los para acordado ou sono profundo era
     * inventar, e é por isso que este bloco não produz nada.
     */
    public function testSleepCodesAreNotPublishedWhileTheirMeaningIsUnknown(): void
    {
        $out = (new DailyBlockNormalizer())->normalize([
            'date' => '2026-09-09-03-20',
            'sleepData' => [136, 112, 136, 144, 136, 0],
        ], self::DEVICE, 'bef341903987');

        self::assertSame([], array_column($out, 'type'));
    }

    public function testDeviceLocalTimeIsConvertedToUtc(): void
    {
        // A pulseira carimba na hora que o gateway lhe acertou. Em Portugal, no verão, são
        // mais 60 minutos do que UTC -- ler o carimbo como UTC punha o bloco no futuro.
        $out = (new DailyBlockNormalizer())->normalize(
            ['date' => '2026-09-09-11-05', 'pulseReat' => [70]],
            self::DEVICE,
            'bef341903987',
            60,
        );

        self::assertSame('2026-09-09T10:05:00Z', $out[0]['occurredAt']);
    }

    /**
     * O bloco não traz temperatura corporal, por mais que o campo se chame assim.
     *
     * Uma medição a pedido na mesma pulseira e no mesmo minuto devolveu 36,0 °C de corpo e
     * 33,2 °C de superfície; o bloco, para o mesmo instante, traz 33,5 e 26,8. O valor que
     * ele rotula de corporal é o da pele, e o outro é mais frio ainda. Publicar 33,5 °C como
     * temperatura do corpo mostraria hipotermia grave a quem está bem.
     */
    public function testBlockTemperatureIsReportedAsSkinAndNotAsBody(): void
    {
        // Captura real da MF91 ao pulso, às 09:40.
        $out = (new DailyBlockNormalizer())->normalize(
            ['date' => '2026-09-09-09-40', 'bodyTemperature' => ['bodyTemperature' => '33.4', 'bodySurfaceTemperature' => '24.8']],
            self::DEVICE,
            'bef341903987',
        );

        self::assertSame('temperature', $out[0]['type']);
        self::assertSame(['skinCelsius' => 33.4], $out[0]['data']);
    }

    public function testUnmeasuredTemperatureIsDiscarded(): void
    {
        // `0.0` é o sentinela de não medido, e vem em texto como os valores verdadeiros.
        $out = (new DailyBlockNormalizer())->normalize(
            ['date' => '2026-09-09-00-05', 'bodyTemperature' => ['bodyTemperature' => '0.0', 'bodySurfaceTemperature' => '0.0']],
            self::DEVICE,
            'bef341903987',
        );

        self::assertSame([], $out);
    }

    public function testBloodOxygenAndGlucoseAreExtracted(): void
    {
        $out = (new DailyBlockNormalizer())->normalize([
            'date' => '2026-09-09-14-00',
            'bloodOxygen' => ['oxygens' => [97, 98, 0, 255, 96], 'apneaResults' => [0, 0]],
            'bloodGlucose' => ['bloodGlucose' => 5.43, 'level' => 2],
        ], self::DEVICE, 'bef341903987');

        $oxygen = array_values(array_filter($out, static fn(array $e): bool => $e['type'] === 'blood_oxygen'));
        $sugar = array_values(array_filter($out, static fn(array $e): bool => $e['type'] === 'blood_sugar'));

        // Cinco leituras por minuto, menos os dois sentinelas.
        self::assertCount(3, $oxygen);
        self::assertSame(['spo2Percent' => 97], $oxygen[0]['data']);
        self::assertSame('2026-09-09T14:04:00Z', $oxygen[2]['occurredAt']);
        // O nível de risco do firmware sai como enumeração inglesa, não como número.
        // O hub publica em mg/dL; a pulseira dá mmol/L, logo converte-se. 5,43 × 18,016 ≈ 97,8.
        self::assertSame(['glucoseMgDl' => 97.8, 'riskLevel' => 'medium'], $sugar[0]['data']);
    }

    public function testDerivedFieldsBecomeTheirOwnCapabilities(): void
    {
        $out = (new DailyBlockNormalizer())->normalize([
            'date' => '2026-09-09-14-00',
            'bloodOxygen' => ['oxygens' => [], 'apneaResults' => [2, 1, 255], 'hypoxiaTimes' => [40, 20], 'cardiacLoads' => [7]],
            'pressure' => [31, 0, 28],
            'meiTuo' => [4],
            'bloodLiquid' => ['cholesterol' => '4.2', 'triacylglycerol' => '1.1', 'highDensity' => 0, 'lowDensity' => '2.4', 'uricAcidVal' => '327'],
        ], self::DEVICE, 'bef341903987');

        $byType = [];
        foreach ($out as $e) {
            $byType[$e['type']][] = $e['data'];
        }

        // Contagens do bloco inteiro: somadas, com os sentinelas fora.
        self::assertSame(['episodes' => 3, 'hypoxiaSeconds' => 60], $byType['sleep_apnea'][0]);
        self::assertSame(['value' => 7], $byType['cardiac_load'][0]);
        // Stress e MET são por minuto, como as grandezas vitais.
        self::assertSame([['score' => 31], ['score' => 28]], $byType['stress']);
        self::assertSame('2026-09-09T14:02:00Z', $out[array_search(['score' => 28], array_column($out, 'data'), true)]['occurredAt']);
        // O ácido úrico sai à parte dos lípidos: não é um deles e tem unidade própria.
        self::assertSame(['totalCholesterolMmolPerL' => 4.2, 'triglyceridesMmolPerL' => 1.1, 'ldlMmolPerL' => 2.4], $byType['blood_lipids'][0]);
        self::assertSame(['umolPerL' => 327.0], $byType['uric_acid'][0]);
    }

    public function testBlockWithoutAUsableDateIsDiscarded(): void
    {
        self::assertSame([], (new DailyBlockNormalizer())->normalize(
            ['date' => '', 'pulseReat' => [72]],
            self::DEVICE,
            'bef341903987',
        ));
    }
}
