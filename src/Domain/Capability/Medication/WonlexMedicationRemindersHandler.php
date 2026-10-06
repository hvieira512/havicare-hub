<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Medication;

use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Estratégia do Wonlex para os lembretes de medicação.
 */
final class WonlexMedicationRemindersHandler implements MedicationRemindersHandler
{
    use CapabilityHelpers;

    public function nativeKey(): string
    {
        return 'dnMedicationPlan';
    }

    public function toNative(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('medication plan must be an object');
        }
        $plans = $value['plans'] ?? null;
        if ($plans !== null) {
            $plans = self::requireListValue($plans, 'plans');
            foreach ($plans as $plan) {
                if (!is_array($plan)) {
                    throw new \InvalidArgumentException('plans items must be objects');
                }
            }
            return ['dnMedicationPlan' => ['plans' => array_map(self::nativePlan(...), $plans)]];
        }

        return ['dnMedicationPlan' => ['plan' => $value['plan'] ?? $value]];
    }

    /**
     * As horas do plano voltam aos quatro períodos do dia que a Wonlex nomeia. Uma hora que
     * não caia num período não tem onde ir, e por isso a ordem dos `times` é a dos períodos.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function nativePlan(array $plan): array
    {
        if (!array_key_exists('times', $plan)) {
            return $plan;
        }

        $alarmClock = [];
        $checkboxes = [];
        foreach (is_array($plan['times']) ? $plan['times'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $index = array_search((string)($entry['period'] ?? ''), MedicationPlanShape::PERIODS, true);
            if ($index === false) {
                continue;
            }
            $alarmClock[MedicationPlanShape::WONLEX_PERIODS[$index]] = (string)($entry['time'] ?? '');
            $checkboxes[] = $index;
        }

        return [
            'drugType' => (int)MedicationPlanShape::codeOf(
                MedicationPlanShape::CONDITIONS,
                $plan['condition'] ?? null,
                0,
            ),
            'drugName' => (string)($plan['name'] ?? ''),
            'drugDose' => (float)($plan['doseCount'] ?? 0),
            'drugUnit' => (string)MedicationPlanShape::codeOf(
                MedicationPlanShape::DOSE_UNITS,
                $plan['doseUnit'] ?? null,
                '5',
            ),
            'drugStartTime' => (string)($plan['startDate'] ?? ''),
            'drugEndTime' => (string)($plan['endDate'] ?? ''),
            'drugInterval' => (float)($plan['intervalDays'] ?? 1),
            'drugTime' => [
                'alarmClock' => $alarmClock,
                'checkboxes' => $checkboxes,
                'radio' => (int)MedicationPlanShape::codeOf(
                    MedicationPlanShape::MEAL_TIMINGS,
                    $plan['mealTiming'] ?? null,
                    0,
                ),
            ],
        ];
    }

    public function fromNative(array $desired): mixed
    {
        $plans = $desired['plans'] ?? null;
        if (!is_array($plans)) {
            $plan = $desired['plan'] ?? $desired;
            $plans = is_array($plan) && $plan !== [] ? [$plan] : [];
        }

        return ['plans' => array_values(array_map(
            self::publicPlan(...),
            array_filter($plans, 'is_array'),
        ))];
    }

    /**
     * Um plano nativo da Wonlex é um medicamento com até quatro períodos do dia. Cada
     * período escolhido passa a uma hora do plano.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function publicPlan(array $plan): array
    {
        if (array_key_exists('times', $plan)) {
            return $plan;
        }

        $drugTime = is_array($plan['drugTime'] ?? null) ? $plan['drugTime'] : [];
        $alarmClock = is_array($drugTime['alarmClock'] ?? null) ? $drugTime['alarmClock'] : [];
        $selected = is_array($drugTime['checkboxes'] ?? null) ? $drugTime['checkboxes'] : [];
        if ($selected === []) {
            $selected = array_keys(array_filter(
                MedicationPlanShape::WONLEX_PERIODS,
                static fn(string $key): bool => trim((string)($alarmClock[$key] ?? '')) !== '',
            ));
        }

        $times = [];
        foreach ($selected as $index) {
            $key = MedicationPlanShape::WONLEX_PERIODS[(int)$index] ?? null;
            $time = $key === null ? '' : MedicationPlanShape::time((string)($alarmClock[$key] ?? ''));
            if ($time !== '') {
                $times[] = MedicationPlanShape::timeEntry($time, true, ['kind' => 'daily'], [
                    'period' => MedicationPlanShape::PERIODS[(int)$index] ?? null,
                ]);
            }
        }

        return MedicationPlanShape::plan([
            'name' => trim((string)($plan['drugName'] ?? '')),
            'condition' => MedicationPlanShape::nameOf(
                MedicationPlanShape::CONDITIONS,
                array_key_exists('drugType', $plan) ? (int)$plan['drugType'] : null,
            ),
            'doseCount' => array_key_exists('drugDose', $plan) ? (float)$plan['drugDose'] : null,
            'doseUnit' => MedicationPlanShape::nameOf(
                MedicationPlanShape::DOSE_UNITS,
                $plan['drugUnit'] ?? null,
            ),
            'startDate' => trim((string)($plan['drugStartTime'] ?? '')),
            'endDate' => trim((string)($plan['drugEndTime'] ?? '')),
            'intervalDays' => array_key_exists('drugInterval', $plan) ? (float)$plan['drugInterval'] : null,
            'mealTiming' => MedicationPlanShape::nameOf(
                MedicationPlanShape::MEAL_TIMINGS,
                (int)($drugTime['radio'] ?? $plan['mealTiming'] ?? -1),
            ),
        ], $times);
    }

    public function defaultValue(): mixed
    {
        return ['plans' => []];
    }

    public function meta(array $accumulatedMeta = []): array
    {
        return array_replace_recursive([
            'limit' => 10,
            'condition' => ['options' => [
                ['value' => 'hypertension', 'label' => 'Hipertensão'],
                ['value' => 'diabetes', 'label' => 'Diabetes'],
                ['value' => 'cholesterol', 'label' => 'Colesterol / lípidos'],
                ['value' => 'uric_acid', 'label' => 'Ácido úrico elevado'],
            ]],
            'doseUnit' => ['options' => [
                ['value' => 'tablet', 'label' => 'Comprimido / unidade'],
                ['value' => 'ampoule', 'label' => 'Ampola'],
                ['value' => 'ml', 'label' => 'ml'],
                ['value' => 'mg', 'label' => 'mg'],
                ['value' => 'iu', 'label' => 'UI'],
                ['value' => 'other', 'label' => 'Outra'],
            ]],
            'mealTiming' => ['options' => [
                ['value' => 'before_meal', 'label' => 'Antes da refeição'],
                ['value' => 'after_meal', 'label' => 'Depois da refeição'],
            ]],
            'period' => ['options' => [
                ['value' => 'morning', 'label' => 'Manhã', 'defaultTime' => '08:00'],
                ['value' => 'midday', 'label' => 'Meio-dia', 'defaultTime' => '12:00'],
                ['value' => 'night', 'label' => 'Noite', 'defaultTime' => '19:00'],
                ['value' => 'before_sleep', 'label' => 'Antes de dormir', 'defaultTime' => '22:00'],
            ]],
            'intervalDays' => ['supported' => true, 'label' => 'Intervalo em dias'],
        ], $accumulatedMeta);
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return self::mergeAssociativeValues($existing, $incoming);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return [
            'value' => $value,
            '_meta' => $meta,
        ];
    }
}
