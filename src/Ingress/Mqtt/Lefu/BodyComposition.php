<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Lefu;

/**
 * A composição corporal pela impedância de 100 kHz, com as fórmulas do SDK da Lefu (`CalculateUtil`
 * e `CalculateHelper4TwoChannel`). O metabolismo, a gordura visceral, a proteína, o músculo
 * esquelético, a pontuação e a idade corporal só existem na biblioteca nativa licenciada, e não saem.
 */
final class BodyComposition
{
    /**
     * @param array<string, mixed> $user `sex`, `ageYears` e `heightCm` do utilizador da balança
     * @return array<string, float>|null
     */
    public static function of(float $weightKg, float $impedanceOhm, array $user): ?array
    {
        $age = (int)($user['ageYears'] ?? 0);
        $height = (float)($user['heightCm'] ?? 0);
        $sex = $user['sex'] ?? null;
        if ($age <= 0 || $height <= 0 || $weightKg <= 0 || !in_array($sex, ['male', 'female'], true)) {
            return null;
        }
        $male = $sex === 'male';
        $w = $weightKg;
        $metres = $height / 100;
        $bmi = max(10, floor($w / ($metres * $metres) * 10) / 10);
        // O modo atleta é um algoritmo fechado da biblioteca nativa: só o IMC não depende dele.
        if (($user['athlete'] ?? false) === true) {
            return ['bmi' => $bmi];
        }

        $c0 = $height * 9.058 / 100 * $metres + $w * 0.32 + 12.226 - $impedanceOhm * 0.0068 - $age * 0.0542;
        $sub = $male ? 0.8 : ($age <= 49 ? 9.25 : 7.25);
        $c = 1.0;
        if ($male && $w < 61) {
            $c = 0.98;
        } elseif (!$male && ($w > 60 || $w < 50)) {
            $c = ($w > 60 ? 0.96 : 1.02) * ($height > 160 ? 1.03 : 1);
        }
        $fat = (1 - ($c0 - $sub) * $c / $w) * 100;
        $fat = $fat > 63 ? 75.0 : $fat;

        $bone = -(($male ? 0.18016894 : 0.245691014) - $c0 * 0.05158);
        $bone += $bone > 2.2 ? 0.1 : -0.1;
        if ($bone > ($male ? 5.2 : 5.1)) {
            $bone = 8.0;
        }

        $water = (100 - $fat) * 0.7;
        $water *= $water < 50 ? 1.02 : 0.98;

        $fatKg = $w * $fat / 100;
        $lean = $w - $fatKg;
        $muscleKg = $lean - $bone;
        // A app trunca o peso ideal a uma casa, e os controlos partem do valor truncado.
        $ideal = floor(22 * $metres * $metres * 10) / 10;

        return array_map(static fn (float $value): float => round($value, 2), [
            'bmi' => $bmi,
            'bodyFatPercent' => $fat,
            'fatMassKg' => $fatKg,
            'leanMassKg' => $lean,
            'muscleMassKg' => $muscleKg,
            'musclePercent' => $muscleKg / $w * 100,
            'subcutaneousFatPercent' => $fat * 2 / 3,
            'bodyWaterPercent' => $water,
            'boneMassKg' => $bone,
            'idealWeightKg' => $ideal,
            'fatControlKg' => self::fatControl($male, $bmi, $fatKg, $age),
            'muscleControlKg' => max(0, $ideal * ($male ? 0.797 : 0.724) - $muscleKg),
            'weightControlKg' => $ideal - $w,
        ]);
    }

    private static function fatControl(bool $male, float $bmi, float $fatKg, int $age): float
    {
        if ($male && $bmi <= 22.5) {
            return $fatKg < 8.5 ? 9.0 - $fatKg : 0.0;
        }
        if (!$male && $bmi <= 21) {
            return $fatKg < 10.5 ? 10.2 - $fatKg : 0.0;
        }
        [$f, $f2, $f3, $f4, $f5] = $male
            ? [24.1589, -0.6282, -0.5865, 9.8772, -0.368]
            : [67.2037, 0.6351, -2.6706, -18.1146, -0.2374];

        return $f5 * $bmi * $bmi / $age + $age * $f4 / $bmi + $bmi * $f3 + $age * $f2 + $f;
    }
}
