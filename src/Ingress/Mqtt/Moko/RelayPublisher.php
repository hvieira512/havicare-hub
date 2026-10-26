<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

use Hub\State\DeviceReportStore;
use Hub\Device\CommercialModelResolver;
use Hub\Device\ConnectionAnnouncer;
use Hub\Device\DeviceDescriptor;
use Hub\Device\HubMqttBridge;
use Hub\Domain\DeviceMetadata;
use Hub\Domain\ProtocolRegistry;
use Hub\Domain\ProximityAlarm;
use Hub\Domain\ProximityAlarmLookup;
use Hub\Ingress\Mqtt\Gateway\ObservationStateStore;
use Hub\Registry\Whitelist;
use Hub\Support\Throttle;
use Hub\Support\Values;

/**
 * Põe no fio o que um gateway ouviu de um aparelho retransmitido: o MQTT leva tudo, o
 * histórico da dashboard uma amostra.
 */
final class RelayPublisher
{
    /** Como cada tipo retransmitido reporta, para quando não há observação de onde o ler. */
    private const RELAYED_PROTOCOLS = [
        'bracelet' => 'moko-w6b',
        'diaper_sensor' => 'monit-mecs-pro-ble',
    ];

    private readonly Throttle $rawHistory;

    private ?ProximityTracker $proximity = null;

    private \Closure $clock;

    /**
     * O último alarme de proximidade por par (dispositivo, gateway), para quem fica à porta não dar
     * um por segundo.
     *
     * ponytail: em memória, perde-se num reinício; no pior caso o mesmo par dispara uma vez a mais.
     *
     * @var array<string, float>
     */
    private array $proximityAlarmedAt = [];

    private readonly ConnectionAnnouncer $connections;

