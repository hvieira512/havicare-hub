<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\JoyTech;

use Hub\Device\CommercialModelResolver;
use Hub\Device\HubMqttBridge;
use Hub\Device\PendingDownlinkQueue;
use Hub\Device\TelemetryEnvelope;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\DispatchesQueued;
use Hub\Ingress\Mqtt\Gateway\GattDeviceContext;
use Hub\Ingress\Mqtt\Gateway\GattDownlinks;
use Hub\Ingress\Mqtt\Gateway\GatewayTopic;
use Hub\Ingress\Mqtt\MqttBridgeBase;
use Hub\Ingress\Mqtt\Veepoo\BraceletPresence;
use Hub\Registry\Whitelist;
use Hub\State\DeviceCommandLog;
use Hub\State\DeviceReportStore;
use PhpMqtt\Client\MqttClient;

/**
 * Ingestão dos tensiómetros JoyTech entregues por um gateway GATT, que reencaminha cada trama do
 * `FFF1` inteira: o protocolo é todo do hub, à ida e à volta.
 */
final class JoyTechBpBridge extends MqttBridgeBase implements DispatchesQueued
{
    private const PROTOCOL = 'joytech-bp';

    private const DEVICE_TYPE = 'blood_pressure_monitor';

    private const READ_MEMORY = 'readBloodPressureHistory';

    /** O resultado ao vivo repete-se 6 vezes, de 2 em 2 s. */
    private const REPEAT_SECONDS = 15.0;

    /** Os registos da memória chegam a cada 100 ms; este silêncio quer dizer que a leitura não começou. */
    private const MEMORY_SILENCE_SECONDS = 3.0;

    /** 2025-01-01: as medições com o relógio por acertar vêm de 2021. */
    private const HISTORY_EPOCH = 1735689600;

    /** Uma hora do aparelho mais à frente do que isto não é plausível. */
    private const FUTURE_TOLERANCE_SECONDS = 300;

    /** @var array<string, array{gateway: string, offset: int}> */
    private array $sessions = [];

    /**
     * Até quando uma trama `0x83` é da memória, as marcas como estavam quando a leitura começou e
     * a hora mais recente lida de cada grupo, que só passa a marca no fim: a memória é um anel.
     *
     * @var array<string, array{until: float, marks: array<int, int>, latest: array<int, int>}>
     */
    private array $reading = [];

    /** @var array<string, array<string, float>> quando chegou cada resultado, para as repetições */
    private array $seen = [];

