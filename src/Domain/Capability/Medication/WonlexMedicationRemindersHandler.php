<?php

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
            return ['dnMedicationPlan' => ['plans' => array_values($plans)]];
        }

        return ['dnMedicationPlan' => ['plan' => $value['plan'] ?? $value]];
    }

    public function fromNative(array $desired): mixed
    {
        if (isset($desired['plans']) && is_array($desired['plans'])) {
            return ['plans' => array_values(array_filter($desired['plans'], 'is_array'))];
        }
        $plan = $desired['plan'] ?? $desired;
        return is_array($plan) ? ['plans' => [$plan]] : ['plans' => []];
    }

    public function defaultValue(): mixed
    {
        return ['plans' => []];
    }

    public function meta(array $accumulatedMeta = []): array
    {
        return $accumulatedMeta;
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
