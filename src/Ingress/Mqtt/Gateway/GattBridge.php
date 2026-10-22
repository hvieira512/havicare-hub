<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Gateway;

use Hub\Device\CommercialModelResolver;
use Hub\Device\HubMqttBridge;
use Hub\Device\TelemetryEnvelope;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\DeviceBridge;
use Hub\Ingress\Mqtt\DispatchesQueued;
use Hub\Ingress\Mqtt\Veepoo\BraceletPresence;
use Hub\Registry\Denylist;
use Hub\Registry\Whitelist;
use Hub\State\DeviceReportStore;

/**
 * Uma ponte de aparelhos BLE entregues por um gateway GATT: resolve o aparelho da mensagem que
 * reclamou, publica-a em cru e entrega à ponte o `kind`. Com `GattDownlinks`, guarda também as
 * sessões abertas e entrega-lhes a fila.
 */
abstract class GattBridge extends DeviceBridge implements GatewayRelay, DispatchesQueued
{
    /** Sem mensagens deste tempo, a sessão acabou mesmo sem o `connected: false`. */
    private const SESSION_SILENCE_SECONDS = 120;

    /**
     * Por cada aparelho com sessão: o gateway, o MTU e o fuso dele.
     *
     * @var array<string, array{gateway: string, mtu: int, offset: int}>
     */
    protected array $sessions = [];

    /** @var array<string, float> quando se ouviu cada aparelho pela última vez */
    private array $heardAt = [];

    /** @var array<string, BraceletPresence> por protocolo */
    private array $presences = [];

    public function __construct(
        Whitelist $whitelist,
        HubMqttBridge $mqttBridge,
        private readonly GatewayDeviceLinkLookup $links,
        private readonly string $protocol,
        private readonly string $deviceType,
        ?DeviceReportStore $deviceStore,
        ?callable $clock,
        ?CommercialModelResolver $commercialModelResolver,
        ?Denylist $denylist,
        protected readonly ?GattDownlinks $downlinks = null,
    ) {
        parent::__construct($whitelist, $mqttBridge, $deviceStore, $denylist, $clock, $commercialModelResolver);
    }

    /**
     * @param array<string, mixed> $data    o `payload` da mensagem
     * @param array<string, mixed> $message a mensagem inteira
     */
    abstract protected function handleKind(GattDeviceContext $context, string $kind, array $data, int $offsetMinutes, array $message): void;

    final public function handle(string $topic, array $message): void
    {
        $context = $this->resolveGattDevice($topic, $message, $this->links, $this->protocol, $this->deviceType);
        if ($context === null) {
            return;
        }

        $this->mqttBridge->publishRaw($context->deviceKey, $message, $this->deviceType, $context->licenseId, $context->company);
        $this->heardAt[$context->deviceKey] = $this->clockNow();

        $kind = (string)($message['kind'] ?? '');
        $data = is_array($message['payload'] ?? null) ? $message['payload'] : [];
        if ($kind === 'command_result' && $this->downlinks !== null) {
            $this->commandResult($context->deviceKey, $data);
            return;
        }
        // O fuso do gateway, que põe em UTC o que o aparelho data no relógio dele.
        $offset = is_int($message['tzOffsetMinutes'] ?? null) ? $message['tzOffsetMinutes'] : 0;
        $this->handleKind($context, $kind, $data, $offset, $message);
    }

    public function dispatchQueued(): void
    {
        foreach (array_keys($this->sessions) as $deviceKey) {
            $deviceKey = (string)$deviceKey;
            if ($this->clockNow() - ($this->heardAt[$deviceKey] ?? 0) > self::SESSION_SILENCE_SECONDS) {
                unset($this->sessions[$deviceKey]);
                continue;
            }
            $this->dispatchSession($deviceKey);
        }
    }

    /** O que uma sessão viva faz a cada volta: entregar a fila. */
    protected function dispatchSession(string $deviceKey): void
    {
        $this->dispatch($deviceKey);
    }

    protected function openSession(GattDeviceContext $context, int $mtu, int $offsetMinutes): void
    {
        $this->sessions[$context->deviceKey] = ['gateway' => $context->gatewayKey, 'mtu' => $mtu, 'offset' => $offsetMinutes];
    }

    /** @return list<string> as operações que saíram */
    protected function dispatch(string $deviceKey): array
    {
        $session = $this->sessions[$deviceKey] ?? null;
        if ($session === null || $this->downlinks === null) {
            return [];
        }

        return $this->downlinks->dispatchPending($deviceKey, $session['gateway'], $session['mtu'], $session['offset']);
    }

    /** @param array<string, mixed> $data */
    private function commandResult(string $deviceKey, array $data): void
    {
        $error = (string)($data['error'] ?? '');
        $this->downlinks?->delivered($deviceKey, (string)($data['dedupeKey'] ?? ''), ($data['ok'] ?? false) === true, $error);
        if ($error === GattDownlinks::NOT_CONNECTED) {
            unset($this->sessions[$deviceKey]);
        }
    }

    /** O protocolo com que a leitura sai; uma ponte que serve mais do que um decide-o pelo modelo. */
    protected function protocolOf(GattDeviceContext $context): string
    {
        return $this->protocol;
    }

    protected function presence(GattDeviceContext $context): BraceletPresence
    {
        $protocol = $this->protocolOf($context);

        return $this->presences[$protocol] ??= new BraceletPresence($this->mqttBridge, $this->deviceStore, $protocol);
    }

    /** @param array<string, mixed> $data */
    protected function telemetry(GattDeviceContext $context, string $type, string $nativeType, array $data, ?string $occurredAt = null): void
    {
        $telemetry = TelemetryEnvelope::for($type, $context->deviceKey, $context->device, $this->protocolOf($context), $nativeType, $data, $context->gatewayKey);
        if ($occurredAt !== null) {
            $telemetry['occurredAt'] = $occurredAt;
        }
        $this->emit($context, $telemetry);
    }

    /**
     * Publica no MQTT, para quem integra, e guarda no histórico da dashboard, para quem opera; o
     * histórico leva só a contagem das amostras de um traçado.
     *
     * @param array<string, mixed> $telemetry
     */
    protected function emit(GattDeviceContext $context, array $telemetry): void
    {
        $this->mqttBridge->publishTelemetry($context->deviceKey, $telemetry, $this->deviceType, $context->licenseId, $context->company);
        if (!$this->keepsInHistory($context->deviceKey, $telemetry)) {
            return;
        }
        $this->deviceStore?->append($context->deviceKey, 'telemetry', TelemetryEnvelope::forDashboard($telemetry) + [
            'deviceType' => $this->deviceType,
            'licenseId' => $context->licenseId,
        ]);
    }

    /** @param array<string, mixed> $telemetry */
    protected function keepsInHistory(string $deviceKey, array $telemetry): bool
    {
        return true;
    }
}
