<?php

namespace App\Hub;

use App\Log\Logger;
use App\Mqtt\SimpleClient;

class HubMqttBridge
{
    private SimpleClient $publisher;
    private string $topicPrefix;

    public function __construct(SimpleClient $publisher, string $topicPrefix = '')
    {
        $this->publisher = $publisher;
        $this->topicPrefix = trim($topicPrefix, '/');
    }

    public function publishUplink(string $imei, array $payload): void
    {
        $this->publish($this->topic("devices/$imei/uplink"), $payload);
    }

    public function publishStatus(string $imei, array $payload, bool $retain = true): void
    {
        $this->publish($this->topic("devices/$imei/status"), $payload, $retain);
    }

    public function publishError(string $imei, array $payload): void
    {
        $this->publish($this->topic("devices/$imei/error"), $payload);
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

        $this->publisher->publish($topic, $json, $retain);
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
}