    public function __construct(
        private readonly HubMqttBridge $mqttBridge,
        private readonly ?DeviceReportStore $deviceStore,
        private readonly ObservationStateStore $state,
        private readonly Whitelist $whitelist,
        private readonly ?CommercialModelResolver $commercialModelResolver,
        private readonly int $telemetryRefreshSeconds,
        private readonly int $rawHistorySampleSeconds,
        ?callable $clock = null,
        private readonly ?ProximityAlarmLookup $proximityAlarm = null,
    ) {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): float => microtime(true);
        $this->connections = new ConnectionAnnouncer($mqttBridge, $deviceStore);
        $this->rawHistory = new Throttle();
    }

    /**
     * Guarda a observação crua no histórico do aparelho retransmitido, e não no do gateway,
     * que ficaria afogado. Só para aparelhos já autorizados.
     *
     * @param array<string, mixed> $device
     * @param array<string, mixed> $gateway
     * @param array<string, mixed> $observation
     */
    public function recordRaw(array $device, array $gateway, string $protocol, array $observation): void
    {
        $deviceKey = (string)$device['imei'];
        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        $raw = [
            'direction' => 'uplink',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => DeviceDescriptor::of((string)$device['imei'], $device),
            'data' => $observation,
            'debug' => [
                'protocol' => $protocol,
                'transport' => 'ble_gateway',
                'encoding' => 'json',
                'payload' => $observation,
                'gatewayId' => (string)$gateway['imei'],
            ],
        ];
        // O MQTT leva todas as observações; o histórico, uma amostra por dispositivo.
        $this->mqttBridge->publishRaw($deviceKey, $raw, $deviceType, $licenseId, $company);
        if ($this->deviceStore !== null && $this->rawHistory->allow($deviceKey, $this->rawHistorySampleSeconds, (float)($this->clock)())) {
            $this->deviceStore->append($deviceKey, 'raw', $raw + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }
    }

    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed> $gateway
     * @param array<string, mixed> $normalized
     */
    public function publishTelemetry(
        array $device,
        array $gateway,
        string $protocol,
        array $normalized,
        mixed $rssiDbm,
    ): void {
        $deviceKey = (string)$device['imei'];
        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        $cameOnline = $this->deviceStore?->deviceSeen($deviceKey, [
            'supplier' => (string)$device['supplier'], 'model' => (string)$device['model'],
            'deviceType' => $deviceType, 'licenseId' => $licenseId, 'company' => $company,
            'protocol' => $protocol, 'transport' => 'ble_gateway', 'gatewayId' => (string)$gateway['imei'],
        ]);
        // Desligado dá-o a manutenção do hub, quando nenhum gateway o ouve durante o prazo.
        if ($cameOnline === true) {
            $this->connections->announce($deviceKey, $device, true);
        }
        $this->recordSignal($device, $gateway, $protocol, $rssiDbm);

        foreach ($normalized['telemetry'] as $capability => $telemetry) {
            if (!$this->state->shouldPublish($deviceKey, (string)$capability, $telemetry, $this->telemetryRefreshSeconds, (string)$gateway['imei'])) {
                continue;
            }
            $this->mqttBridge->publishAndStore($this->deviceStore, 'telemetry', $deviceKey, $telemetry, $deviceType, $licenseId, $company);
        }
    }

    /**
     * Publica os eventos de um aparelho retransmitido no MQTT e no histórico dele.
     *
     * @param array<string, mixed> $device
     * @param array<string, mixed> $gateway
     * @param list<array<string, mixed>> $events
     */
    public function publishEvents(array $device, array $gateway, array $events): void
    {
        $deviceKey = (string)$device['imei'];
        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        foreach ($events as $event) {
            $this->mqttBridge->publishAndStore($this->deviceStore, 'events', $deviceKey, $event, $deviceType, $licenseId, $company);
        }
    }

    /**
     * Diz ao cliente quando um par se calou. `unknown` não é `far`: fora de alcance, bateria
     * descarregada e gateway offline são indistinguíveis. Reportado uma vez por par.
     */
    public function expireStaleProximity(): void
    {
        foreach ($this->proximity()->takeStale((float)($this->clock)()) as $pair) {
            $device = $this->whitelist->resolve($pair['deviceKey']);
            $gateway = $this->whitelist->resolve($pair['gatewayKey']);
            if ($device === null || $gateway === null) {
                continue;
            }
            try {
                $this->publishProximity(
                    $this->commercialModelResolver?->enrich($device) ?? $device,
                    $gateway,
                    self::relayedProtocol($device),
                    ['state' => 'unknown', 'samples' => 0],
                );
            } catch (\Throwable $e) {
                $this->mqttBridge->logPublishFailure('hub', (string)$pair['deviceKey'], $e);
            }
        }
    }

    /** Se este tipo de aparelho é dos que um gateway retransmite. */
    public static function relays(string $deviceType): bool
    {
        return isset(self::RELAYED_PROTOCOLS[$deviceType]);
    }

    /**
     * O protocolo de um aparelho retransmitido, pelo par fornecedor/modelo no `ProtocolRegistry`;
     * o tipo sozinho não chega e fica como último recurso.
     *
     * @param array<string, mixed> $device
     */
    public static function relayedProtocol(array $device): string
    {
        $protocol = ProtocolRegistry::forModel(
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
        );

        return $protocol !== ''
            ? $protocol
            : (self::RELAYED_PROTOCOLS[(string)($device['deviceType'] ?? '')] ?? 'moko-gateway');
    }

    /**
     * O sinal entre um dispositivo retransmitido e o gateway, publicado por avistamento fora do
     * `shouldPublish()`; não entra no histórico, que ficaria só com isto.
     *
     * @param array<string, mixed> $device o dispositivo retransmitido, já autorizado
     * @param array<string, mixed> $gateway
     */
    public function recordSignal(array $device, array $gateway, string $protocol, mixed $rssiDbm): void
    {
        $deviceKey = (string)$device['imei'];
        $gatewayKey = (string)$gateway['imei'];
        $this->deviceStore?->recordGatewaySighting(
            $deviceKey,
            $gatewayKey,
            is_numeric($rssiDbm) ? (int)$rssiDbm : null,
        );
        if (!is_numeric($rssiDbm)) {
            return;
        }

        $window = $this->proximity()->record($deviceKey, $gatewayKey, (int)$rssiDbm, (float)($this->clock)());
        $this->publishProximity($device, $gateway, $protocol, $window);
        $this->evaluateProximityAlarm($device, $gateway, $protocol, $window);
    }

    /**
     * A calibrar só marca o avistamento, para a dashboard mostrar quem dispararia.
     *
     * @param array<string, mixed> $device
     * @param array<string, mixed> $gateway
     * @param array<string, mixed> $window
     */
    private function evaluateProximityAlarm(array $device, array $gateway, string $protocol, array $window): void
    {
        $deviceKey = (string)$device['imei'];
        $gatewayKey = (string)$gateway['imei'];
        $settings = $this->proximityAlarm?->forGateway($gatewayKey) ?? ProximityAlarm::defaults();
        if (!ProximityAlarm::watches($settings, $deviceKey) || !ProximityAlarm::fires($settings, $window)) {
            return;
        }
        if ($settings['mode'] === 'calibrating') {
            $this->deviceStore?->recordProximityAlarmCandidate($deviceKey, $gatewayKey);
            return;
        }

        $now = (float)($this->clock)();
        $pair = $deviceKey . '|' . $gatewayKey;
        if ($now - ($this->proximityAlarmedAt[$pair] ?? -INF) < (int)$settings['cooldownSeconds']) {
            return;
        }
        $this->proximityAlarmedAt[$pair] = $now;

        $this->publishEvents($device, $gateway, [[
            'type' => 'proximity_alarm',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => DeviceDescriptor::of($deviceKey, $device),
            'data' => [
                'gatewayId' => $gatewayKey,
                'rssiDbm' => $window['rssiMaxDbm'],
                'window' => [
                    'maxDbm' => $window['rssiMaxDbm'],
                    'medianDbm' => $window['rssiMedianDbm'],
                    'minDbm' => $window['rssiMinDbm'],
                    'samples' => $window['samples'],
                ],
            ],
            'source' => ['protocol' => $protocol, 'gatewayId' => $gatewayKey],
        ]]);
    }

    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed> $gateway
     * @param array<string, mixed> $data
     */
    private function publishProximity(array $device, array $gateway, string $protocol, array $data): void
    {
        $this->mqttBridge->publishTelemetry(
            (string)$device['imei'],
            [
                'type' => 'proximity',
                'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'device' => DeviceDescriptor::of((string)$device['imei'], $device),
                'data' => ['gatewayId' => (string)$gateway['imei']] + $data,
                'source' => Values::withoutNulls([
                    'protocol' => $protocol,
                    'nativeType' => 'manufacturer_data',
                    'gatewayId' => (string)$gateway['imei'],
                    'rssiDbm' => $data['rssiDbm'] ?? null,
                ]),
            ],
            (string)$device['deviceType'],
            DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0),
            (string)($device['company'] ?? 'null'),
        );
    }

    /** Guardado, ao contrário dos decoders: leva a janela de amostras. */
    private function proximity(): ProximityTracker
    {
        return $this->proximity ??= new ProximityTracker();
    }
}
