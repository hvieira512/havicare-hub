<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Medication;

use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Estratégia do dispensador M228 para o plano de medicação.
 *
 * A chave nativa é a genérica: ao contrário dos relógios, cujo plano viaja dentro de um
 * comando com nome próprio (`dnMedicationPlan`, `takePills`), o dispensador declara a
 * configuração já pela chave do contrato, e é o `command` da definição que monta a trama.
 */
final class PillDispenserMedicationRemindersHandler implements MedicationRemindersHandler
{
    use CapabilityHelpers;

    /** O aparelho tem nove alarmes fixos. */
    private const SLOTS = 9;

    public function nativeKey(): string
    {
        return 'medication_reminders';
    }

    public function toNative(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('medication plan must be an object');
        }

        $plans = self::requireListValue($value['plans'] ?? [], 'plans');
        if (count($plans) > self::SLOTS) {
            throw new \InvalidArgumentException('the M228 has ' . self::SLOTS . ' alarms');
        }
        foreach ($plans as $plan) {
            if (!is_array($plan)) {
                throw new \InvalidArgumentException('plans items must be objects');
            }
        }

        return ['medication_reminders' => ['plans' => array_map(self::nativePlan(...), $plans)]];
    }

    public function fromNative(array $desired): mixed
    {
        $plans = is_array($desired['plans'] ?? null) ? $desired['plans'] : [];

        return ['plans' => array_values(array_map(
            self::publicPlan(...),
            array_filter($plans, 'is_array'),
        ))];
    }

    /**
     * O nativo do aparelho é o compartimento e a hora em duas peças.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function nativePlan(array $plan): array
    {
        if (!array_key_exists('times', $plan)) {
            return $plan;
        }

        $entry = is_array($plan['times'][0] ?? null) ? $plan['times'][0] : [];
        [$hour, $minute] = array_pad(explode(':', (string)($entry['time'] ?? '')), 2, '0');

        // O `enabled` carrega: um plano desligado é o que limpa o compartimento no downlink.
        return [
            'slot' => (int)($entry['slot'] ?? 0),
            'hour' => (int)$hour,
            'minute' => (int)$minute,
            'enabled' => MedicationPlanShape::enabled($entry['enabled'] ?? true),
        ];
    }

    /**
     * Cada compartimento é um plano sem nome com uma hora só: o aparelho não sabe o que
     * dispensa, só quando.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function publicPlan(array $plan): array
    {
        if (array_key_exists('times', $plan)) {
            return $plan;
        }

        $time = sprintf('%02d:%02d', (int)($plan['hour'] ?? 0), (int)($plan['minute'] ?? 0));
        $slot = array_key_exists('slot', $plan) ? (int)$plan['slot'] : null;

        return MedicationPlanShape::plan([], [
            MedicationPlanShape::timeEntry(
                $time,
                MedicationPlanShape::enabled($plan['enabled'] ?? true),
                ['kind' => 'daily'],
                ['slot' => $slot],
            ),
        ]);
    }

    public function defaultValue(): mixed
    {
        // Vazio é um plano legítimo: os nove alarmes desligados.
        return ['plans' => []];
    }

    public function meta(array $accumulatedMeta = []): array
    {
        return $accumulatedMeta + ['slots' => self::SLOTS];
    }

    /**
     * O plano substitui-se, não se funde. Ele viaja inteiro para o aparelho, e fundir um
     * plano parcial com o anterior dava um terceiro plano que ninguém pediu -- com alarmes
     * de trás que o utilizador julgava ter removido.
     */
    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return $incoming;
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return [
            'value' => $value,
            '_meta' => $meta,
        ];
    }
}
