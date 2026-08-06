<?php

namespace Hub\Ingress\Mqtt\Moko;

use Hub\CommercialModelResolver;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Log\Logger;
use Hub\RawPayload;

final class Bridge extends \Hub\Ingress\Mqtt\Bridge
{
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
        private readonly ?Mkgw3MessageDecoder $messageDecoder = null,
        private readonly ?MonitMecsProDecoder $monitDecoder = null,
        private readonly ?MonitNormalizer $monitNormalizer = null,
        private readonly ?GatewayNormalizer $gatewayNormalizer = null,
        ?callable $clock = null,
    ) {
        parent::__construct(
            $subscriber,
            $whitelist,
            $mqttBridge,
            $topicFilter,
            sourceName: 'moko-mkgw3',
            reconnectSubscriber: $reconnectSubscriber,
            dashboardStore: $dashboardStore,
        );
        $this->links = $links;
        $this->state = $state;
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): float => microtime(true);
    }

    private readonly GatewayDeviceLinkLookup $links;
    private readonly ObservationStateStore $state;

    public function tick(float $timeout = 0.01): void
    {
        parent::tick($timeout);
        $this->expireIdleGateways();
    }

    public function expireIdleGateways(): void
    {
        $now = ($this->clock)();
        foreach ($this->onlineGateways as $deviceKey => $gateway) {
            if ($now - ($this->gatewayLastSeenAt[$deviceKey] ?? $now) < $this->gatewayIdleTimeoutSeconds) {
                continue;
            }
            $deviceType = (string)$gateway['deviceType'];
            $licenseId = (string)$gateway['licenseId'];
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
            Logger::channel('hub')->warning("Ignoring unsupported MKGW3 topic {$topic}");
            return;
        }

        $gateway = $this->whitelist->resolve($parsedTopic->gatewayMac);
        if ($gateway === null || ($gateway['deviceType'] ?? '') !== 'gateway') {
            $this->recordUnauthorizedDevice($parsedTopic->gatewayMac, 'moko-mkgw3', ident: $parsedTopic->gatewayMac);
            Logger::channel('hub')->warning("Ignoring unregistered MKGW3 gateway mac={$parsedTopic->gatewayMac}");
            return;
        }

        $decoded = ($this->messageDecoder ?? new Mkgw3MessageDecoder())->decode($payload);
        if ($decoded === null || $decoded['gatewayMac'] !== $parsedTopic->gatewayMac) {
            Logger::channel('hub')->warning("Ignoring invalid MKGW3 payload or gateway MAC mismatch on {$topic}");
            return;
        }
        $gateway = $this->enrich($gateway);
        $this->recordGateway($gateway, $decoded, $topic, $payload);

        if ($decoded['messageId'] === 3070 && is_array($decoded['data'])) {
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
        $licenseId = (string)$gateway['licenseId'];
        $company = (string)($gateway['company'] ?? 'null');
        $this->gatewayLastSeenAt[$deviceKey] = ($this->clock)();
        $raw = [
            'schemaVersion' => 1,
            'direction' => 'uplink',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->device($gateway),
            'data' => $decoded,
            'debug' => [
                'protocol' => 'moko-mkgw3',
                'transport' => 'mqtt',
                'encoding' => 'json',
                'payload' => json_decode($originalPayload, true),
                'sourceTopic' => $sourceTopic,
            ],
        ];
        $this->mqttBridge->publishRaw($deviceKey, $raw, $deviceType, $licenseId, $company);
        $this->dashboardStore?->deviceSeen($deviceKey, [
            'supplier' => (string)$gateway['supplier'], 'model' => (string)$gateway['model'],
            'deviceType' => $deviceType, 'licenseId' => $licenseId, 'company' => $company,
            'protocol' => 'moko-mkgw3', 'transport' => 'mqtt', 'online' => '1',
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

    /** @param array<string, mixed> $gateway @param array<string, mixed> $observation */
    private function handleObservation(array $gateway, array $observation): void
    {
        $decoded = ($this->monitDecoder ?? new MonitMecsProDecoder())->decode($observation);
        if ($decoded === null) {
            return;
        }
        $sensorKey = (string)$decoded['mac'];
        $sensor = $this->whitelist->resolve($sensorKey);
        if ($sensor === null || ($sensor['deviceType'] ?? '') !== 'diaper_sensor') {
            $this->recordUnauthorizedDevice($sensorKey, 'monit-mecs-pro-ble', ident: $sensorKey);
            return;
        }
        if (
            !$this->links->isEnabled((string)$gateway['imei'], (string)$sensor['imei'])
            || (string)($gateway['company'] ?? 'null') !== (string)($sensor['company'] ?? 'null')
            || (string)$gateway['licenseId'] !== (string)$sensor['licenseId']
        ) {
            Logger::channel('hub')->warning("Ignoring unlinked MONIT sensor={$sensorKey} gateway={$gateway['imei']}");
            return;
        }
        if (!$this->state->acceptObservation($sensorKey, hash('sha256', (string)$decoded['raw20']), $this->dedupeTtlSeconds)) {
            return;
        }

        $sensor = $this->enrich($sensor);
        $normalized = ($this->monitNormalizer ?? new MonitNormalizer())->normalize($decoded, $sensor, (string)$gateway['imei']);
        $deviceType = (string)$sensor['deviceType'];
        $licenseId = (string)$sensor['licenseId'];
        $company = (string)($sensor['company'] ?? 'null');
        $this->dashboardStore?->deviceSeen($sensorKey, [
            'supplier' => (string)$sensor['supplier'], 'model' => (string)$sensor['model'],
            'deviceType' => $deviceType, 'licenseId' => $licenseId, 'company' => $company,
            'protocol' => 'monit-mecs-pro-ble', 'transport' => 'ble_gateway', 'online' => '1',
        ]);
        foreach ($normalized['telemetry'] as $capability => $telemetry) {
            if (!$this->state->shouldPublish($sensorKey, $capability, $telemetry, $this->telemetryRefreshSeconds)) {
                continue;
            }
            $this->mqttBridge->publishTelemetry($sensorKey, $telemetry, $deviceType, $licenseId, $company);
            $this->dashboardStore?->append($sensorKey, 'telemetry', $telemetry + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }

        $previous = $this->state->transitionCondition($sensorKey, $normalized['condition']);
        if ($normalized['condition'] === 'change_required' && $previous !== null) {
            $event = [
                'schemaVersion' => 1, 'type' => 'change_required', 'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'device' => $this->device($sensor), 'data' => ['previousState' => $previous],
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
