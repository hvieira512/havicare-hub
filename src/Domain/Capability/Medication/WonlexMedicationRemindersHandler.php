<?php

namespace Hub\Domain\Capability\Medication;

use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Wonlex strategy for medication reminders.
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
            if ($plans === []) {
                throw new \InvalidArgumentException('plans must contain at least one medication plan');
            }
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

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }
}
