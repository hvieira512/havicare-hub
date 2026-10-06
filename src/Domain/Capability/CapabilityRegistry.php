<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

use Hub\Domain\Capability\AlarmClock\AlarmClockCapability;
use Hub\Domain\Capability\AlarmClock\FourPTouch as FourPTouchAlarmClock;
use Hub\Domain\Capability\AlarmClock\Vivistar as VivistarAlarmClock;
use Hub\Domain\Capability\AlarmClock\Wonlex as WonlexAlarmClock;
use Hub\Domain\Capability\Alarms\SosSmsAlertCapability;
use Hub\Domain\Capability\Contacts\CallWhitelistCapability;
use Hub\Domain\Capability\Contacts\PhonebookCapability;
use Hub\Domain\Capability\Contacts\SosContactsCapability;
use Hub\Domain\Capability\Contacts\WhitelistEnabledCapability;
use Hub\Domain\Capability\FourPTouch\FourPTouchGenericHandler;
use Hub\Domain\Capability\Medication\MedicationRemindersCapability;

/**
 * O registo central dos contratos de capacidades. As simples caem na `GenericCapability`, e
 * por isso o `contract()` devolve sempre alguém.
 */
final class CapabilityRegistry
{
    /** @var array<string, CapabilityContract> */
    private array $contracts = [];

    /** @var array<string, GenericCapability> as genéricas, criadas à medida que aparecem */
    private array $generic = [];

    private FourPTouchGenericHandler $fourPTouchGeneric;

    public function __construct()
    {
        $this->fourPTouchGeneric = new FourPTouchGenericHandler();
        $vivistar = new VivistarAlarmClock();
        $fourPTouch = new FourPTouchAlarmClock();
        $this->register(new AlarmClockCapability([
            'vivistar-iw' => $vivistar,
            'wonlex-json' => new WonlexAlarmClock(),
            'four-p-touch' => $fourPTouch,
        ]));

        $this->register(new SosContactsCapability());
        $this->register(new CallWhitelistCapability());
        $this->register(new WhitelistEnabledCapability());
        $this->register(new PhonebookCapability());
        $this->register(new SosSmsAlertCapability());
        $this->register(new MedicationRemindersCapability());
        $this->register(new DiaperSensitivityCapability());
    }

    public function register(CapabilityContract $capability): void
    {
        $this->contracts[$capability->key()] = $capability;
    }

    /**
     * O contrato escrito à mão desta chave, ou `null`. Devolve `null` de propósito: quem
     * chama está a perguntar se a capacidade tem código próprio.
     */
    public function get(string $genericKey): ?CapabilityContract
    {
        return $this->contracts[$genericKey] ?? null;
    }

    public function has(string $genericKey): bool
    {
        return isset($this->contracts[$genericKey]);
    }

    /** Quem trata desta chave: o contrato escrito à mão, ou a genérica. Nunca `null`. */
    private function contract(string $genericKey): CapabilityContract
    {
        // `??` e não `??=`: guardar a genérica no mapa dos contratos faria o `has()` e o `get()`
        // responderem que sim a tudo.
        return $this->contracts[$genericKey]
            ?? ($this->generic[$genericKey] ??= new GenericCapability($genericKey, $this->fourPTouchGeneric));
    }

    /**
     * Se a alteração viaja para o dispositivo. Por omissão viaja, e só o `HubAppliedCapability`
     * diz que o hub a aplica sozinho.
     */
    public function travelsToDevice(string $genericKey): bool
    {
        return !($this->contract($genericKey) instanceof HubAppliedCapability);
    }

    public function supportsProtocol(string $genericKey, string $protocol): bool
    {
        return in_array($protocol, $this->contract($genericKey)->supportedProtocols(), true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toNative(string $protocol, string $genericKey, mixed $value): array
    {
        return $this->contract($genericKey)->toNative($protocol, $value);
    }

    /** Só as capacidades que implementam o `CapabilityInputSanitizer` limpam o que lhes entra. */
    public function sanitizeInput(string $protocol, string $genericKey, mixed $value): mixed
    {
        $contract = $this->contract($genericKey);

        return $contract instanceof CapabilityInputSanitizer
            ? $contract->sanitizeInput($protocol, $value)
            : $value;
    }

    /**
     * O protocolo vem à frente e sem valor por omissão, como no `toNative` e no
     * `responseEntry`: descodificar sem saber de que fornecedor é o payload é adivinhar.
     *
     * @param array<array-key, mixed> $desired
     */
    public function fromNative(string $protocol, string $genericKey, string $nativeKey, array $desired): mixed
    {
        return $this->contract($genericKey)->fromNative($protocol, $nativeKey, $desired);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function responseEntry(string $protocol, string $genericKey, string $nativeKey, mixed $value, array $meta): array
    {
        return $this->contract($genericKey)->responseEntry($protocol, $nativeKey, $value, $meta);
    }

    public function defaultValue(string $protocol, string $genericKey): mixed
    {
        return $this->contract($genericKey)->defaultValue($protocol);
    }

    /** Nada com que juntar é a única regra que vale para todas, e por isso fica cá fora. */
    public function merge(string $genericKey, mixed $existing, mixed $incoming): mixed
    {
        return $existing === null
            ? $incoming
            : $this->contract($genericKey)->merge($existing, $incoming);
    }
}
