<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Lefu;

use Hub\Ingress\Mqtt\DeviceBridge;
use Hub\Ingress\Mqtt\Gateway\GatewayRelay;
use Hub\Command\Configuration\Payload\LefuTorreScaleUsers;
use Hub\Device\CommercialModelResolver;
use Hub\Device\HubMqttBridge;
use Hub\Device\PendingDownlinkQueue;
use Hub\Device\TelemetryEnvelope;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\DispatchesQueued;
use Hub\Ingress\Mqtt\Gateway\GattDeviceContext;
use Hub\Ingress\Mqtt\Gateway\GattDownlinks;
use Hub\Ingress\Mqtt\JoyTech\RecentMeasurementKeys;
use Hub\Ingress\Mqtt\Veepoo\BraceletPresence;
use Hub\Registry\Denylist;
use Hub\Registry\Whitelist;
use Hub\State\DeviceCommandLog;
use Hub\State\DeviceReportStore;

/**
 * Ingestão das balanças Torre entregues por um gateway GATT, que reencaminha cada notificação em
 * bruto: o protocolo é todo do hub, à ida e à volta.
 */
final class LefuTorreBridge extends DeviceBridge implements GatewayRelay, DispatchesQueued
{
    private const PROTOCOL = 'lefu-torre';

    private const DEVICE_TYPE = 'scale';

    /** O SDK ignora uma segunda trama completa que chegue neste intervalo. */
    private const REPEATED_FINAL_SECONDS = 2.0;

    /** 2023-02-03: o SDK descarta os registos de histórico anteriores. */
    private const HISTORY_EPOCH = 1675382400;

    /** O MTU que a CF632 dá, quando a sessão não o trouxer. */
    private const DEFAULT_MTU = 61;

    /** Sem mensagens deste tempo, a sessão acabou mesmo sem o `connected: false`. */
    private const SESSION_SILENCE_SECONDS = 120;

    /** A balança aceitou pôr em linha. */
    private const SELECT_REPLY = 'torre_user_07';

    /**
     * Por cada balança com sessão: o gateway, o MTU e o fuso dele.
     *
     * @var array<string, array{gateway: string, mtu: int, offset: int}>
     */
    private array $sessions = [];

    /** @var array<string, float> quando se ouviu cada balança pela última vez */
    private array $heardAt = [];

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

    /** @var null|\Closure(string): ?array<string, mixed> os utilizadores da balança, como ela os confirmou */
    private readonly ?\Closure $scaleUsers;

    private readonly TorreFrameDecoder $decoder;

    private readonly GattDownlinks $downlinks;

    private readonly BraceletPresence $presence;

    private readonly ?DeviceCommandLog $commands;

    public function __construct(
        Whitelist $whitelist,
        HubMqttBridge $mqttBridge,
        private readonly GatewayDeviceLinkLookup $links,
        ?PendingDownlinkQueue $queue,
        string $topicFilter,
        null|(DeviceReportStore&DeviceCommandLog) $deviceStore = null,
        ?callable $clock = null,
        ?CommercialModelResolver $commercialModelResolver = null,
        ?callable $scaleUsers = null,
        ?Denylist $denylist = null,
        private readonly ScaleOnlineUsers $onlineUsers = new ScaleOnlineUsers(),
        private readonly RecentMeasurementKeys $historySeen = new RecentMeasurementKeys(prefix: 'hub:lefu:history-seen', keep: 500),
    ) {
        parent::__construct($whitelist, $mqttBridge, $deviceStore, $denylist, $clock, $commercialModelResolver);
        $this->commands = $deviceStore;
        $this->scaleUsers = $scaleUsers !== null ? \Closure::fromCallable($scaleUsers) : null;
        $this->decoder = new TorreFrameDecoder();
        $this->downlinks = new GattDownlinks($queue, $mqttBridge, $deviceStore, $topicFilter, (new TorreCommandEncoder())->writes(...));
        $this->presence = new BraceletPresence($mqttBridge, $deviceStore, self::PROTOCOL, self::DEVICE_TYPE);
    }

    public function dispatchQueued(): void
    {
        foreach (array_keys($this->sessions) as $deviceKey) {
            $deviceKey = (string)$deviceKey;
            if ($this->clockNow() - ($this->heardAt[$deviceKey] ?? 0) > self::SESSION_SILENCE_SECONDS) {
                unset($this->sessions[$deviceKey]);
                continue;
            }
            $this->dispatch($deviceKey);
        }
    }

