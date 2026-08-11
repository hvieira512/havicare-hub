<?php

namespace Hub;

use Hub\Log\Logger;
use PhpMqtt\Client\MqttClient;

class HubMqttBridge
{
    private const DEFAULT_COMPANY = 'null';
    private const DEFAULT_LICENSE_ID = 0;
    private const DEFAULT_DEVICE_TYPE = 'watch';

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

    public function publishRaw(string $imei, array $payload, string $deviceType = self::DEFAULT_DEVICE_TYPE, int $licenseId = self::DEFAULT_LICENSE_ID, string $company = self::DEFAULT_COMPANY): void
    {
        $this->publish($this->topic($this->deviceTopic($company, $licenseId, $deviceType, $imei, 'raw')), $payload);
    }

    public function publishStatus(
        string $imei,
        array $payload,
        bool $retain = true,
        string $deviceType = self::DEFAULT_DEVICE_TYPE,
        int $licenseId = self::DEFAULT_LICENSE_ID,
        string $company = self::DEFAULT_COMPANY,
    ): void
    {
        $this->publish($this->topic($this->deviceTopic($company, $licenseId, $deviceType, $imei, 'status')), $payload, $retain);
    }

    public function publishEvent(string $imei, array $payload, string $deviceType = self::DEFAULT_DEVICE_TYPE, int $licenseId = self::DEFAULT_LICENSE_ID, string $company = self::DEFAULT_COMPANY): void
    {
        $this->publish($this->topic($this->deviceTopic($company, $licenseId, $deviceType, $imei, 'events')), $payload, false, MqttClient::QOS_AT_LEAST_ONCE);
    }

    public function publishTelemetry(string $imei, array $payload, string $deviceType = self::DEFAULT_DEVICE_TYPE, int $licenseId = self::DEFAULT_LICENSE_ID, string $company = self::DEFAULT_COMPANY): void
    {
        $this->publish($this->topic($this->deviceTopic($company, $licenseId, $deviceType, $imei, 'telemetry')), $payload);
    }

    public function downlinkTopicFilter(): string
    {
        return $this->topic('+/+/' . self::DEFAULT_DEVICE_TYPE . '/+/downlink');
    }

    private function publish(string $topic, array $payload, bool $retain = false, int $qualityOfService = MqttClient::QOS_AT_MOST_ONCE): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode MQTT payload');
        }

        try {
            $this->publisher->publish($topic, $json, $qualityOfService, $retain);
        } catch (\Throwable $e) {
            if ($this->reconnectPublisher === null) {
                throw $e;
            }

            $this->reconnect();
            $this->publisher->publish($topic, $json, $qualityOfService, $retain);
        }
    }

    public function topic(string $topic): string
    {
        $topic = trim($topic, '/');
        return $this->topicPrefix === '' ? $topic : $this->topicPrefix . '/' . $topic;
    }

    /**
     * The one place licenseId becomes text: it is an int everywhere else.
     */
    public function deviceTopic(string $company, int $licenseId, string $deviceType, string $deviceKey, string $kind): string
    {
        return trim($company, '/') . '/' . $licenseId . '/' . trim($deviceType, '/') . '/' . trim($deviceKey, '/') . '/' . trim($kind, '/');
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
