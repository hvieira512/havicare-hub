<?php

namespace Hub\Ncs;

use Hub\Dashboard\DashboardStore;
use Hub\HubMqttBridge;
use Hub\Log\Logger;
use Hub\Registry\Whitelist;
use PhpMqtt\Client\MqttClient;

final class NcsMqttIngressBridge
{
    private MqttClient $subscriber;
    private float $nextReconnectAt = 0.0;
    private int $reconnectDelay = 2;

    /** @var null|callable(): MqttClient */
    private $reconnectSubscriber;

    public function __construct(
        MqttClient $subscriber,
        private readonly Whitelist $whitelist,
        private readonly HubMqttBridge $mqttBridge,
        private readonly string $topicFilter = '/voerka/#',
        ?callable $reconnectSubscriber = null,
        private readonly ?DashboardStore $dashboardStore = null,
        private readonly ?NcsMessageNormalizer $normalizer = null,
    ) {
        $this->subscriber = $subscriber;
        $this->reconnectSubscriber = $reconnectSubscriber;
    }

    public function start(): void
    {
        $this->subscribe();
    }

    public function tick(float $timeout = 0.01): void
    {
        try {
            $this->subscriber->loopOnce(microtime(true), false, max(1000, (int)round($timeout * 1000000)));
        } catch (\Throwable $e) {
            $this->handleLoopFailure($e);
        }
    }

    public function handleReceivedMessage(string $topic, string $payload): void
    {
        $this->handle($topic, $payload);
    }

    private function subscribe(): void
    {
        $this->subscriber->subscribe($this->topicFilter, function (string $topic, string $payload): void {
            $this->handle($topic, $payload);
        }, MqttClient::QOS_AT_LEAST_ONCE);
        Logger::channel('hub')->info("NCS ingress subscribed to {$this->topicFilter} qos=1");
    }

    private function handleLoopFailure(\Throwable $e): void
    {
        if ($this->reconnectSubscriber === null) {
            throw $e;
        }

        $now = microtime(true);
        if ($now < $this->nextReconnectAt) {
            return;
        }

        Logger::channel('hub')->warning("NCS ingress connection lost: {$e->getMessage()}; reconnecting");
        $this->nextReconnectAt = $now + $this->reconnectDelay;
        $this->reconnectDelay = min($this->reconnectDelay * 2, 60);

        try {
            if ($this->subscriber->isConnected()) {
                $this->subscriber->disconnect();
            }
        } catch (\Throwable) {
        }

        try {
            $this->subscriber = ($this->reconnectSubscriber)();
            $this->subscribe();
            $this->reconnectDelay = 2;
            $this->nextReconnectAt = 0.0;
        } catch (\Throwable $reconnectError) {
            Logger::channel('hub')->error("NCS ingress reconnect failed: {$reconnectError->getMessage()}");
        }
    }

    private function handle(string $topic, string $payload): void
    {
        $parsedTopic = NcsTopic::parse($topic);
        if ($parsedTopic === null) {
            Logger::channel('hub')->warning("Ignoring unsupported NCS topic {$topic}");
            return;
        }

        if (!in_array($parsedTopic->kind, ['status', 'events'], true)) {
            Logger::channel('hub')->info("Ignoring NCS {$parsedTopic->kind} topic {$topic} in phase 1");
            return;
        }

        $message = json_decode($payload, true);
        if (!is_array($message)) {
            Logger::channel('hub')->warning("Ignoring malformed NCS JSON on {$topic}");
            return;
        }

        $from = trim((string)($message['from'] ?? ''));
        if ($from === '') {
            Logger::channel('hub')->warning("Ignoring NCS message without from on {$topic}");
            return;
        }

        if ($from !== $parsedTopic->sourceId) {
            Logger::channel('hub')->warning("Ignoring NCS message with source mismatch topic={$parsedTopic->sourceId} payload={$from}");
            return;
        }

        $device = $this->whitelist->resolveSource('voerka', $from);
        if ($device === null || ($device['deviceType'] ?? '') !== 'ncs' || trim((string)($device['licenseId'] ?? '')) === '') {
            Logger::channel('hub')->warning("Ignoring unregistered NCS source from={$from}");
            return;
        }

        try {
            $normalized = ($this->normalizer ?? new NcsMessageNormalizer())->normalize($parsedTopic, $message, $device);
        } catch (\Throwable $e) {
            Logger::channel('hub')->warning("Ignoring invalid NCS message from={$from}: {$e->getMessage()}");
            return;
        }

        $deviceKey = (string)$device['imei'];
        $deviceType = (string)$device['deviceType'];
        $licenseId = (string)$device['licenseId'];

        $this->mqttBridge->publishRaw($deviceKey, $normalized['raw'], $deviceType, $licenseId);
        $this->dashboardStore?->deviceSeen($deviceKey, [
            'supplier' => (string)$device['supplier'],
            'model' => (string)$device['model'],
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
            'sourceSystem' => (string)($device['sourceSystem'] ?? ''),
            'sourceDeviceId' => (string)($device['sourceDeviceId'] ?? ''),
            'protocol' => 'voerka-ncs',
            'transport' => 'mqtt',
            'online' => '1',
        ]);
        $this->dashboardStore?->append($deviceKey, 'raw', array_merge($normalized['raw'], [
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
        ]));

        if (isset($normalized['status']) && is_array($normalized['status'])) {
            $retain = ((string)($normalized['status']['state'] ?? '')) !== 'error';
            $this->mqttBridge->publishStatus($deviceKey, $normalized['status'], $retain, $deviceType, $licenseId);
            if (($normalized['status']['state'] ?? '') === 'offline') {
                $this->dashboardStore?->deviceOffline($deviceKey);
            }
        }

        if (isset($normalized['event']) && is_array($normalized['event'])) {
            $this->mqttBridge->publishEvent($deviceKey, $normalized['event'], $deviceType, $licenseId);
            $this->dashboardStore?->append($deviceKey, 'events', array_merge($normalized['event'], [
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
            ]));
        }

        if (isset($normalized['telemetry']) && is_array($normalized['telemetry'])) {
            $this->mqttBridge->publishTelemetry($deviceKey, $normalized['telemetry'], $deviceType, $licenseId);
            $this->dashboardStore?->append($deviceKey, 'telemetry', array_merge($normalized['telemetry'], [
                'deviceType' => $deviceType,
                'licenseId' => $licenseId,
            ]));
        }
    }
}
