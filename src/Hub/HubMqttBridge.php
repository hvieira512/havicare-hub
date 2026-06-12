<?php

namespace App\Hub;

use App\Log\Logger;
use PhpMqtt\Client\MqttClient;

class HubMqttBridge
{
    private MqttClient $publisher;
    private string $topicPrefix;
    /** @var null|callable(): MqttClient */
    private $reconnectPublisher;

    public function __construct(MqttClient $publisher, string $topicPrefix = '', ?callable $reconnectPublisher = null)
    {
        $this->publisher = $publisher;
        $this->topicPrefix = trim($topicPrefix, '/');
        $this->reconnectPublisher = $reconnectPublisher;
    }

    public function publishRaw(string $imei, array $payload): void
    {
        $this->publish($this->topic("devices/$imei/raw"), $payload);
    }

    public function publishStatus(string $imei, array $payload, bool $retain = true): void
    {
        $this->publish($this->topic("devices/$imei/status"), $payload, $retain);
    }

    public function publishEvent(string $imei, array $payload): void
    {
        $this->publish($this->topic("devices/$imei/events"), $payload);
    }

    public function publishTelemetry(string $imei, array $payload): void
    {
        $this->publish($this->topic("devices/$imei/telemetry"), $payload);
    }

    public function downlinkTopicFilter(): string
    {
        return $this->topic('devices/+/downlink');
    }

    private function publish(string $topic, array $payload, bool $retain = false): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode MQTT payload');
        }

        try {
            $this->publisher->publish($topic, $json, MqttClient::QOS_AT_MOST_ONCE, $retain);
        } catch (\Throwable $e) {
            if ($this->reconnectPublisher === null) {
                throw $e;
            }

            $this->reconnect();
            $this->publisher->publish($topic, $json, MqttClient::QOS_AT_MOST_ONCE, $retain);
        }
    }

    public function topic(string $topic): string
    {
        $topic = trim($topic, '/');
        return $this->topicPrefix === '' ? $topic : $this->topicPrefix . '/' . $topic;
    }

    public function logPublishFailure(string $channel, string $imei, \Throwable $e): void
    {
        Logger::channel($channel)->error("MQTT publish failed for IMEI=$imei: {$e->getMessage()}");
    }

    private function reconnect(): void
    {
        try {
            if ($this->publisher->isConnected()) {
                $this->publisher->disconnect();
            }
        } catch (\Throwable) {
        }

        Logger::channel('hub')->warning('MQTT publisher connection lost; reconnecting');
        $this->publisher = ($this->reconnectPublisher)();
    }
}
