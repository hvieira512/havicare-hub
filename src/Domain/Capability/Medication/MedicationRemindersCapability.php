<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Medication;

use Hub\Domain\Capability\CapabilityContract;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Os lembretes de medicação (`take_pills`): `takePills` no 4P Touch e os nove alarmes do M228.
 * O `supportedProtocols` sai do mapa dos tratadores.
 */
final class MedicationRemindersCapability implements CapabilityContract
{
    use CapabilityHelpers;

    /** @var array<string, MedicationRemindersHandler> */
    private array $handlers;

    public function __construct(
        ?MedicationRemindersHandler $fourPTouch = null,
        ?MedicationRemindersHandler $zayata = null,
    ) {
        $this->handlers = [
            'four-p-touch' => $fourPTouch ?? new FourPTouchMedicationRemindersHandler(),
            'zayata-m228' => $zayata ?? new ZayataMedicationRemindersHandler(),
        ];
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
        return array_keys($this->handlers);
    }

    public function toNative(string $protocol, mixed $value): array
    {
        return $this->require($protocol)->toNative($value);
    }

    /** Um protocolo sem tratador devolve o que lá está: a leitura desenha o ecrã e não pode rebentar. */
    public function fromNative(string $protocol, string $nativeKey, array $desired): mixed
    {
        return ($this->handlers[$protocol] ?? null)?->fromNative($desired) ?? $desired;
    }

    public function defaultValue(string $protocol): mixed
    {
        return ($this->handlers[$protocol] ?? null)?->defaultValue() ?? [];
    }

    public function meta(string $protocol, array $accumulatedMeta = []): array
    {
        return ($this->handlers[$protocol] ?? null)?->meta($accumulatedMeta) ?? $accumulatedMeta;
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return self::mergeAssociativeValues($existing, $incoming);
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return ($this->handlers[$protocol] ?? null)?->responseEntry($protocol, $nativeKey, $value, $meta)
            ?? ['value' => $value, '_meta' => $meta];
    }

    private function require(string $protocol): MedicationRemindersHandler
    {
        return $this->handlers[$protocol]
            ?? throw new \InvalidArgumentException("Unsupported protocol {$protocol} for medication_reminders");
    }
}
