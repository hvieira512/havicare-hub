<?php

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

        return ['medication_reminders' => ['plans' => array_values($plans)]];
    }

    public function fromNative(array $desired): mixed
    {
        $plans = $desired['plans'] ?? [];

        return ['plans' => is_array($plans) ? array_values(array_filter($plans, 'is_array')) : []];
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
