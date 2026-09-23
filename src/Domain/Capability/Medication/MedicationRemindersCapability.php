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
 * - zayata-m228: `{ medication_reminders: { plans: [...] } }`, os nove alarmes do aparelho.
 *
 * Os tratadores estão num mapa e não em `match` repetidos, e o `supportedProtocols` sai do
 * mesmo mapa, para não poder anunciar o que o despacho recusa.
 */
final class MedicationRemindersCapability implements CapabilityContract
{
    /** @var array<string, MedicationRemindersHandler> */
    private array $handlers;

    public function __construct(
        ?MedicationRemindersHandler $wonlex = null,
        ?MedicationRemindersHandler $fourPTouch = null,
        ?MedicationRemindersHandler $pillDispenser = null,
    ) {
        $this->handlers = [
            'wonlex-json' => $wonlex ?? new WonlexMedicationRemindersHandler(),
            'four-p-touch' => $fourPTouch ?? new FourPTouchMedicationRemindersHandler(),
            'zayata-m228' => $pillDispenser ?? new PillDispenserMedicationRemindersHandler(),
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

    /**
     * Um protocolo sem tratador devolve o que lá está, em vez de o passar por um tratador
     * qualquer. A leitura desenha o ecrã e não pode rebentar; inventar uma descodificação
     * seria pior do que não fazer nenhuma.
     */
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

    /**
     * O plano da Wonlex reconhece-se pela forma e não pelo protocolo: quem chama o `merge` tem
     * o valor antigo e o novo, e não o protocolo por onde eles vieram.
     */
    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return is_array($incoming) && array_key_exists('plans', $incoming)
            ? $this->handlers['wonlex-json']->merge($existing, $incoming)
            : $this->handlers['four-p-touch']->merge($existing, $incoming);
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
