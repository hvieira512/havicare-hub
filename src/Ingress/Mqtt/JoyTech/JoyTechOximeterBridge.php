<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\JoyTech;

use Hub\Device\CommercialModelResolver;
use Hub\Device\HubMqttBridge;
use Hub\Device\TelemetryEnvelope;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\DispatchesQueued;
use Hub\Ingress\Mqtt\Gateway\GattDeviceContext;
use Hub\Ingress\Mqtt\MqttBridgeBase;
use Hub\Ingress\Mqtt\Veepoo\BraceletPresence;
use Hub\Registry\Denylist;
use Hub\Registry\Whitelist;
use Hub\State\DeviceCommandLog;
use Hub\State\DeviceReportStore;
use PhpMqtt\Client\MqttClient;

/**
 * Ingestão dos oxímetros de dedo JoyTech entregues por um gateway GATT. O aparelho manda a leitura
 * corrente 11 vezes por segundo, sem trama de fim; uma medição sai uma vez, quando acaba.
 */
final class JoyTechOximeterBridge extends MqttBridgeBase implements DispatchesQueued
{
    private const PROTOCOL = 'joytech-spo2';

    private const DEVICE_TYPE = 'pulse_oximeter';

    private const NATIVE_TYPE = 'fff1:88';

    /** Sem tramas válidas este tempo, a medição acabou: tirar o dedo não manda nada. */
    private const END_SILENCE_SECONDS = 5.0;

    /** A janela de que sai o valor, antes da última trama válida. */
    private const WINDOW_SECONDS = 5.0;

    /** Menos do que isto é o dedo a entrar e a sair. */
    private const MIN_DURATION_SECONDS = 3.0;

    /** A barra de uma leitura vai de 0 a 9; à procura do pulso traz o `0x20`, e fica acima. */
    private const MAX_BAR = 9;

    /** @var array<string, array{context: GattDeviceContext, firstAt: float, lastAt: float, samples: list<array{float, int, int}>}> */
    private array $measurements = [];

    private readonly BraceletPresence $presence;

    public function __construct(
        MqttClient $subscriber,
        Whitelist $whitelist,
        HubMqttBridge $mqttBridge,
        private readonly GatewayDeviceLinkLookup $links,
        string $topicFilter,
        ?callable $reconnectSubscriber = null,
        null|(DeviceReportStore&DeviceCommandLog) $deviceStore = null,
        ?callable $clock = null,
        ?CommercialModelResolver $commercialModelResolver = null,
        ?Denylist $denylist = null,
    ) {
        parent::__construct(
            $subscriber,
            $whitelist,
            $mqttBridge,
            $topicFilter,
            'joytech-oximeter',
            $reconnectSubscriber,
            $deviceStore,
            denylist: $denylist,
            clock: $clock,
            commercialModelResolver: $commercialModelResolver,
        );
        $this->presence = new BraceletPresence($mqttBridge, $deviceStore, self::PROTOCOL, self::DEVICE_TYPE);
    }

    public function dispatchQueued(): void
    {
        $now = $this->clockNow();
        foreach ($this->measurements as $deviceKey => $measurement) {
            if ($now - $measurement['lastAt'] >= self::END_SILENCE_SECONDS) {
                $this->finish((string)$deviceKey);
            }
        }
    }

    protected function handleMessage(string $topic, string $payload): void
    {
        $message = json_decode($payload, true);
        // O gateway entrega os outros JoyTech pela mesma fonte: o nome do aparelho distingue-os.
        if (
            !is_array($message)
            || ($message['source'] ?? null) !== 'joytech-node'
            || !str_starts_with(trim((string)($message['device']['model'] ?? '')), 'XM')
        ) {
            return;
        }

        $context = $this->resolveGattDevice($topic, $message, $this->links, self::PROTOCOL, self::DEVICE_TYPE);
        if ($context === null) {
            return;
        }

        $this->mqttBridge->publishRaw($context->deviceKey, $message, self::DEVICE_TYPE, $context->licenseId, $context->company);

        $data = is_array($message['payload'] ?? null) ? $message['payload'] : [];
        match ($message['kind'] ?? null) {
            'session' => $this->session($context, ($data['connected'] ?? false) === true),
            'frame' => $this->frame($context, (string)($data['hex'] ?? '')),
            default => null,
        };
    }

    private function session(GattDeviceContext $context, bool $connected): void
    {
        if ($connected) {
            $this->presence->markOnline($context->deviceKey, $context->device, $context->licenseId, $context->company);
            return;
        }

        $this->finish($context->deviceKey);
        $this->presence->markOffline($context->deviceKey, $context->device, $context->licenseId, $context->company);
    }

    /** O 0x88: SpO2 em %, pulso em bpm, PI, barra e onda, um byte cada. O PI vem sempre a zero. */
    private function frame(GattDeviceContext $context, string $hex): void
    {
        $frame = JoyTechFrame::decode($hex);
        if ($frame === null || $frame['function'] !== 0x88 || strlen($frame['data']) < 4) {
            return;
        }

        [$spo2, $pulse, , $bar] = array_values(unpack('C4', $frame['data']) ?: []);
        if ($spo2 === 0 || $spo2 > 100 || $pulse === 0 || $bar > self::MAX_BAR) {
            return;
        }

        $now = $this->clockNow();
        $measurement = $this->measurements[$context->deviceKey] ?? ['context' => $context, 'firstAt' => $now, 'lastAt' => $now, 'samples' => []];
        $measurement['context'] = $context;
        $measurement['lastAt'] = $now;
        $measurement['samples'][] = [$now, $spo2, $pulse];
        $measurement['samples'] = array_values(array_filter(
            $measurement['samples'],
            static fn (array $sample): bool => $sample[0] >= $now - self::WINDOW_SECONDS,
        ));
        $this->measurements[$context->deviceKey] = $measurement;
    }

    private function finish(string $deviceKey): void
    {
        $measurement = $this->measurements[$deviceKey] ?? null;
        unset($this->measurements[$deviceKey]);
        if ($measurement === null || $measurement['lastAt'] - $measurement['firstAt'] < self::MIN_DURATION_SECONDS) {
            return;
        }

        $occurredAt = gmdate('Y-m-d\TH:i:s\Z', (int)$measurement['lastAt']);
        $this->emit($measurement['context'], 'blood_oxygen', ['spo2Percent' => self::median(array_column($measurement['samples'], 1))], $occurredAt);
        $this->emit($measurement['context'], 'heart_rate', ['bpm' => self::median(array_column($measurement['samples'], 2))], $occurredAt);
    }

    /** @param list<int> $values a de cima, num número par: é sempre um valor que o aparelho mostrou */
    private static function median(array $values): int
    {
        sort($values);

        return $values[intdiv(count($values), 2)];
    }

    /** @param array<string, mixed> $data */
    private function emit(GattDeviceContext $context, string $type, array $data, string $occurredAt): void
    {
        $telemetry = TelemetryEnvelope::for($type, $context->deviceKey, $context->device, self::PROTOCOL, self::NATIVE_TYPE, $data, $context->gatewayKey);
        $telemetry['occurredAt'] = $occurredAt;
        $this->mqttBridge->publishTelemetry($context->deviceKey, $telemetry, self::DEVICE_TYPE, $context->licenseId, $context->company);
        $this->deviceStore?->append($context->deviceKey, 'telemetry', $telemetry + [
            'deviceType' => self::DEVICE_TYPE,
            'licenseId' => $context->licenseId,
        ]);
    }
}
