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
        return ['takePills' => self::requireObjectValue($value, 'medication_reminders')];
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
        return [
            'reminderSettings' => [
                ['time' => '08:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                ['time' => '09:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                ['time' => '10:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
            ],
            'number' => 1,
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
