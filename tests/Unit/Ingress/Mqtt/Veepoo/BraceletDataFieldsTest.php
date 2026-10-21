<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Ingress\Mqtt\Veepoo\DailyBlockNormalizer;
use Hub\Ingress\Mqtt\Veepoo\MeasurementNormalizer;
use PHPUnit\Framework\TestCase;

/** Os nomes do `data` de cada tipo que a pulseira publica: são contrato, e mudar um é revisão. */
final class BraceletDataFieldsTest extends TestCase
{
    public function testEveryBlockQuantityNamesWhatItCounts(): void
    {
        $out = (new DailyBlockNormalizer())->normalize([
            'date' => '2026-09-09-03-20',
            'HRVData' => [42],
            'rr50' => [81],
            'meiTuo' => [9],
            'pressure' => [33],
            'bloodOxygen' => ['cardiacLoads' => [4, 5]],
            'step' => ['stepCount' => 37],
        ], ['id' => 'dba376003185'], 'bef341903987');

        self::assertSame([
            'hrv' => ['hrvMilliseconds' => 42],
            'rr_interval' => ['intervals' => [['measuredAt' => '2026-09-09T03:20:00Z', 'rrIntervalMilliseconds' => 810]]],
            'cardiac_load' => ['cardiacLoad' => 9],
            'stress' => ['stressScore' => 33],
            'met' => ['met' => 0.9],
            'steps' => ['steps' => 37, 'periodSeconds' => 300],
        ], array_column($out, 'data', 'type'));
    }

    public function testAStressMeasurementCarriesTheSameName(): void
    {
        self::assertSame(['stress', ['stressScore' => 40]], MeasurementNormalizer::forSdkType(58, ['pressure' => 40]));
    }
}
