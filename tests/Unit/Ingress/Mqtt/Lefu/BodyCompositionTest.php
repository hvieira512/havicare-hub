<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Lefu;

use Hub\Ingress\Mqtt\Lefu\BodyComposition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** As fórmulas do SDK da Lefu, contra pesagens emparelhadas com a app do fabricante. */
final class BodyCompositionTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, float, float, array<string, float>}> */
    public static function pairedWeighings(): array
    {
        $hugo = ['sex' => 'male', 'ageYears' => 23, 'heightCm' => 181];

        return [
            'CF632, 96,60 kg' => [$hugo, 96.60, 379.0, [
                'bodyFatPercent' => 29.41, 'fatMassKg' => 28.41, 'bodyWaterPercent' => 50.40, 'boneMassKg' => 3.48,
                'muscleMassKg' => 64.71, 'musclePercent' => 66.99, 'leanMassKg' => 68.19, 'bmi' => 29.4,
                'subcutaneousFatPercent' => 19.61, 'fatControlKg' => -13.64, 'idealWeightKg' => 72.0,
                'muscleControlKg' => 0.0, 'weightControlKg' => -24.6,
            ]],
            'CF632, 96,75 kg' => [$hugo, 96.75, 390.8, [
                'bodyFatPercent' => 29.55, 'bodyWaterPercent' => 50.30, 'boneMassKg' => 3.48, 'musclePercent' => 66.85,
                'bmi' => 29.5, 'subcutaneousFatPercent' => 19.70, 'fatControlKg' => -13.81,
            ]],
            'CF621, 93,70 kg' => [['sex' => 'male', 'ageYears' => 60, 'heightCm' => 169], 93.70, 404.2, [
                'bodyFatPercent' => 34.60, 'fatMassKg' => 32.42, 'bodyWaterPercent' => 46.70, 'boneMassKg' => 3.12,
                'muscleMassKg' => 58.16, 'leanMassKg' => 61.28, 'bmi' => 32.8, 'subcutaneousFatPercent' => 23.07,
                'fatControlKg' => -21.30, 'idealWeightKg' => 62.8, 'weightControlKg' => -30.9,
            ]],
            'CF632, mulher, 96,80 kg' => [['sex' => 'female', 'ageYears' => 21, 'heightCm' => 168], 96.80, 370.0, [
                'bodyFatPercent' => 42.94, 'fatMassKg' => 41.56, 'bodyWaterPercent' => 40.74, 'boneMassKg' => 3.21,
                'muscleMassKg' => 52.02, 'musclePercent' => 53.74, 'leanMassKg' => 55.24, 'bmi' => 34.2,
                'subcutaneousFatPercent' => 28.62, 'fatControlKg' => -35.14, 'idealWeightKg' => 62.0,
                'muscleControlKg' => 0.0, 'weightControlKg' => -34.8,
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, float> $expected
     */
    #[DataProvider('pairedWeighings')]
    public function testMatchesTheManufacturerApp(array $user, float $weightKg, float $impedanceOhm, array $expected): void
    {
        $composition = BodyComposition::of($weightKg, $impedanceOhm, $user);

        foreach ($expected as $field => $value) {
            self::assertEqualsWithDelta($value, $composition[$field], 0.011, $field);
        }
    }

    /** O modo atleta é um algoritmo fechado da Lefu: fica só o IMC, que não depende dele. */
    public function testAnAthleteGetsOnlyTheBmi(): void
    {
        $composition = BodyComposition::of(96.95, 390.6, ['sex' => 'male', 'ageYears' => 23, 'heightCm' => 181, 'athlete' => true]);

        self::assertSame(['bmi' => 29.5], $composition);
    }

    public function testWithoutSexAgeOrHeightThereIsNoComposition(): void
    {
        self::assertNull(BodyComposition::of(80.0, 400.0, ['sex' => 'male', 'ageYears' => 40]));
    }
}
