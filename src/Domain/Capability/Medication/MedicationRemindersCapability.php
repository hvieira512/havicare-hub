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

    public function fromNative(string $protocol, string $nativeKey, array $desired): mixed
    {
        return $protocol === 'wonlex-json'
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

    // Esta capacidade não usa o `CapabilityHelpers` -- não precisa de nenhum dos seus
    // ajudantes --, e por isso declara o que as outras herdam dele.
    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $key;
    }
}
