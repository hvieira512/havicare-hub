<?php

namespace Hub\Ingress\Mqtt;

use Hub\Dashboard\DashboardStoreContract;
use Hub\HubMqttBridge;
use Hub\Log\Logger;
use Hub\Registry\Whitelist;
use PhpMqtt\Client\MqttClient;

abstract class Bridge implements MqttIngress
{
    private MqttClient $subscriber;
    private float $nextReconnectAt = 0.0;
    private int $reconnectDelay = 2;

    /** @var null|callable(): MqttClient */
    private $reconnectSubscriber;

    public function __construct(
        MqttClient $subscriber,
        protected readonly Whitelist $whitelist,
        protected readonly HubMqttBridge $mqttBridge,
        protected readonly string $topicFilter,
        protected readonly ?string $sourceName = null,
        ?callable $reconnectSubscriber = null,
        protected readonly ?DashboardStoreContract $dashboardStore = null,
    ) {
        $this->subscriber = $subscriber;
        $this->reconnectSubscriber = $reconnectSubscriber;
    }

    abstract protected function handleMessage(string $topic, string $payload): void;

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
        $this->handleMessage($topic, $payload);
    }

    protected function recordUnauthorizedDevice(
        string $identity,
        string $protocol,
        string $model = '',
        string $ident = '',
    ): void {
        try {
            $this->dashboardStore?->recordRejectedDevice(
                $identity,
                $protocol,
                $model,
                $ident,
                'device_not_authorized'
            );
        } catch (\Throwable $e) {
            Logger::channel('hub')->error(
                "Failed to record rejected device identity={$identity}: {$e->getMessage()}"
            );
        }
    }

    private function subscribe(): void
    {
        $this->subscriber->subscribe($this->topicFilter, function (string $topic, string $payload): void {
            $this->handleMessage($topic, $payload);
        }, MqttClient::QOS_AT_LEAST_ONCE);

        $source = $this->sourceName ?? $this->topicFilter;
        Logger::channel('hub')->info("MQTT ingress {$source} subscribed to {$this->topicFilter} qos=1");
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

        $source = $this->sourceName ?? $this->topicFilter;
        Logger::channel('hub')->warning("MQTT ingress {$source} connection lost: {$e->getMessage()}; reconnecting");
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
            Logger::channel('hub')->error("MQTT ingress {$source} reconnect failed: {$reconnectError->getMessage()}");
        }
    }
}
