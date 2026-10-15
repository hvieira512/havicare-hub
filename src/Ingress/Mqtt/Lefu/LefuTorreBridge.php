<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Lefu;

use Hub\Device\CommercialModelResolver;
use Hub\Device\HubMqttBridge;
use Hub\Device\PendingDownlinkQueue;
use Hub\Device\TelemetryEnvelope;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\DispatchesQueued;
use Hub\Ingress\Mqtt\Gateway\GatewayTopic;
use Hub\Ingress\Mqtt\MqttBridgeBase;
use Hub\Ingress\Mqtt\Veepoo\BraceletPresence;
use Hub\Registry\Whitelist;
use Hub\State\DeviceCommandLog;
use Hub\State\DeviceReportStore;
use PhpMqtt\Client\MqttClient;

/**
 * Ingestão das balanças Torre entregues por um gateway GATT, que reencaminha cada notificação em
 * bruto: o protocolo é todo do hub, à ida e à volta.
 */
final class LefuTorreBridge extends MqttBridgeBase implements DispatchesQueued
{
    private const PROTOCOL = 'lefu-torre';

    private const DEVICE_TYPE = 'scale';

    /** O SDK ignora uma segunda trama completa que chegue neste intervalo. */
    private const REPEATED_FINAL_SECONDS = 2.0;

    /** 2023-02-03: o SDK descarta os registos de histórico anteriores. */
    private const HISTORY_EPOCH = 1675382400;

    /** O MTU que a CF632 dá, quando a sessão não o trouxer. */
    private const DEFAULT_MTU = 61;

    /**
     * Por cada balança com sessão: o gateway, o MTU e o fuso dele.
     *
     * @var array<string, array{gateway: string, mtu: int, offset: int}>
     */
    private array $sessions = [];

    /**
     * O último estado de pesagem publicado, para só sair quando muda.
     *
     * @var array<string, string>
     */
    private array $lastStatus = [];

    /**
     * Quando saiu a última pesagem completa.
     *
     * @var array<string, float>
     */
    private array $lastFinalAt = [];

    /**
     * A impedância cifrada da pesagem em curso, que chega antes da trama final.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $encodedImpedance = [];

    /**
     * O utilizador que se pôs em linha e o que a balança já aceitou.
     *
     * @var array<string, array<string, string>>
     */
    private array $pendingUser = [];

    /** @var array<string, array<string, string>> */
    private array $activeUser = [];

    private readonly TorreFrameDecoder $decoder;

    private readonly LefuDownlinks $downlinks;

    private readonly BraceletPresence $presence;

    private readonly ?DeviceCommandLog $commands;

    public function __construct(
        MqttClient $subscriber,
        Whitelist $whitelist,
        HubMqttBridge $mqttBridge,
        private readonly GatewayDeviceLinkLookup $links,
        ?PendingDownlinkQueue $queue,
        string $topicFilter,
        ?callable $reconnectSubscriber = null,
        null|(DeviceReportStore&DeviceCommandLog) $deviceStore = null,
        ?callable $clock = null,
        ?CommercialModelResolver $commercialModelResolver = null,
    ) {
        parent::__construct(
            $subscriber,
            $whitelist,
            $mqttBridge,
            $topicFilter,
            'lefu',
            $reconnectSubscriber,
            $deviceStore,
            clock: $clock,
            commercialModelResolver: $commercialModelResolver,
        );
        $this->commands = $deviceStore;
        $this->decoder = new TorreFrameDecoder();
        $this->downlinks = new LefuDownlinks($queue, $mqttBridge, $deviceStore, $topicFilter);
        $this->presence = new BraceletPresence($mqttBridge, $deviceStore, self::PROTOCOL, self::DEVICE_TYPE);
    }

    public function dispatchQueued(): void
    {
        foreach (array_keys($this->sessions) as $deviceKey) {
            $this->dispatch((string)$deviceKey);
        }
    }

