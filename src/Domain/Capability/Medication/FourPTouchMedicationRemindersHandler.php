<?php

namespace Hub\Domain\Capability\Medication;

use Hub\Domain\Capability\CapabilityHelpers;

/**
 * 4P Touch strategy for medication reminders.
 */
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
        $settings = $desired['reminderSettings'] ?? [];
        if (is_string($settings)) {
            $settings = $this->parseReminderSettings($settings);
        } elseif (is_array($settings) && !array_is_list($settings)) {
            $settings = [$settings];
        }
        if (!is_array($settings)) {
            throw new \InvalidArgumentException('medication_reminders.reminderSettings must be a list');
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
            'reminderSettings' => $settings,
            'number' => count($settings),
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
            'reminderSettings' => [],
            'number' => 0,
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
            && array_key_exists('reminderSettings', $incoming)
            && $incoming['reminderSettings'] === []
        ) {
            $incoming['number'] = 0;
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