    private readonly GattDownlinks $downlinks;

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
        private readonly BloodPressureHistoryMarks $marks = new BloodPressureHistoryMarks(),
    ) {
        parent::__construct(
            $subscriber,
            $whitelist,
            $mqttBridge,
            $topicFilter,
            'joytech',
            $reconnectSubscriber,
            $deviceStore,
            clock: $clock,
            commercialModelResolver: $commercialModelResolver,
        );
        $this->commands = $deviceStore;
        $this->downlinks = new GattDownlinks($queue, $mqttBridge, $deviceStore, $topicFilter, (new JoyTechCommandEncoder())->writes(...));
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
        if (!is_array($message) || ($message['source'] ?? null) !== 'joytech-node') {
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
                "Ignoring unlinked joytech device={$mac} gateway={$gateway['imei']}"
            );
            return;
        }

        $device = $this->enrichWithCommercialName($device);
        $context = new GattDeviceContext(
            (string)$device['imei'],
            (string)$gateway['imei'],
            $device,
            (int)($device['licenseId'] ?? 0),
            (string)($device['company'] ?? 'null'),
        );

        $this->mqttBridge->publishRaw($context->deviceKey, $message, self::DEVICE_TYPE, $context->licenseId, $context->company);

        $data = is_array($message['payload'] ?? null) ? $message['payload'] : [];
        $offset = is_int($message['tzOffsetMinutes'] ?? null) ? $message['tzOffsetMinutes'] : 0;
        match ($message['kind'] ?? null) {
            'session' => $this->session($context, ($data['connected'] ?? false) === true, $offset),
            'frame' => $this->frame($context, (string)($data['hex'] ?? ''), $offset),
            'command_result' => $this->downlinks->delivered(
                $context->deviceKey,
                (string)($data['dedupeKey'] ?? ''),
                ($data['ok'] ?? false) === true,
                (string)($data['error'] ?? ''),
            ),
            default => null,
        };
    }

    private function session(GattDeviceContext $context, bool $connected, int $offsetMinutes): void
    {
        $deviceKey = $context->deviceKey;
        if (!$connected) {
            $this->finishReading($deviceKey);
            unset($this->sessions[$deviceKey]);
            $this->presence->markOffline($deviceKey, $context->device, $context->licenseId, $context->company);
            return;
        }

        $this->sessions[$deviceKey] = ['gateway' => $context->gatewayKey, 'offset' => $offsetMinutes];
        $this->presence->markOnline($deviceKey, $context->device, $context->licenseId, $context->company);

        // O aparelho não pede a hora, e a memória é datada por ela: acerta-se a cada ligação.
        $this->downlinks->sendDirect($deviceKey, $context->gatewayKey, 'calibrateClock', [], 0, $offsetMinutes);
        if (!$this->dispatch($deviceKey)) {
            // A meio de uma medição o aparelho ignora o pedido: lê-se na ligação seguinte.
            $this->downlinks->sendDirect($deviceKey, $context->gatewayKey, self::READ_MEMORY, [], 0, $offsetMinutes);
            $this->startReading($deviceKey);
        }
    }

    /** @return bool se saiu uma leitura da memória pedida */
    private function dispatch(string $deviceKey): bool
    {
        $session = $this->sessions[$deviceKey] ?? null;
        if ($session === null) {
            return false;
        }

        $sent = $this->downlinks->dispatchPending($deviceKey, $session['gateway'], 0, $session['offset']);
        if (!in_array(self::READ_MEMORY, $sent, true)) {
            return false;
        }
        $this->startReading($deviceKey);

        return true;
    }

    private function startReading(string $deviceKey): void
    {
        $this->finishReading($deviceKey);
        $marks = [];
        foreach ([1, 2, 3, 4] as $group) {
            $marks[$group] = $this->marks->mark($deviceKey, $group);
        }
        $this->reading[$deviceKey] = ['until' => $this->clockNow() + self::MEMORY_SILENCE_SECONDS, 'marks' => $marks, 'latest' => []];
    }

    private function finishReading(string $deviceKey): void
    {
        foreach ($this->reading[$deviceKey]['latest'] ?? [] as $group => $at) {
            $this->marks->advance($deviceKey, $group, $at);
        }
        unset($this->reading[$deviceKey]);
    }

    private function frame(GattDeviceContext $context, string $hex, int $offsetMinutes): void
    {
        $frame = BpFrame::decode($hex);
        if ($frame === null) {
            return;
        }

        if ($frame['function'] === 0x8b) {
            $this->finishReading($context->deviceKey);
            $this->commands?->markCommandReply($context->deviceKey, 'joytech_8b', null, '', true);
            return;
        }
        if ($frame['function'] === 0x83 && strlen($frame['data']) >= 12) {
            $this->result($context, $frame['data'], $offsetMinutes);
        }
    }

    /** @param string $data sistólica e diastólica em 2 B, pulso, arritmia, ano, mês, dia, hora, minuto, grupo */
    private function result(GattDeviceContext $context, string $data, int $offsetMinutes): void
    {
        $deviceKey = $context->deviceKey;
        $now = $this->clockNow();
        if ($this->repeated($deviceKey, $data, $now)) {
            return;
        }

        $fields = unpack('nsystolic/ndiastolic/Cpulse/Cirregular/Cyear/Cmonth/Cday/Chour/Cminute/Cgroup', $data);
        if ($fields === false) {
            return;
        }
        $group = (int)$fields['group'];
        $at = self::deviceTime($fields, $offsetMinutes);
        $plausible = $at !== null && $at >= self::HISTORY_EPOCH && $at <= $now + self::FUTURE_TOLERANCE_SECONDS;

        $reading = $this->reading[$deviceKey] ?? null;
        $fromHistory = $reading !== null && $now <= $reading['until'];
        if ($reading !== null && !$fromHistory) {
            $this->finishReading($deviceKey);
        }
        if ($fromHistory) {
            $this->reading[$deviceKey]['until'] = $now + self::MEMORY_SILENCE_SECONDS;
            if (!$plausible || $at <= ($reading['marks'][$group] ?? 0)) {
                return;
            }
            $this->reading[$deviceKey]['latest'][$group] = max($at, $reading['latest'][$group] ?? 0);
            if ($this->marks->wasLive($deviceKey, $group, $data)) {
                return;
            }
        } elseif ($plausible) {
            // A marca não avança ao vivo: o que se mediu sem gateway antes disto ainda está na memória.
            $this->marks->rememberLive($deviceKey, $group, $data, $at);
        }

        // Ao vivo, a hora de chegada: o relógio do aparelho pode estar por acertar.
        $occurredAt = gmdate('Y-m-d\TH:i:s\Z', $fromHistory ? (int)$at : (int)$now);
        $history = $fromHistory ? ['fromHistory' => true] : [];
        $nativeType = 'fff1:83';
        $this->emit($context, 'blood_pressure', $nativeType, [
            'systolicMmHg' => (int)$fields['systolic'],
            'diastolicMmHg' => (int)$fields['diastolic'],
            'irregularHeartbeat' => $fields['irregular'] === 0xff,
            'memoryGroup' => $group,
        ] + $history, $occurredAt);
        $this->emit($context, 'heart_rate', $nativeType, ['bpm' => (int)$fields['pulse']] + $history, $occurredAt);
    }

    private function repeated(string $deviceKey, string $data, float $now): bool
    {
        $this->seen[$deviceKey] = array_filter(
            $this->seen[$deviceKey] ?? [],
            static fn (float $at): bool => $now - $at < self::REPEAT_SECONDS,
        );
        $repeated = isset($this->seen[$deviceKey][$data]);
        $this->seen[$deviceKey][$data] = $now;

        return $repeated;
    }

    /**
     * A hora local do aparelho, em unix.
     *
     * @param array<string, mixed> $fields
     */
    private static function deviceTime(array $fields, int $offsetMinutes): ?int
    {
        [$year, $month, $day, $hour, $minute] = [2000 + (int)$fields['year'], (int)$fields['month'], (int)$fields['day'], (int)$fields['hour'], (int)$fields['minute']];
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59) {
            return null;
        }

        return gmmktime($hour, $minute, 0, $month, $day, $year) - $offsetMinutes * 60;
    }

    /** @param array<string, mixed> $data */
    private function emit(GattDeviceContext $context, string $type, string $nativeType, array $data, string $occurredAt): void
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
        $telemetry['occurredAt'] = $occurredAt;
        $this->mqttBridge->publishTelemetry($context->deviceKey, $telemetry, self::DEVICE_TYPE, $context->licenseId, $context->company);
        $this->deviceStore?->append($context->deviceKey, 'telemetry', $telemetry + [
            'deviceType' => self::DEVICE_TYPE,
            'licenseId' => $context->licenseId,
        ]);
    }
}
