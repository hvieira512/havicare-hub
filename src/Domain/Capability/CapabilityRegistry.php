<?php

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
 * O registo central dos contratos de capacidades.
 *
 * As capacidades complexas (`alarm_clock`, `sos_contacts`, `call_whitelist`, ...) implementam
 * o `CapabilityContract` e registam-se aqui. As simples -- interruptores, números, telefones
 * -- caem na `GenericCapability`, que também é um `CapabilityContract`.
 *
 * É por isso que este ficheiro encolheu para metade: sete métodos perguntavam
 * `isset($this->contracts[$key])` e tratavam eles próprios do caso em que a resposta era
 * não, e os cerca de 120 linhas dessa segunda metade eram tradução de fio da Vivistar e da
 * Wonlex a viver dentro de um registo. O `contract()` devolve sempre alguém, e um registo
 * volta a só registar e a delegar.
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
     * O contrato escrito à mão desta chave, ou `null` se não houver nenhum.
     *
     * Continua a devolver `null` de propósito: quem chama isto está mesmo a perguntar se a
     * capacidade tem código próprio -- é uma pergunta legítima, ao contrário dos `isset` que
     * os métodos de despacho faziam para decidir se delegavam.
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
        // `??` e não `??=` no primeiro: escrever aqui punha a genérica dentro do mapa dos
        // contratos, e o `has()` e o `get()` -- que existem justamente para distinguir os
        // dois -- passavam a responder que sim a toda a gente.
        return $this->contracts[$genericKey]
            ?? ($this->generic[$genericKey] ??= new GenericCapability($genericKey, $this->fourPTouchGeneric));
    }

    /**
     * Se a alteração tem de viajar para o dispositivo, ou se o hub a aplica sozinho.
     *
     * Por omissão viaja: uma configuração é um downlink à espera de acontecer, e uma
     * capacidade só sai dessa regra dizendo-o com o `HubAppliedCapability`.
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

    /**
     * O `instanceof` fica: não é um desvio à regra, é a pergunta "esta capacidade quer
     * limpar o que lhe entra?". Quem quiser diz-lo implementando o `CapabilityInputSanitizer`,
     * e quem não quiser não escreve método nenhum.
     */
    public function sanitizeInput(string $protocol, string $genericKey, mixed $value): mixed
    {
        $contract = $this->contract($genericKey);

        return $contract instanceof CapabilityInputSanitizer
            ? $contract->sanitizeInput($protocol, $value)
            : $value;
    }

    /**
     * O protocolo vem à frente e sem valor por omissão, como no `toNative` e no
     * `responseEntry`. Era um `string $protocol = ''` no fim, e o `''` deixava chamar isto
     * sem dizer de que fornecedor era o payload -- o que só funcionava porque um
     * `instanceof AlarmClockCapability` aqui desviava esse caso para um segundo método.
     * Descodificar sem saber o protocolo é adivinhar; agora não se pode pedir.
     */
    public function fromNative(string $protocol, string $genericKey, string $nativeKey, array $desired): mixed
    {
        return $this->contract($genericKey)->fromNative($protocol, $nativeKey, $desired);
    }

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

    public function resolveConfigKey(string $protocol, string $key): ?string
    {
        return $this->contract($key)->resolveConfigKey($protocol, $key);
    }
}
