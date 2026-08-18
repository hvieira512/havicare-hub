<?php

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Domain\DeviceMetadata;

use Hub\CommercialModelResolver;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Log\Logger;
use Hub\RawPayload;

final class Bridge extends \Hub\Ingress\Mqtt\Bridge
{
    /**
     * How each relayed device type reports, keyed by device type. Needed when the
     * signal goes quiet, because there is no observation left to read it from.
     */
    private const RELAYED_PROTOCOLS = [
        'bracelet' => 'moko-w6r',
        'diaper_sensor' => 'monit-mecs-pro-ble',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $onlineGateways = [];
    /** @var array<string, float> */
    private array $gatewayLastSeenAt = [];
    private \Closure $clock;

    public function __construct(
        \PhpMqtt\Client\MqttClient $subscriber,
        \Hub\Registry\Whitelist $whitelist,
        \Hub\HubMqttBridge $mqttBridge,
        GatewayDeviceLinkLookup $links,
        ObservationStateStore $state,
        string $topicFilter = 'havicare-hub/null/0/gw/+/raw',
        ?callable $reconnectSubscriber = null,
        ?\Hub\Dashboard\DashboardStoreContract $dashboardStore = null,
        private readonly ?CommercialModelResolver $commercialModelResolver = null,
        private readonly int $dedupeTtlSeconds = 5,
        private readonly int $telemetryRefreshSeconds = 60,
        private readonly int $gatewayIdleTimeoutSeconds = 180,
        private readonly ?MessageDecoder $messageDecoder = null,
        private readonly ?MonitMecsProDecoder $monitDecoder = null,
        private readonly ?MonitNormalizer $monitNormalizer = null,
        private readonly ?GatewayNormalizer $gatewayNormalizer = null,
        private readonly ?W6rDecoder $w6rDecoder = null,
        private readonly ?W6rNormalizer $w6rNormalizer = null,
        ?callable $clock = null,
        private readonly ?ProximityTracker $proximityTracker = null,
    ) {
        parent::__construct(
            $subscriber,
            $whitelist,
            $mqttBridge,
            $topicFilter,
            sourceName: 'moko-gateway',
            reconnectSubscriber: $reconnectSubscriber,
            dashboardStore: $dashboardStore,
        );
        $this->links = $links;
        $this->state = $state;
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): float => microtime(true);
    }

    private readonly GatewayDeviceLinkLookup $links;
    private readonly ObservationStateStore $state;

    /**
     * Resolved once and kept, unlike the stateless decoders above which the call
     * sites build on demand: this one carries the sample window, so a fresh
     * instance per sighting would see an empty window every time.
     */
    private ?ProximityTracker $proximity = null;

    private function proximity(): ProximityTracker
    {
        return $this->proximity ??= $this->proximityTracker ?? new ProximityTracker();
    }

    public function tick(float $timeout = 0.01): void
    {
        parent::tick($timeout);
        $this->expireIdleGateways();
        $this->expireStaleProximity();
    }

