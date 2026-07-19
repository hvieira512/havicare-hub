<?php

namespace Hub\Domain\Capability\Medication;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Capability for medication reminders (take_pills).
 *
 * Maps to different native shapes per protocol:
 * - wonlex-json: { dnMedicationPlan: { plans: [...] } }
 * - four-p-touch: { takePills: { reminderSettings: [...], number, reminderText, voiceData } }
 */
final class MedicationRemindersCapability implements CapabilityContract
{
    use CapabilityHelpers;

    public function key(): string
    {
        return 'medication_reminders';
    }

    public function section(): string
    {
        return 'alarms';
    }

    public function isList(): bool
    {
        return false;
    }

    public function supportedProtocols(): array
    {
        return ['wonlex-json', 'four-p-touch'];
    }

    public function toNative(string $protocol, mixed $value): array
    {
        return match ($protocol) {
            'wonlex-json' => ['dnMedicationPlan' => ['plans' => self::requireListValue($value, 'plans')]],
            'four-p-touch' => ['takePills' => self::requireObjectValue($value, 'medication_reminders')],
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol} for medication_reminders"),
        };
    }

    public function fromNative(string $nativeKey, array $desired): mixed
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

    public function defaultValue(string $protocol): mixed
    {
        return match ($protocol) {
            'four-p-touch' => [
                'reminderSettings' => [
                    ['time' => '08:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                    ['time' => '09:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                    ['time' => '10:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                ],
                'number' => 1,
                'reminderText' => '',
                'voiceData' => '',
                'voiceMimeType' => 'audio/webm',
            ],
            default => [],
        };
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
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
            '_type' => $this->key(),
        ];
    }

    public function nativeKeyForProtocol(string $protocol): ?string
    {
        return match ($protocol) {
            'wonlex-json' => 'dnMedicationPlan',
            'four-p-touch' => 'takePills',
            default => null,
        };
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }

    // ------------------------------------------------------------------
    // String parsing
    // ------------------------------------------------------------------

    /** @return list<array{time: string, enabled: bool, frequency: int, custom: string}> */
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
