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

    private MedicationRemindersHandler $wonlex;
    private MedicationRemindersHandler $fourPTouch;

    public function __construct(
        ?MedicationRemindersHandler $wonlex = null,
        ?MedicationRemindersHandler $fourPTouch = null,
    ) {
        $this->wonlex = $wonlex ?? new WonlexMedicationRemindersHandler();
        $this->fourPTouch = $fourPTouch ?? new FourPTouchMedicationRemindersHandler();
    }

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

    public function supportsMultipleNativeKeys(): bool
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
            'wonlex-json' => $this->wonlex->toNative($value),
            'four-p-touch' => $this->fourPTouch->toNative($value),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol} for medication_reminders"),
        };
    }

    public function fromNative(string $nativeKey, array $desired): mixed
    {
        return $nativeKey === 'dnMedicationPlan'
            ? $this->wonlex->fromNative($desired)
            : $this->fourPTouch->fromNative($desired);
    }

    public function defaultValue(string $protocol): mixed
    {
        return match ($protocol) {
            'wonlex-json' => $this->wonlex->defaultValue(),
            'four-p-touch' => $this->fourPTouch->defaultValue(),
            default => [],
        };
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        return match ($protocol) {
            'wonlex-json' => $this->wonlex->meta($accumulatedMeta),
            'four-p-touch' => $this->fourPTouch->meta($accumulatedMeta),
            default => $accumulatedMeta,
        };
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return is_array($incoming) && array_key_exists('plans', $incoming)
            ? $this->wonlex->merge($existing, $incoming)
            : $this->fourPTouch->merge($existing, $incoming);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return match ($protocol) {
            'wonlex-json' => $this->wonlex->responseEntry($protocol, $nativeKey, $value, $meta),
            'four-p-touch' => $this->fourPTouch->responseEntry($protocol, $nativeKey, $value, $meta),
            default => ['value' => $value, '_meta' => $meta],
        };
    }

    public function nativeKeyForProtocol(string $protocol): ?string
    {
        return match ($protocol) {
            'wonlex-json' => $this->wonlex->nativeKey(),
            'four-p-touch' => $this->fourPTouch->nativeKey(),
            default => null,
        };
    }

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }
}
