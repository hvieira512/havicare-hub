<?php

namespace App\Hub;

use App\Log\Logger;
use PhpMqtt\Client\MqttClient;

class HubDownlinkSubscriber
{
    private MqttClient $subscriber;
    private DeviceHubServer $hubServer;
    private string $topicPrefix;
    /** @var null|callable(): MqttClient */
    private $reconnectSubscriber;
    private float $nextReconnectAt = 0.0;
    private int $reconnectDelay = 2;

    public function __construct(MqttClient $subscriber, DeviceHubServer $hubServer, string $topicPrefix = '', ?callable $reconnectSubscriber = null)
    {
        $this->subscriber = $subscriber;
        $this->hubServer = $hubServer;
        $this->topicPrefix = trim($topicPrefix, '/');
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

    private function subscribe(): void
    {
        $filter = $this->topic('devices/+/downlink');
        $this->subscriber->subscribe($filter, function (string $topic, string $payload): void {
            $this->handle($topic, $payload);
        }, MqttClient::QOS_AT_LEAST_ONCE);
        Logger::channel('hub')->info("MQTT downlink subscribed to {$filter} qos=1");
    }

    public function handleReceivedMessage(string $topic, string $payload): void
    {
        $this->handle($topic, $payload);
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

        Logger::channel('hub')->warning("MQTT downlink connection lost: {$e->getMessage()}; reconnecting");
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
            Logger::channel('hub')->error("MQTT downlink reconnect failed: {$reconnectError->getMessage()}");
        }
    }

    private function handle(string $topic, string $payload): void
    {
        $imei = $this->imeiFromTopic($topic);
        if ($imei === null) {
            Logger::channel('hub')->warning("Ignoring downlink on unexpected topic {$topic}");
            return;
        }

        $decoded = json_decode($payload, true);
        $bytes = RawPayload::bytesFromDownlink(is_array($decoded) ? $decoded : $payload);
        if ($bytes === null) {
            Logger::channel('hub')->warning("Ignoring malformed downlink for IMEI={$imei}");
            return;
        }

        if (!$this->hubServer->sendDownlink($imei, $bytes)) {
            if ($this->hubServer->queueDownlink($imei, $bytes)) {
                Logger::channel('hub')->info("Downlink queued because IMEI={$imei} is offline");
                return;
            }

            Logger::channel('hub')->warning("Downlink dropped because IMEI={$imei} is offline and queueing failed");
        }
    }

    private function imeiFromTopic(string $topic): ?string
    {
        $base = $this->topicPrefix === '' ? $topic : preg_replace(
            '/^' . preg_quote($this->topicPrefix, '/') . '\\//',
            '',
            $topic
        );
        if (!is_string($base)) {
            return null;
        }

        $parts = explode('/', trim($base, '/'));
        if (count($parts) !== 3 || $parts[0] !== 'devices' || $parts[2] !== 'downlink') {
            return null;
        }

        return $parts[1] !== '' ? $parts[1] : null;
    }

    private function topic(string $topic): string
    {
        $topic = trim($topic, '/');
        return $this->topicPrefix === '' ? $topic : $this->topicPrefix . '/' . $topic;
    }
}