    public function expireIdleGateways(): void
    {
        $now = ($this->clock)();
        foreach ($this->onlineGateways as $deviceKey => $gateway) {
            if ($now - ($this->gatewayLastSeenAt[$deviceKey] ?? $now) < $this->gatewayIdleTimeoutSeconds) {
                continue;
            }
            $deviceType = (string)$gateway['deviceType'];
            $licenseId = DeviceMetadata::normalizeLicenseId($gateway['licenseId'] ?? 0);
            $company = (string)($gateway['company'] ?? 'null');
            $status = RawPayload::status($deviceKey, (string)$gateway['supplier'], (string)$gateway['model'], 'offline', null, (string)($gateway['commercialName'] ?? ''));
            $event = RawPayload::event($deviceKey, (string)$gateway['supplier'], (string)$gateway['model'], 'device.disconnected', null, null, (string)($gateway['commercialName'] ?? ''));
            $this->mqttBridge->publishStatus($deviceKey, $status, true, $deviceType, $licenseId, $company);
            $this->mqttBridge->publishEvent($deviceKey, $event, $deviceType, $licenseId, $company);
            $this->dashboardStore?->deviceOffline($deviceKey);
            $this->dashboardStore?->append($deviceKey, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
            unset($this->onlineGateways[$deviceKey], $this->gatewayLastSeenAt[$deviceKey]);
        }
    }

    protected function handleMessage(string $topic, string $payload): void
    {
        $parsedTopic = Topic::parse($topic);
        if ($parsedTopic === null) {
            Logger::channel('hub')->warning("Ignoring unsupported MOKO gateway topic {$topic}");
            return;
        }

        $gateway = $this->whitelist->resolve($parsedTopic->gatewayMac);
        if ($gateway === null || ($gateway['deviceType'] ?? '') !== 'gateway') {
            $this->recordUnauthorizedDevice($parsedTopic->gatewayMac, 'moko-gateway', ident: $parsedTopic->gatewayMac);
            Logger::channel('hub')->warning("Ignoring unregistered MOKO gateway mac={$parsedTopic->gatewayMac}");
            return;
        }

        $decoded = ($this->messageDecoder ?? new MokoMessageDecoder())->decode($payload);
        if ($decoded === null || $decoded['gatewayMac'] !== $parsedTopic->gatewayMac) {
            Logger::channel('hub')->warning("Ignoring invalid MOKO gateway payload or gateway MAC mismatch on {$topic}");
            return;
        }
        $gateway = $this->enrich($gateway);
        $this->recordGateway($gateway, $decoded, $topic, $payload);

        if (in_array((string)$decoded['messageId'], ['3070', '30a0', '30b2'], true) && is_array($decoded['data'])) {
            foreach ($decoded['data'] as $observation) {
                if (is_array($observation)) {
                    $this->handleObservation($gateway, $observation);
                }
            }
        }
    }

    /** @param array<string, mixed> $gateway @param array<string, mixed> $decoded */
    private function recordGateway(array $gateway, array $decoded, string $sourceTopic, string $originalPayload): void
    {
        $deviceKey = (string)$gateway['imei'];
        $deviceType = (string)$gateway['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($gateway['licenseId'] ?? 0);
        $company = (string)($gateway['company'] ?? 'null');
        $this->gatewayLastSeenAt[$deviceKey] = ($this->clock)();
        $protocol = (string)($decoded['protocol'] ?? 'moko-gateway');
        $encoding = (string)($decoded['encoding'] ?? 'unknown');
        $raw = [
            'schemaVersion' => 1,
            'direction' => 'uplink',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->device($gateway),
            'data' => $decoded,
            'debug' => [
                'protocol' => $protocol,
                'transport' => 'mqtt',
                'encoding' => $encoding,
                'payload' => $encoding === 'json' ? json_decode($originalPayload, true) : bin2hex($originalPayload),
                'sourceTopic' => $sourceTopic,
            ],
        ];
        $this->mqttBridge->publishRaw($deviceKey, $raw, $deviceType, $licenseId, $company);
        $this->dashboardStore?->deviceSeen($deviceKey, [
            'supplier' => (string)$gateway['supplier'], 'model' => (string)$gateway['model'],
            'deviceType' => $deviceType, 'licenseId' => $licenseId, 'company' => $company,
            'protocol' => $protocol, 'transport' => 'mqtt', 'online' => '1',
        ]);
        $this->dashboardStore?->append($deviceKey, 'raw', $raw + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);

        foreach (($this->gatewayNormalizer ?? new GatewayNormalizer())->telemetry($decoded, $gateway) as $telemetry) {
            if (!$this->state->shouldPublish($deviceKey, (string)$telemetry['type'], $telemetry, $this->telemetryRefreshSeconds)) {
                continue;
            }
            $this->mqttBridge->publishTelemetry($deviceKey, $telemetry, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'telemetry', $telemetry + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }

        if (!isset($this->onlineGateways[$deviceKey])) {
            $this->onlineGateways[$deviceKey] = $gateway;
            $status = RawPayload::status($deviceKey, (string)$gateway['supplier'], (string)$gateway['model'], 'online', null, (string)($gateway['commercialName'] ?? ''));
            $event = RawPayload::event($deviceKey, (string)$gateway['supplier'], (string)$gateway['model'], 'device.connected', null, null, (string)($gateway['commercialName'] ?? ''));
            $this->mqttBridge->publishStatus($deviceKey, $status, true, $deviceType, $licenseId, $company);
            $this->mqttBridge->publishEvent($deviceKey, $event, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }
    }

    /**
     * A gateway relays every BLE device it can see, so each observation is
     * offered to the decoders in turn and the first one that recognises it
     * owns the payload.
     *
     * @param array<string, mixed> $gateway @param array<string, mixed> $observation
     */
    private function handleObservation(array $gateway, array $observation): void
    {
        $monit = ($this->monitDecoder ?? new MonitMecsProDecoder())->decode($observation);
        if ($monit !== null) {
            $this->handleMonitObservation($gateway, $monit);
            return;
        }

        $w6r = ($this->w6rDecoder ?? new W6rDecoder())->decode($observation);
        if ($w6r !== null) {
            $this->handleW6rObservation($gateway, $w6r);
        }
    }

    /**
     * Resolves a relayed device and confirms it is allowed to reach us through
     * this gateway.
     *
     * @param array<string, mixed> $gateway
     * @return array<string, mixed>|null
     */
    private function linkedDevice(array $gateway, string $deviceKey, string $deviceType, string $protocol): ?array
    {
        $device = $this->whitelist->resolve($deviceKey);
        if ($device === null || ($device['deviceType'] ?? '') !== $deviceType) {
            $this->recordUnauthorizedDevice($deviceKey, $protocol, ident: $deviceKey);
            return null;
        }

        if (
            !$this->links->isEnabled((string)$gateway['imei'], (string)$device['imei'])
            || (string)($gateway['company'] ?? 'null') !== (string)($device['company'] ?? 'null')
            || (string)$gateway['licenseId'] !== (string)$device['licenseId']
        ) {
            Logger::channel('hub')->warning(
                "Ignoring unlinked {$protocol} device={$deviceKey} gateway={$gateway['imei']}"
            );
            return null;
        }

        return $this->enrich($device);
    }

    /**
     * A pressed W6R broadcasts for 30 seconds, so the same trigger count
     * arrives many times over. Each press mode keeps its own counter, and only
     * a change is a new press.
     *
     * @param array<string, mixed> $gateway @param array<string, mixed> $decoded
     */
    private function handleW6rObservation(array $gateway, array $decoded): void
    {
        $deviceKey = (string)$decoded['mac'];
        $device = $this->linkedDevice($gateway, $deviceKey, 'bracelet', 'moko-w6r');
        if ($device === null) {
            return;
        }

        $previousTriggerCount = null;
        if (isset($decoded['alarm']['pressMode'], $decoded['alarm']['triggerCount'])) {
            $transition = $this->state->transitionCondition(
                $deviceKey . ':press:' . $decoded['alarm']['pressMode'],
                (string)$decoded['alarm']['triggerCount'],
            );
            // No press is reported either when the counter has not moved OR on the first
            // sighting of this bracelet: the counter is cumulative, so seeing it for the
            // first time says nothing about a press having just happened. This is the
            // opposite of the diaper condition below, where a first observation that is
            // already `change_required` MUST raise the alarm -- which is why the store
            // now reports both facts and each caller decides what to do with them.
            $previousTriggerCount = $transition === null || $transition['previous'] === null
                ? null
                : (int)$transition['previous'];
        }

        $normalized = ($this->w6rNormalizer ?? new W6rNormalizer())->normalize(
            $decoded,
            $device,
            (string)$gateway['imei'],
            $previousTriggerCount,
        );

        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        $this->dashboardStore?->deviceSeen($deviceKey, [
            'supplier' => (string)$device['supplier'], 'model' => (string)$device['model'],
            'deviceType' => $deviceType, 'licenseId' => $licenseId, 'company' => $company,
            'protocol' => 'moko-w6r', 'transport' => 'ble_gateway', 'online' => '1',
        ]);
        $this->recordSignal($device, $gateway, 'moko-w6r', $decoded['rssiDbm'] ?? null);

        foreach ($normalized['telemetry'] as $capability => $telemetry) {
            if (!$this->state->shouldPublish($deviceKey, $capability, $telemetry, $this->telemetryRefreshSeconds, (string)$gateway['imei'])) {
                continue;
            }
            $this->mqttBridge->publishTelemetry($deviceKey, $telemetry, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'telemetry', $telemetry + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }

        foreach ($normalized['events'] as $event) {
            $this->mqttBridge->publishEvent($deviceKey, $event, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($deviceKey, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }
    }

    /**
     * Report the signal between a relayed device and the gateway that heard it.
     *
     * Published per sighting rather than through shouldPublish(): that throttle
     * fingerprints the telemetry data, and the signal lives outside it, so a
     * sighting whose signal moved but whose readings did not was being dropped --
     * leaving the client a series with holes it could not see, and no way to know
     * its own statistics were computed on one.
     *
     * Not appended to the device history either. Every other telemetry is, but at
     * roughly forty sightings a minute per pair this would bury the history list
     * and the dashboard's telemetry table under readings nobody scrolls through.
     *
     * @param array<string, mixed> $device the relayed device, already authorized
     * @param array<string, mixed> $gateway
     */
    private function recordSignal(array $device, array $gateway, string $protocol, mixed $rssiDbm): void
    {
        $deviceKey = (string)$device['imei'];
        $gatewayKey = (string)$gateway['imei'];
        $this->dashboardStore?->recordGatewaySighting(
            $deviceKey,
            $gatewayKey,
            is_numeric($rssiDbm) ? (int)$rssiDbm : null,
        );
        if (!is_numeric($rssiDbm)) {
            return;
        }

        $this->publishProximity(
            $device,
            $gateway,
            $protocol,
            $this->proximity()->record($deviceKey, $gatewayKey, (int)$rssiDbm, ($this->clock)()),
        );
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
                'schemaVersion' => 2,
                'type' => 'proximity',
                'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'device' => $this->device($device),
                'data' => ['gatewayId' => (string)$gateway['imei']] + $data,
                'source' => array_filter([
                    'protocol' => $protocol,
                    'nativeType' => 'manufacturer_data',
                    'gatewayId' => (string)$gateway['imei'],
                    'rssiDbm' => $data['rssiDbm'] ?? null,
                ], static fn(mixed $value): bool => $value !== null),
            ],
            (string)$device['deviceType'],
            DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0),
            (string)($device['company'] ?? 'null'),
        );
    }

    /**
     * Tell the client when a pair has gone quiet.
     *
     * `unknown` is not `far`: out of range, a flat battery, a gateway offline and a
     * filter set too strictly are indistinguishable from each other and from
     * nobody being there. Reported once per pair, so silence stays silent
     * afterwards.
     */
    public function expireStaleProximity(): void
    {
        foreach ($this->proximity()->takeStale(($this->clock)()) as $pair) {
            $device = $this->whitelist->resolve($pair['deviceKey']);
            $gateway = $this->whitelist->resolve($pair['gatewayKey']);
            if ($device === null || $gateway === null) {
                continue;
            }
            $this->publishProximity(
                $this->enrich($device),
                $gateway,
                self::RELAYED_PROTOCOLS[(string)($device['deviceType'] ?? '')] ?? 'moko-gateway',
                ['state' => 'unknown', 'samples' => 0],
            );
        }
    }

    /** @param array<string, mixed> $gateway @param array<string, mixed> $decoded */
    private function handleMonitObservation(array $gateway, array $decoded): void
    {
        $sensorKey = (string)$decoded['mac'];
        $sensor = $this->linkedDevice($gateway, $sensorKey, 'diaper_sensor', 'monit-mecs-pro-ble');
        if ($sensor === null) {
            return;
        }
        if (!$this->state->acceptObservation($sensorKey, hash('sha256', (string)$decoded['raw20']), $this->dedupeTtlSeconds)) {
            return;
        }

        $normalized = ($this->monitNormalizer ?? new MonitNormalizer())->normalize($decoded, $sensor, (string)$gateway['imei']);
        $deviceType = (string)$sensor['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($sensor['licenseId'] ?? 0);
        $company = (string)($sensor['company'] ?? 'null');
        $this->dashboardStore?->deviceSeen($sensorKey, [
            'supplier' => (string)$sensor['supplier'], 'model' => (string)$sensor['model'],
            'deviceType' => $deviceType, 'licenseId' => $licenseId, 'company' => $company,
            'protocol' => 'monit-mecs-pro-ble', 'transport' => 'ble_gateway', 'online' => '1',
        ]);
        $this->recordSignal($sensor, $gateway, 'monit-mecs-pro-ble', $decoded['rssiDbm'] ?? null);
        foreach ($normalized['telemetry'] as $capability => $telemetry) {
            if (!$this->state->shouldPublish($sensorKey, $capability, $telemetry, $this->telemetryRefreshSeconds, (string)$gateway['imei'])) {
                continue;
            }
            $this->mqttBridge->publishTelemetry($sensorKey, $telemetry, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($sensorKey, 'telemetry', $telemetry + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }

        $transition = $this->state->transitionCondition($sensorKey, $normalized['condition']);
        if ($normalized['condition'] === 'change_required' && $transition !== null) {
            $event = [
                'schemaVersion' => 1, 'type' => 'change_required', 'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'device' => $this->device($sensor), 'data' => ['previousState' => $transition['previous']],
                'source' => ['protocol' => 'monit-mecs-pro-ble', 'gatewayId' => (string)$gateway['imei']],
            ];
            $this->mqttBridge->publishEvent($sensorKey, $event, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($sensorKey, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }
    }

    /** @param array<string, mixed> $device @return array<string, mixed> */
    private function enrich(array $device): array
    {
        if (($device['commercialName'] ?? '') === '') {
            $device['commercialName'] = $this->commercialModelResolver?->resolveCommercialName((string)$device['supplier'], (string)$device['model']) ?? '';
        }
        return $device;
    }

    /** @param array<string, mixed> $device */
    private function device(array $device): array
    {
        return array_filter([
            'id' => (string)$device['imei'], 'supplier' => (string)($device['supplier'] ?? ''),
            'model' => (string)($device['model'] ?? ''), 'commercialName' => (string)($device['commercialName'] ?? ''),
        ], static fn(string $value): bool => $value !== '');
    }
}
