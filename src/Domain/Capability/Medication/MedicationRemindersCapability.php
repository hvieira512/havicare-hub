<?php

namespace Hub\Domain\Capability\Medication;

use Hub\Domain\Capability\CapabilityContract;

/**
 * Os lembretes de medicação (`take_pills`).
 *
 * Mapeia em formas nativas diferentes por protocolo:
 * - wonlex-json: `{ dnMedicationPlan: { plans: [...] } }`
 * - four-p-touch: `{ takePills: { reminderSettings: [...], reminderText, voiceData } }`, com
 *   o campo nativo `number` derivado do `reminderSettings`.
 */
final class MedicationRemindersCapability implements CapabilityContract
{
    private MedicationRemindersHandler $wonlex;
    private MedicationRemindersHandler $fourPTouch;
    private MedicationRemindersHandler $pillDispenser;

    public function __construct(
        ?MedicationRemindersHandler $wonlex = null,
        ?MedicationRemindersHandler $fourPTouch = null,
        ?MedicationRemindersHandler $pillDispenser = null,
    ) {
        $this->wonlex = $wonlex ?? new WonlexMedicationRemindersHandler();
        $this->fourPTouch = $fourPTouch ?? new FourPTouchMedicationRemindersHandler();
        $this->pillDispenser = $pillDispenser ?? new PillDispenserMedicationRemindersHandler();
    }

    public function key(): string
    {
        return 'medication_reminders';
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
        return ['wonlex-json', 'four-p-touch', 'zayata-m228'];
    }

    public function toNative(string $protocol, mixed $value): array
    {
        return match ($protocol) {
            'wonlex-json' => $this->wonlex->toNative($value),
            'four-p-touch' => $this->fourPTouch->toNative($value),
            'zayata-m228' => $this->pillDispenser->toNative($value),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol} for medication_reminders"),
        };
    }

    public function fromNative(string $protocol, string $nativeKey, array $desired): mixed
    {
        return match ($protocol) {
            'wonlex-json' => $this->wonlex->fromNative($desired),
            'zayata-m228' => $this->pillDispenser->fromNative($desired),
            default => $this->fourPTouch->fromNative($desired),
        };
    }

    public function defaultValue(string $protocol): mixed
    {
        return match ($protocol) {
            'wonlex-json' => $this->wonlex->defaultValue(),
            'four-p-touch' => $this->fourPTouch->defaultValue(),
            'zayata-m228' => $this->pillDispenser->defaultValue(),
            default => [],
        };
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        return match ($protocol) {
            'wonlex-json' => $this->wonlex->meta($accumulatedMeta),
            'four-p-touch' => $this->fourPTouch->meta($accumulatedMeta),
            'zayata-m228' => $this->pillDispenser->meta($accumulatedMeta),
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
            'zayata-m228' => $this->pillDispenser->responseEntry($protocol, $nativeKey, $value, $meta),
            default => ['value' => $value, '_meta' => $meta],
        };
    }
}
