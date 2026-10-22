<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\JoyTech;

use Hub\Device\CommercialModelResolver;
use Hub\Device\HubMqttBridge;
use Hub\Device\RedisPendingDownlinkQueue;
use Hub\Domain\ProtocolRegistry;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\Gateway\GattBridge;
use Hub\Ingress\Mqtt\Gateway\GattDeviceContext;
use Hub\Ingress\Mqtt\Gateway\GattDownlinks;
use Hub\Log\Logger;
use Hub\Registry\Denylist;
use Hub\Registry\Whitelist;
use Hub\State\DeviceCommandLog;
use Hub\State\DeviceReportStore;

/**
 * Ingestão dos tensiómetros JoyTech entregues por um gateway GATT, que reencaminha cada trama do
 * `FFF1` inteira: o protocolo é todo do hub, à ida e à volta.
 */
final class JoyTechBpBridge extends GattBridge
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

    /** Depois das 6 repetições do resultado ao vivo, de 2 em 2 s. */
    private const READ_AFTER_RESULT_SECONDS = 15.0;

    private const ECG_HZ = 250;

    /** Uma hora do aparelho mais à frente do que isto não é plausível. */
    private const FUTURE_TOLERANCE_SECONDS = 300;

    /**
     * Por sessão: se a memória já foi lida até ao fim e quando se volta a pedir. Recomeça com cada
     * sessão que se abre.
     *
     * @var array<string, array{read: bool, readAt: ?float}>
     */
    private array $memory = [];

    /**
     * Até quando uma trama `0x83` é da memória, as marcas como estavam quando a leitura começou e
     * a hora mais recente lida de cada grupo, que só passa a marca no fim: a memória é um anel.
     *
     * @var array<string, array{until: float, marks: array<int, int>, latest: array<int, int>}>
     */
    private array $reading = [];

    /** @var array<string, array<string, float>> quando chegou cada resultado, para as repetições */
    private array $seen = [];

    private readonly EcgRecordings $ecg;

    public function __construct(
        Whitelist $whitelist,
        HubMqttBridge $mqttBridge,
        GatewayDeviceLinkLookup $links,
        ?RedisPendingDownlinkQueue $queue,
        string $topicFilter,
        private readonly BloodPressureHistoryMarks $marks,
        null|(DeviceReportStore&DeviceCommandLog) $deviceStore = null,
        ?callable $clock = null,
        ?CommercialModelResolver $commercialModelResolver = null,
        ?Denylist $denylist = null,
    ) {
        parent::__construct(
            $whitelist,
            $mqttBridge,
            $links,
            self::PROTOCOL,
            self::DEVICE_TYPE,
            $deviceStore,
            $clock,
            $commercialModelResolver,
            $denylist,
            new GattDownlinks($queue, $mqttBridge, $deviceStore, $topicFilter, (new JoyTechCommandEncoder())->writes(...)),
        );
        $this->ecg = new EcgRecordings();
    }

    protected function dispatchSession(string $deviceKey): void
    {
        $readAt = $this->memory[$deviceKey]['readAt'] ?? null;
        if ($readAt !== null && $this->clockNow() >= $readAt) {
            $this->memory[$deviceKey]['readAt'] = null;
            $this->readMemory($deviceKey);
        }
        $this->dispatchAndRead($deviceKey);
    }

    private function readMemory(string $deviceKey): void
    {
        $session = $this->sessions[$deviceKey];
        $this->downlinks?->sendDirect($deviceKey, $session['gateway'], self::READ_MEMORY, [], 0, $session['offset']);
        $this->startReading($deviceKey);
    }

    /** O mesmo tópico serve outras marcas, e a mesma fonte os termómetros: o nome distingue-os. */
    public static function claims(array $message): bool
    {
        return ($message['source'] ?? null) === 'joytech-node'
            && str_starts_with(trim((string)($message['device']['model'] ?? '')), 'DBP');
    }

    protected function handleKind(GattDeviceContext $context, string $kind, array $data, int $offsetMinutes, array $message): void
    {
        match ($kind) {
            'session' => $this->session($context, ($data['connected'] ?? false) === true, $offsetMinutes),
            'frame' => $this->frame($context, (string)($data['hex'] ?? ''), $offsetMinutes),
            default => null,
        };
    }

    private function openMemorySession(GattDeviceContext $context, int $offsetMinutes): void
    {
        $this->openSession($context, 0, $offsetMinutes);
        $this->memory[$context->deviceKey] = ['read' => false, 'readAt' => null];
    }

    private function session(GattDeviceContext $context, bool $connected, int $offsetMinutes): void
    {
        $deviceKey = $context->deviceKey;
        if (!$connected) {
            unset($this->reading[$deviceKey]);
            $this->ecg->drop($deviceKey);
            unset($this->sessions[$deviceKey]);
            $this->presence($context)->markOffline($deviceKey, $context->device);
            return;
        }

        $this->openMemorySession($context, $offsetMinutes);
        $this->presence($context)->markOnline($deviceKey, $context->device);

        // O aparelho não pede a hora, e a memória é datada por ela: acerta-se a cada ligação.
        $this->downlinks?->sendDirect($deviceKey, $context->gatewayKey, 'calibrateClock', [], 0, $offsetMinutes);
        if (!$this->dispatchAndRead($deviceKey) && $this->readsMemoryUnasked($context)) {
            // A meio de uma medição o aparelho ignora-o, e volta a pedir-se depois do resultado.
            $this->readMemory($deviceKey);
        }
    }

    /** @return bool se saiu uma leitura da memória pedida */
    private function dispatchAndRead(string $deviceKey): bool
    {
        if (!in_array(self::READ_MEMORY, $this->dispatch($deviceKey), true)) {
            return false;
        }
        $this->startReading($deviceKey);

        return true;
    }

    private function startReading(string $deviceKey): void
    {
        $marks = [];
        foreach ([1, 2, 3, 4] as $group) {
            $marks[$group] = $this->marks->mark($deviceKey, $group);
        }
        $this->reading[$deviceKey] = ['until' => $this->clockNow() + self::MEMORY_SILENCE_SECONDS, 'marks' => $marks, 'latest' => []];
    }

    /** Só no `0x8B`: uma leitura interrompida deixa a marca onde estava, e o que já saiu está na lista. */
    private function finishReading(string $deviceKey): void
    {
        foreach ($this->reading[$deviceKey]['latest'] ?? [] as $group => $at) {
            $this->marks->advance($deviceKey, $group, $at);
        }
        unset($this->reading[$deviceKey]);
    }

    private function frame(GattDeviceContext $context, string $hex, int $offsetMinutes): void
    {
        $frame = JoyTechFrame::decode($hex);
        if ($frame === null) {
            return;
        }
        // Um hub reiniciado com o aparelho ligado não viu a `session`.
        if (!isset($this->sessions[$context->deviceKey])) {
            $this->openMemorySession($context, $offsetMinutes);
        }

        if ($frame['function'] === 0x8b) {
            $this->finishReading($context->deviceKey);
            $this->memory[$context->deviceKey]['read'] = true;
            return;
        }
        if ($frame['function'] === 0x83 && strlen($frame['data']) >= 12) {
            $this->result($context, $frame['data'], $offsetMinutes);
            return;
        }
        $this->ecgFrame($context, $frame['function'], $frame['data'], $offsetMinutes);
    }

    /** O ECG do DBP-6679B: sem o `0x05` na contagem o aparelho não manda nada, e o fim só pára com o `0x06`. */
    private function ecgFrame(GattDeviceContext $context, int $function, string $data, int $offsetMinutes): void
    {
        $deviceKey = $context->deviceKey;
        $now = $this->clockNow();
        if ($function === 0x85 && $this->ecg->countdown($deviceKey, $now)) {
            $this->downlinks?->sendDirect($deviceKey, $context->gatewayKey, 'startEcg', [], 0, $offsetMinutes);
        } elseif ($function === 0x87) {
            $this->ecg->samples($deviceKey, $data);
        } elseif ($function === 0x86 && strlen($data) >= 9) {
            $end = $this->ecg->end($deviceKey, $now);
            if ($end['answer']) {
                $this->downlinks?->sendDirect($deviceKey, $context->gatewayKey, 'acknowledgeEcg', [], 0, $offsetMinutes);
            }
            if ($end['samples'] === null) {
                if ($end['answer']) {
                    Logger::channel('hub')->warning("ECG de {$deviceKey} interrompido: " . ($end['count'] ?? 0) . ' amostras');
                }
                return;
            }
            // Estado, FC, um byte a zero, ano, mês, dia, hora, minuto e grupo.
            $fields = array_values(unpack('C9', $data) ?: []);
            $at = JoyTechFrame::unixTime($fields[3], $fields[4], $fields[5], $fields[6], $fields[7], $offsetMinutes);
            $plausible = $at !== null && $at >= self::HISTORY_EPOCH && $at <= $now + self::FUTURE_TOLERANCE_SECONDS;
            $this->telemetry($context, 'ecg', 'fff1:86', [
                'samples' => $end['samples'],
                'frequencyHz' => self::ECG_HZ,
                'heartRateBpm' => $fields[1],
            ], gmdate('Y-m-d\TH:i:s\Z', $plausible ? (int)$at : (int)$now));
        }
    }

    /** O 6292B e o 6679B partilham a ponte; o protocolo diz qual mede o ECG. */
    protected function protocolOf(GattDeviceContext $context): string
    {
        $protocol = ProtocolRegistry::forModel((string)($context->device['supplier'] ?? ''), (string)($context->device['model'] ?? ''));

        return $protocol !== '' ? $protocol : self::PROTOCOL;
    }

    /** O HC-6679B só manda a memória com o ecrã MEM aberto, e lê-se só a pedido. */
    private function readsMemoryUnasked(GattDeviceContext $context): bool
    {
        return $this->protocolOf($context) !== 'joytech-bp-ecg';
    }

    /** @param string $data sistólica e diastólica em 2 B, pulso, arritmia, ano, mês, dia, hora, minuto, grupo */
    private function result(GattDeviceContext $context, string $data, int $offsetMinutes): void
    {
        $deviceKey = $context->deviceKey;
        $now = $this->clockNow();
        $reading = $this->reading[$deviceKey] ?? null;
        $fromHistory = $reading !== null && $now <= $reading['until'];
        if ($reading !== null && !$fromHistory) {
            unset($this->reading[$deviceKey]);
        }
        // Uma repetição também é um registo da leitura, e mantém-na aberta.
        if ($fromHistory) {
            $this->reading[$deviceKey]['until'] = $now + self::MEMORY_SILENCE_SECONDS;
        }
        if ($this->repeated($deviceKey, $data, $now)) {
            return;
        }

        $fields = unpack('nsystolic/ndiastolic/Cpulse/Cirregular/Cyear/Cmonth/Cday/Chour/Cminute/Cgroup', $data);
        if ($fields === false) {
            return;
        }
        $group = (int)$fields['group'];
        // A marca e a lista guardam a hora local do aparelho, que a mudança de hora não mexe.
        $local = JoyTechFrame::unixTime((int)$fields['year'], (int)$fields['month'], (int)$fields['day'], (int)$fields['hour'], (int)$fields['minute'], 0);
        $at = $local === null ? null : $local - $offsetMinutes * 60;
        $plausible = $at !== null && $at >= self::HISTORY_EPOCH && $at <= $now + self::FUTURE_TOLERANCE_SECONDS;

        if ($fromHistory) {
            if (!$plausible || $local <= ($reading['marks'][$group] ?? 0)) {
                return;
            }
            $this->reading[$deviceKey]['latest'][$group] = max($local, $reading['latest'][$group] ?? 0);
        }
        // Já saiu, ou é da memória fora de uma leitura: um ao vivo é sempre mais recente do que a marca.
        if ($plausible && ($this->marks->wasLive($deviceKey, $group, $data) || (!$fromHistory && $local <= $this->marks->mark($deviceKey, $group)))) {
            return;
        }
        if ($plausible) {
            // A marca só avança no fim de uma leitura; até lá, o que saiu fica nesta lista e não se repete.
            $this->marks->rememberLive($deviceKey, $group, $data, (int)$local);
        }
        if (!$fromHistory && isset($this->sessions[$deviceKey]) && !($this->memory[$deviceKey]['read'] ?? false) && $this->readsMemoryUnasked($context)) {
            $this->memory[$deviceKey]['readAt'] = $now + self::READ_AFTER_RESULT_SECONDS;
        }

        // Ao vivo, a hora de chegada: o relógio do aparelho pode estar por acertar.
        $occurredAt = gmdate('Y-m-d\TH:i:s\Z', $fromHistory ? (int)$at : (int)$now);
        $history = $fromHistory ? ['fromHistory' => true] : [];
        $nativeType = 'fff1:83';
        $this->telemetry($context, 'blood_pressure', $nativeType, [
            'systolicMmHg' => (int)$fields['systolic'],
            'diastolicMmHg' => (int)$fields['diastolic'],
            'irregularHeartbeat' => $fields['irregular'] === 0xff,
            'memoryGroup' => $group,
        ] + $history, $occurredAt);
        $this->telemetry($context, 'heart_rate', $nativeType, ['bpm' => (int)$fields['pulse']] + $history, $occurredAt);
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
}
