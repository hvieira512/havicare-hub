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
        return ['dnMedicationPlan' => ['plans' => self::requireListValue(is_array($value) ? ($value['plans'] ?? []) : [], 'plans')]];
    }

    public function fromNative(array $desired): mixed
    {
        $settings = $desired['reminderSettings'] ?? [];
        if (is_string($settings) && trim($settings) !== '') {
            $settings = [];
        } elseif (is_array($settings) && !array_is_list($settings)) {
            $settings = [$settings];
        }

        $value = [
            'reminderSettings' => $settings,
            'number' => $desired['number'] ?? 1,
            'reminderText' => $desired['reminderText'] ?? '',
            'voiceData' => $desired['voiceData'] ?? '',
        ];
        if (array_key_exists('voiceMimeType', $desired)) {
            $value['voiceMimeType'] = $desired['voiceMimeType'];
        }

        return $value;
    }

    public function defaultValue(): mixed
    {
        return [];
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
            '_type' => 'medication_reminders',
        ];
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }
}