    protected function handleMessage(string $topic, string $payload): void
    {
        $message = json_decode($payload, true);
        // O mesmo tópico serve gateways de outras marcas; só reclamamos o que é nosso.
        if (!is_array($message) || ($message['source'] ?? null) !== 'lefu-torre-node') {
            return;
        }

        $parsed = GatewayTopic::parse($topic);
        $gateway = $parsed === null ? null : $this->whitelist->resolve($parsed->gatewayMac);
        if ($gateway === null || ($gateway['deviceType'] ?? '') !== 'gateway') {
            return;
        }

        $mac = GatewayTopic::normalizeMac((string)($message['device']['mac'] ?? ''));
        $device = $mac === null ? null : $this->whitelist->resolve($mac);
        if ($device === null || ($device['deviceType'] ?? '') !== self::DEVICE_TYPE) {
            $this->recordUnauthorizedDevice((string)$mac, self::PROTOCOL, (string)($message['device']['model'] ?? ''), ident: (string)$mac);
            return;
        }
        if (
            !$this->links->isEnabled((string)$gateway['imei'], (string)$device['imei'])
            || !$this->sameTenant($gateway, $device)
        ) {
            $this->warnRepeatedly(
                "unlinked:{$mac}:{$gateway['imei']}",
                "Ignoring unlinked lefu device={$mac} gateway={$gateway['imei']}"
            );
            return;
        }

        $device = $this->enrichWithCommercialName($device);
        $context = new ScaleContext(
            (string)$device['imei'],
            (string)$gateway['imei'],
            $device,
            (int)($device['licenseId'] ?? 0),
            (string)($device['company'] ?? 'null'),
        );

        $this->mqttBridge->publishRaw($context->deviceKey, $message, self::DEVICE_TYPE, $context->licenseId, $context->company);

        $data = is_array($message['payload'] ?? null) ? $message['payload'] : [];
        match ($message['kind'] ?? null) {
            'session' => $this->session($context, $data, is_int($message['tzOffsetMinutes'] ?? null) ? $message['tzOffsetMinutes'] : 0),
            'frame' => $this->frame($context, (string)($data['char'] ?? ''), (string)($data['hex'] ?? '')),
            'command_result' => $this->downlinks->delivered(
                $context->deviceKey,
                (string)($data['dedupeKey'] ?? ''),
                ($data['ok'] ?? false) === true,
                (string)($data['error'] ?? ''),
            ),
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function session(ScaleContext $context, array $data, int $offsetMinutes): void
    {
        if (($data['connected'] ?? false) !== true) {
            unset($this->sessions[$context->deviceKey], $this->activeUser[$context->deviceKey], $this->encodedImpedance[$context->deviceKey]);
            $this->presence->markOffline($context->deviceKey, $context->device, $context->licenseId, $context->company);
            return;
        }

        $mtu = is_int($data['mtu'] ?? null) && $data['mtu'] > 2 ? $data['mtu'] : self::DEFAULT_MTU;
        $this->sessions[$context->deviceKey] = ['gateway' => $context->gatewayKey, 'mtu' => $mtu, 'offset' => $offsetMinutes];
        $this->presence->markOnline($context->deviceKey, $context->device, $context->licenseId, $context->company);

        $battery = $data['battery'] ?? null;
        if (is_int($battery) && $battery >= 0 && $battery <= 100) {
            $this->emit($context, 'battery', 'battery', ['percent' => $battery]);
        }

        // O histórico é datado pelo relógio da balança: acerta-se a cada ligação.
        $this->downlinks->sendDirect($context->deviceKey, $context->gatewayKey, 'calibrateClock', [], $mtu, $offsetMinutes);
        $this->dispatch($context->deviceKey);
    }

    private function dispatch(string $deviceKey): void
    {
        $session = $this->sessions[$deviceKey] ?? null;
        if ($session === null) {
            return;
        }

        $this->downlinks->dispatchPending(
            $deviceKey,
            $session['gateway'],
            $session['mtu'],
            $session['offset'],
            function (string $operation, array $payload) use ($deviceKey): void {
                if ($operation === 'selectScaleUser') {
                    $this->pendingUser[$deviceKey] = ['scaleUserId' => (string)($payload['id'] ?? '')];
                }
            },
        );
    }

    private function frame(ScaleContext $context, string $characteristic, string $hex): void
    {
        $frame = $this->decoder->decode($characteristic, $hex);
        if ($frame === null) {
            return;
        }
        $nativeType = $characteristic . ':' . substr(strtolower(preg_replace('/\s+/', '', $hex) ?? ''), 0, 2);

        match ($frame['kind']) {
            'weighing' => $this->weighing($context, $frame, $nativeType),
            'encoded_impedance' => $this->encodedImpedance[$context->deviceKey] = $frame,
            'impedance_channel' => $this->emit($context, 'body_impedance_channel', $nativeType, [
                'channel' => $frame['channel'],
                'impedanceOhm' => $frame['impedanceOhm'],
            ]),
            'motion' => $this->emit($context, 'scale_motion', $nativeType, []),
            'history_available' => $this->emit($context, 'scale_history_available', $nativeType, []),
            'user_selection' => $this->emit($context, 'scale_user_selection', $nativeType, ['stateCode' => $frame['stateCode']]),
            'impedance_failure' => $this->emit($context, 'body_impedance_failure', $nativeType, array_filter(['limb' => $frame['limb']])),
            'setting' => $this->setting($context, $frame, $nativeType),
            'history' => $this->history($context, $frame, $nativeType),
            'reply' => $this->reply($context, $frame),
            default => null,
        };
    }

    /** @param array<string, mixed> $frame */
    private function weighing(ScaleContext $context, array $frame, string $nativeType): void
    {
        $deviceKey = $context->deviceKey;
        $status = ['weightKg' => $frame['weightKg']] + $frame['status'];
        // O peso oscila enquanto estabiliza: o estado sai quando muda a fase, e não o número.
        $signature = json_encode($frame['status']);
        if (($this->lastStatus[$deviceKey] ?? null) !== $signature) {
            $this->lastStatus[$deviceKey] = (string)$signature;
            $this->emit($context, 'weighing_status', $nativeType, $status);
        }

        if (!$frame['complete']) {
            return;
        }
        $now = $this->clockNow();
        if ($now - ($this->lastFinalAt[$deviceKey] ?? -INF) < self::REPEATED_FINAL_SECONDS) {
            return;
        }
        $this->lastFinalAt[$deviceKey] = $now;

        $user = $this->activeUser[$deviceKey] ?? [];
        $this->emit($context, 'weight', $nativeType, ['weightKg' => $frame['weightKg']] + $user);
        if ($frame['heartRateBpm'] !== null) {
            $this->emit($context, 'heart_rate', $nativeType, ['bpm' => $frame['heartRateBpm']] + $user);
        }

        $encoded = $this->encodedImpedance[$deviceKey] ?? null;
        unset($this->encodedImpedance[$deviceKey]);
        if ($frame['impedance50KhzOhm'] !== null) {
            $this->emit($context, 'body_impedance', $nativeType, array_filter([
                'impedance50KhzOhm' => $frame['impedance50KhzOhm'],
                'impedance100KhzOhm' => $frame['impedance100KhzOhm'],
                'electrodes' => $encoded['electrodes'] ?? null,
                'encodedImpedance50Khz' => $encoded['encoded50Khz'] ?? null,
                'encodedImpedance100Khz' => $encoded['encoded100Khz'] ?? null,
            ], static fn (mixed $value): bool => $value !== null) + $user);
        } elseif ($frame['status']['impedance'] === 'failed') {
            // Mau contacto: os valores em claro vêm implausíveis, e não saem.
            $this->emit($context, 'body_impedance_failure', $nativeType, []);
        }
    }

    /**
     * Os registos que a balança guardou de um membro, pelas mesmas capacidades da pesagem ao vivo
     * e com a data dela. O SDK descarta os anteriores a 2023-02-03, e o hub também.
     *
     * @param array<string, mixed> $frame
     */
    private function history(ScaleContext $context, array $frame, string $nativeType): void
    {
        $user = ['scaleUserId' => (string)$frame['memberId'], 'fromHistory' => true];
        foreach ($frame['records'] as $record) {
            if ($record['at'] < self::HISTORY_EPOCH) {
                continue;
            }
            $at = gmdate('Y-m-d\TH:i:s\Z', $record['at']);
            $this->emit($context, 'weight', $nativeType, ['weightKg' => $record['weightKg']] + $user, $at);
            if ($record['heartRateBpm'] !== null) {
                $this->emit($context, 'heart_rate', $nativeType, ['bpm' => $record['heartRateBpm']] + $user, $at);
            }
            $this->emit($context, 'body_impedance', $nativeType, [
                'impedance50KhzOhm' => $record['impedance50KhzOhm'],
                'impedance100KhzOhm' => $record['impedance100KhzOhm'],
                'electrodes' => $record['electrodes'],
                'encodedImpedance50Khz' => $record['encodedImpedance50Khz'],
                'encodedImpedance100Khz' => $record['encodedImpedance100Khz'],
            ] + $user, $at);
        }
    }

    /** @param array<string, mixed> $frame */
    private function setting(ScaleContext $context, array $frame, string $nativeType): void
    {
        $this->emit($context, 'device_config', $nativeType, ['settings' => $frame['settings']]);
        $this->commands?->markCommandReply($context->deviceKey, (string)$frame['replyType'], null, '', true);
    }

    /** @param array<string, mixed> $frame */
    private function reply(ScaleContext $context, array $frame): void
    {
        $deviceKey = $context->deviceKey;
        if ($frame['replyType'] === 'torre_user_07' && $frame['accepted'] && isset($this->pendingUser[$deviceKey])) {
            $this->activeUser[$deviceKey] = $this->pendingUser[$deviceKey];
            unset($this->pendingUser[$deviceKey]);
        }

        $this->commands?->markCommandReply($deviceKey, (string)$frame['replyType'], null, '', (bool)$frame['accepted']);
    }

    /** @param array<string, mixed> $data */
    private function emit(ScaleContext $context, string $type, string $nativeType, array $data, ?string $occurredAt = null): void
    {
        $telemetry = TelemetryEnvelope::for(
            $type,
            $context->deviceKey,
            $context->device,
            self::PROTOCOL,
            $nativeType,
            $data,
            $context->gatewayKey,
        );
        if ($occurredAt !== null) {
            $telemetry['occurredAt'] = $occurredAt;
        }
        $this->mqttBridge->publishTelemetry($context->deviceKey, $telemetry, self::DEVICE_TYPE, $context->licenseId, $context->company);
        $this->deviceStore?->append($context->deviceKey, 'telemetry', $telemetry + [
            'deviceType' => self::DEVICE_TYPE,
            'licenseId' => $context->licenseId,
        ]);
    }
}