    public static function claims(array $message): bool
    {
        return ($message['source'] ?? null) === 'lefu-torre-node';
    }

    public function handle(string $topic, array $message): void
    {
        $context = $this->resolveGattDevice($topic, $message, $this->links, self::PROTOCOL, self::DEVICE_TYPE);
        if ($context === null) {
            return;
        }

        $this->mqttBridge->publishRaw($context->deviceKey, $message, self::DEVICE_TYPE, $context->licenseId, $context->company);

        $data = is_array($message['payload'] ?? null) ? $message['payload'] : [];
        $offset = is_int($message['tzOffsetMinutes'] ?? null) ? $message['tzOffsetMinutes'] : 0;
        $this->heardAt[$context->deviceKey] = $this->clockNow();
        match ($message['kind'] ?? null) {
            'session' => $this->session($context, $data, $offset),
            'frame' => $this->frame($context, (string)($data['char'] ?? ''), (string)($data['hex'] ?? ''), $offset),
            'command_result' => $this->commandResult($context, $data),
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function commandResult(GattDeviceContext $context, array $data): void
    {
        $error = (string)($data['error'] ?? '');
        $this->downlinks->delivered($context->deviceKey, (string)($data['dedupeKey'] ?? ''), ($data['ok'] ?? false) === true, $error);
        if ($error === GattDownlinks::NOT_CONNECTED) {
            unset($this->sessions[$context->deviceKey]);
        }
    }

    /** @param array<string, mixed> $data */
    private function session(GattDeviceContext $context, array $data, int $offsetMinutes): void
    {
        if (($data['connected'] ?? false) !== true) {
            unset($this->sessions[$context->deviceKey], $this->encodedImpedance[$context->deviceKey], $this->lastStatus[$context->deviceKey]);
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
        );
    }

    private function frame(GattDeviceContext $context, string $characteristic, string $hex, int $offsetMinutes): void
    {
        $frame = $this->decoder->decode($characteristic, $hex);
        if ($frame === null) {
            return;
        }
        // Um hub reiniciado com a balança ligada não viu a sessão abrir: a primeira trama abre-a.
        if (!isset($this->sessions[$context->deviceKey])) {
            $this->sessions[$context->deviceKey] = ['gateway' => $context->gatewayKey, 'mtu' => self::DEFAULT_MTU, 'offset' => $offsetMinutes];
            $this->presence->markOnline($context->deviceKey, $context->device, $context->licenseId, $context->company);
        }
        $nativeType = $characteristic . ':' . substr(strtolower(preg_replace('/\s+/', '', $hex) ?? ''), 0, 2);

        match ($frame['kind']) {
            'weighing' => $this->weighing($context, $frame, $nativeType),
            'encoded_impedance' => $this->encodedImpedance[$context->deviceKey] = $frame,
            'impedance_channel' => $this->emit($context, 'body_impedance_channel', $nativeType, [
                'channel' => $frame['channel'],
                'impedanceOhm' => $frame['impedanceOhm'],
            ]),
            'setting' => $this->setting($context, $frame, $nativeType),
            'history' => $this->history($context, $frame, $nativeType),
            'reply' => $this->reply($context, $frame),
            default => null,
        };
    }

    /** @param array<string, mixed> $frame */
    private function weighing(GattDeviceContext $context, array $frame, string $nativeType): void
    {
        $deviceKey = $context->deviceKey;
        $status = ['weightKg' => $frame['weightKg']] + $frame['status'];
        // O peso oscila enquanto estabiliza: o estado sai quando muda a fase, e não o número.
        $signature = json_encode($frame['status']);
        if (($this->lastStatus[$deviceKey] ?? null) !== $signature) {
            $this->lastStatus[$deviceKey] = (string)$signature;
            $this->emit($context, 'weighing_status', $nativeType, $status);
        }
        // Com a balança vazia, a impedância cifrada da pessoa anterior já não é de ninguém.
        if ($frame['status']['weightPhase'] === 'idle') {
            unset($this->encodedImpedance[$deviceKey]);
        }

        if (!$frame['complete']) {
            return;
        }
        $now = $this->clockNow();
        if ($now - ($this->lastFinalAt[$deviceKey] ?? -INF) < self::REPEATED_FINAL_SECONDS) {
            return;
        }
        $this->lastFinalAt[$deviceKey] = $now;

        $at = gmdate('Y-m-d\TH:i:s\Z', (int)$now);
        $profile = $this->onlineUser($deviceKey);
        $user = $profile !== null ? ['scaleUserId' => (string)$profile['id']] : [];
        $this->emit($context, 'weight', $nativeType, ['weightKg' => $frame['weightKg']] + $user, $at);
        if ($frame['heartRateBpm'] !== null) {
            $this->emit($context, 'heart_rate', $nativeType, ['bpm' => $frame['heartRateBpm']] + $user, $at);
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
            ], static fn (mixed $value): bool => $value !== null) + $user, $at);

            $composition = $profile !== null
                ? BodyComposition::of($frame['weightKg'], $frame['impedance100KhzOhm'], ['ageYears' => LefuTorreScaleUsers::ageOn($profile, gmdate('Y-m-d', (int)$now))] + $profile)
                : null;
            if ($composition !== null) {
                $this->emit($context, 'body_composition', $nativeType, $composition + $user, $at);
            }
        }
    }

    /**
     * Quem está em linha é o último que a balança aceitou, e ela lembra-se dele entre ligações; antes
     * de ela aceitar algum, vale o da lista do hub.
     *
     * @return array<string, mixed>|null
     */
    private function onlineUser(string $deviceKey): ?array
    {
        $users = $this->scaleUsers !== null ? ($this->scaleUsers)($deviceKey) : null;
        $online = $this->onlineUsers->get($deviceKey) ?: (string)($users['onlineUserId'] ?? '');
        foreach ($online !== '' && is_array($users['items'] ?? null) ? $users['items'] : [] as $user) {
            if (is_array($user) && ($user['id'] ?? null) === $online) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Os registos que a balança guardou de um membro, pelas mesmas capacidades da pesagem ao vivo
     * e com a data dela. O SDK descarta os anteriores a 2023-02-03, e o hub também.
     *
     * @param array<string, mixed> $frame
     */
    private function history(GattDeviceContext $context, array $frame, string $nativeType): void
    {
        $user = ['scaleUserId' => (string)$frame['memberId'], 'fromHistory' => true];
        // O relógio leva a parte do fuso abaixo da hora (`TorreCommandEncoder::clock`), e sai aqui.
        $halfHour = (($this->sessions[$context->deviceKey]['offset'] ?? 0) % 60) * 60;
        $seen = array_flip($this->historySeen->all($context->deviceKey));
        foreach ($frame['records'] as $record) {
            // Cada leitura traz o histórico todo do membro: o que já saiu não sai outra vez.
            $key = $frame['memberId'] . '|' . $record['at'];
            if ($record['at'] < self::HISTORY_EPOCH || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->historySeen->add($context->deviceKey, $key);
            $at = gmdate('Y-m-d\TH:i:s\Z', $record['at'] - $halfHour);
            $this->emit($context, 'weight', $nativeType, ['weightKg' => $record['weightKg']] + $user, $at);
            if ($record['heartRateBpm'] !== null) {
                $this->emit($context, 'heart_rate', $nativeType, ['bpm' => $record['heartRateBpm']] + $user, $at);
            }
            // Calçado não se mede a impedância, e a balança guarda-a a zero.
            if ($record['impedance50KhzOhm'] <= 0) {
                continue;
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
    private function setting(GattDeviceContext $context, array $frame, string $nativeType): void
    {
        $this->emit($context, 'device_config', $nativeType, ['settings' => $frame['settings']]);
        $this->commands?->markCommandReply($context->deviceKey, (string)$frame['replyType'], null, '', true);
    }

    /** @param array<string, mixed> $frame */
    private function reply(GattDeviceContext $context, array $frame): void
    {
        if ($frame['replyType'] === self::SELECT_REPLY && $frame['accepted']) {
            $this->rememberSelected($context->deviceKey);
        }
        $this->commands?->markCommandReply($context->deviceKey, (string)$frame['replyType'], null, '', (bool)$frame['accepted'], (string)($frame['error'] ?? ''));
    }

    /** O `07` não diz quem: é o do pedido de pôr em linha que está à espera dele. */
    private function rememberSelected(string $deviceKey): void
    {
        foreach ($this->commands?->commands($deviceKey) ?? [] as $command) {
            $id = $command['payload']['id'] ?? null;
            if (($command['status'] ?? '') === 'waiting' && ($command['nativeType'] ?? '') === 'selectScaleUser' && is_string($id)) {
                $this->onlineUsers->set($deviceKey, $id);
                return;
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function emit(GattDeviceContext $context, string $type, string $nativeType, array $data, ?string $occurredAt = null): void
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
