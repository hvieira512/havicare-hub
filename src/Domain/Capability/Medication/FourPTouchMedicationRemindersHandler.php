<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Medication;

use Hub\Domain\Capability\CapabilityHelpers;

/** A estratégia do 4P Touch para os lembretes de medicação. */
final class FourPTouchMedicationRemindersHandler implements MedicationRemindersHandler
{
    use CapabilityHelpers;

    public function nativeKey(): string
    {
        return 'takePills';
    }

    public function toNative(mixed $value): array
    {
        $desired = self::requireObjectValue($value, 'medication_reminders');
        if (array_key_exists('plans', $desired)) {
            $plans = is_array($desired['plans']) ? $desired['plans'] : [];
            unset($desired['plans']);
            $settings = array_values(array_map(self::nativeSetting(...), array_filter($plans, 'is_array')));
        } else {
            $settings = $desired['reminderSettings'] ?? [];
            if (is_string($settings)) {
                $settings = $this->parseReminderSettings($settings);
            } elseif (is_array($settings) && !array_is_list($settings)) {
                $settings = [$settings];
            }
            if (!is_array($settings)) {
                throw new \InvalidArgumentException('medication_reminders.reminderSettings must be a list');
            }
        }

        $desired['reminderSettings'] = $settings;
        $desired['number'] = count($settings);

        return ['takePills' => $desired];
    }

    public function fromNative(array $desired): mixed
    {
        $settings = $desired['reminderSettings'] ?? [];
        if (is_string($settings) && trim($settings) !== '') {
            $settings = $this->parseReminderSettings($settings);
        } elseif (is_array($settings) && !array_is_list($settings)) {
            $settings = [$settings];
        }

        $value = [
            'plans' => array_values(array_map(
                self::publicPlan(...),
                array_filter(is_array($settings) ? $settings : [], 'is_array'),
            )),
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
        return [
            'plans' => [],
            'reminderText' => '',
            'voiceData' => '',
            'voiceMimeType' => 'audio/webm',
        ];
    }

    public function meta(array $accumulatedMeta = []): array
    {
        return $accumulatedMeta;
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        if (
            is_array($incoming)
            && array_key_exists('plans', $incoming)
            && $incoming['plans'] === []
        ) {
            $incoming['reminderText'] = $incoming['reminderText'] ?? '';
            $incoming['voiceData'] = $incoming['voiceData'] ?? '';
            $incoming['voiceMimeType'] = $incoming['voiceMimeType'] ?? '';
        }

        return self::mergeAssociativeValues($existing, $incoming);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return [
            'value' => $value,
            '_meta' => $meta,
        ];
    }

    /**
     * O `number` é derivado e fica no nativo; um plano do 4P Touch tem uma hora só.
     *
     * @param array<string, mixed> $plan
     * @return array{time: string, enabled: bool, frequency: int, custom: string}
     */
    private static function nativeSetting(array $plan): array
    {
        $entry = is_array($plan['times'][0] ?? null) ? $plan['times'][0] : $plan;
        $recurrence = is_array($entry['recurrence'] ?? null) ? $entry['recurrence'] : [];
        [$frequency, $custom] = MedicationPlanShape::modeFromRecurrence($recurrence);

        return [
            'time' => MedicationPlanShape::time((string)($entry['time'] ?? '')),
            'enabled' => MedicationPlanShape::enabled($entry['enabled'] ?? true),
            'frequency' => $frequency,
            'custom' => $custom,
        ];
    }

    /**
     * Um lembrete é um plano sem nome com uma hora: o 4P Touch não agrupa por medicamento.
     * O modo nativo é o mesmo dos alarmes, e por isso a recorrência também.
     *
     * @param array<string, mixed> $setting
     * @return array<string, mixed>
     */
    private static function publicPlan(array $setting): array
    {
        if (array_key_exists('times', $setting)) {
            return $setting;
        }

        return MedicationPlanShape::plan([], [MedicationPlanShape::timeEntry(
            (string)($setting['time'] ?? ''),
            MedicationPlanShape::enabled($setting['enabled'] ?? true),
            MedicationPlanShape::recurrenceFromMode(
                (int)($setting['frequency'] ?? 1),
                (string)($setting['custom'] ?? ''),
            ),
        )]);
    }

    /**
     * @return list<array{time: string, enabled: bool, frequency: int, custom: string}>
     */
    private function parseReminderSettings(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = explode('-', $value);
        $reminders = [];
        $i = 0;
        while ($i < count($parts)) {
            $time = $parts[$i++] ?? '';
            $enabled = ($parts[$i++] ?? '0') === '1';
            $frequency = (int)($parts[$i++] ?? '1');
            $custom = $frequency === 3 ? ($parts[$i++] ?? '') : '';
            $reminders[] = [
                'time' => $time,
                'enabled' => $enabled,
                'frequency' => $frequency,
                'custom' => $custom,
            ];
        }

        return $reminders;
    }
}
