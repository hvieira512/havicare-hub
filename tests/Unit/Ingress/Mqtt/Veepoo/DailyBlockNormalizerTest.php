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
        self::assertSame(['count' => 0, 'periodSeconds' => 300], $byType['steps']);
    }

    public function testEmptyBlockFromAnUnwornBandProducesOnlyStepsAndWearState(): void
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

        self::assertSame(['steps', 'wear_state'], array_column($out, 'type'));
        self::assertSame(['state' => 'not_worn'], self::ofType($out, 'wear_state')[0]['data']);
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

        // Os cinquenta R-R vão numa mensagem só, e a posição na lista é o instante: são
        // cinquenta lugares a cobrir os cinco minutos do bloco, um de seis em seis segundos.
        // O fabricante chama `RR2Per6Second` ao campo equivalente do modo de teste.
        //
        // A cadência não leva campo próprio: sai dos instantes, e dizê-la duas vezes era
        // arriscar que um dia discordassem.
        self::assertCount(1, $byType['rr_interval']);
        self::assertSame('2026-09-09T03:20:00Z', $byType['rr_interval'][0]['occurredAt']);
        self::assertSame(
            [
                'intervals' => [
                    ['timestamp' => '2026-09-09T03:20:00Z', 'milliseconds' => 810],
                    ['timestamp' => '2026-09-09T03:20:12Z', 'milliseconds' => 790],
                ],
            ],
            $byType['rr_interval'][0]['data'],
        );
    }

    /**
     * A pulseira diz em cada bloco se estava a ser usada, e isso é telemetria por si.
     *
     * Sem ela, um bloco de zeros por estar na mesinha de cabeceira é indistinguível de um
     * bloco de zeros de alguém sentado -- e são a mesma leitura com significados opostos.
     * O javadoc do fabricante chama-lhe bits de bandeira e não documenta a tabela; num dia
     * inteiro de captura só apareceram `0` com a pulseira ao pulso e `6` com ela fora dele.
     */
    public function testWearStateSaysWhetherTheBandWasOnTheWrist(): void
    {
        $worn = (new DailyBlockNormalizer())->normalize(
            ['date' => '2026-09-09-09-40', 'step' => ['stepCount' => 37, 'wear' => 0]],
            self::DEVICE,
            'bef341903987',
        );
        $off = (new DailyBlockNormalizer())->normalize(
            ['date' => '2026-09-09-03-20', 'step' => ['stepCount' => 0, 'wear' => 6]],
            self::DEVICE,
            'bef341903987',
        );

        self::assertSame(['state' => 'worn'], self::ofType($worn, 'wear_state')[0]['data']);
        self::assertSame(['state' => 'not_worn'], self::ofType($off, 'wear_state')[0]['data']);
    }

    /**
     * Cada bloco traz o seu estado de uso, mesmo quando é igual ao anterior.
     *
     * Colapsar repetições era o hub a decidir o que vale a pena dizer, e a mudar o
     * significado do silêncio: deixava de se distinguir «não mudou» de «não houve leitura».
     * Quem consome é que compara com o que leu da vez anterior.
     */
    public function testEveryBlockCarriesItsOwnWearState(): void
    {
        $n = new DailyBlockNormalizer();
        $states = [];
        foreach ([['08-50', 6], ['08-55', 1], ['09-00', 1], ['09-05', 0], ['09-10', 0]] as [$at, $flag]) {
            $out = $n->normalize(
                ['date' => "2026-09-09-{$at}", 'step' => ['stepCount' => 0, 'wear' => $flag]],
                self::DEVICE,
                'bef341903987',
            );
            foreach (self::ofType($out, 'wear_state') as $e) {
                $states[] = substr($e['occurredAt'], 11, 5) . ' ' . $e['data']['state'];
            }
        }

        self::assertSame([
            '08:50 not_worn',
            '08:55 not_worn',
            '09:00 not_worn',
            '09:05 worn',
            '09:10 worn',
        ], $states);
    }

    /**
     * A bandeira a zero é a única que diz «ao pulso»; os outros códigos são razões.
     *
     * `1` apanhou-se nos dois blocos em que a pulseira estava a ser calçada, entre um `6` de
     * noite inteira fora do pulso e o `0` do bloco seguinte, já com movimento. `2` veio de
     * uma captura anterior com ela pousada na secretária. Nenhum deles traz leitura ótica.
     */
    public function testEveryNonZeroWearFlagMeansNotWorn(): void
    {
        foreach ([1, 2, 6] as $flag) {
            $out = (new DailyBlockNormalizer())->normalize(
                ['date' => '2026-09-09-09-40', 'step' => ['stepCount' => 0, 'wear' => $flag]],
                self::DEVICE,
                'bef341903987',
            );

            self::assertSame(['state' => 'not_worn'], self::ofType($out, 'wear_state')[0]['data'], "wear={$flag}");
        }
    }

    /**
     * O bloco conta passos numa janela, e é só isso que ele mede.
     *
     * A distância e as calorias do bloco são o número de passos vezes uma constante -- em
     * quatrocentos e dezasseis blocos capturados, 0,86 m e 0,067 kcal por passo, e zero
     * sempre que os passos são zero. Publicá-las era dizer a mesma medição três vezes.
     *
     * O acumulado do dia é outra coisa e tem tipo próprio: aqui vai o que se andou nestes
     * cinco minutos, e a janela viaja com o valor para ninguém ter de a adivinhar.
     */
    public function testABlockCountsStepsOverItsWindow(): void
    {
        $out = (new DailyBlockNormalizer())->normalize(
            ['date' => '2026-09-09-09-40', 'step' => ['stepCount' => 37, 'distance' => 32, 'calorie' => 25]],
            self::DEVICE,
            'bef341903987',
        );

        self::assertSame(['count' => 37, 'periodSeconds' => 300], self::ofType($out, 'steps')[0]['data']);
        self::assertSame([], self::ofType($out, 'activity'));
    }

    /** Zero passos é uma leitura verdadeira; o bloco sem contagem nenhuma é que não é. */
    public function testABlockWithoutAStepCountProducesNoSteps(): void
    {
        $out = (new DailyBlockNormalizer())->normalize(
            ['date' => '2026-09-09-09-40', 'step' => ['wear' => 0]],
            self::DEVICE,
            'bef341903987',
        );

        self::assertSame([], self::ofType($out, 'steps'));
        self::assertSame(['state' => 'worn'], self::ofType($out, 'wear_state')[0]['data']);
    }

    /** @return list<array<string, mixed>> */
    private static function ofType(array $out, string $type): array
    {
        return array_values(array_filter($out, static fn(array $e): bool => $e['type'] === $type));
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
     * O bloco traz os dois valores, e são o que os nomes dizem.
     *
     * Captura da MF91 ao pulso: 36,2 °C e 34,0 °C às 09:40, 36,6 e 35,0 às 10:40 -- e a app
     * do fabricante mostra 36,2 °C como temperatura corporal nesse mesmo minuto. Os nomes
     * são os dos relógios, que é onde o contrato já os tinha.
     */
    public function testBlockTemperatureCarriesBodyAndSurface(): void
    {
        $out = (new DailyBlockNormalizer())->normalize(
            ['date' => '2026-09-09-09-40', 'bodyTemperature' => ['bodyTemperature' => '36.2', 'bodySurfaceTemperature' => '34.0']],
            self::DEVICE,
            'bef341903987',
        );

        self::assertSame('temperature', $out[0]['type']);
        self::assertSame(['bodyCelsius' => 36.2, 'surfaceCelsius' => 34.0], $out[0]['data']);
    }

    /** Um dos dois pode faltar, e o sentinela é `0.0` em ambos. */
    public function testTemperatureKeepsWhicheverValueWasMeasured(): void
    {
        $out = (new DailyBlockNormalizer())->normalize(
            ['date' => '2026-09-09-09-45', 'bodyTemperature' => ['bodyTemperature' => '36.4', 'bodySurfaceTemperature' => '0.0']],
            self::DEVICE,
            'bef341903987',
        );

        self::assertSame(['bodyCelsius' => 36.4], $out[0]['data']);
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
