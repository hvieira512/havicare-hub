<?php

namespace Hub\Device;

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
    ): void {
        // QoS 1: a mensagem é retida, e um `offline` perdido deixa `online` no broker até
        // à transição seguinte.
        $this->publish(
            $this->topic($this->deviceTopic($company, $licenseId, $deviceType, $imei, 'status')),
            $payload,
            $retain,
            MqttClient::QOS_AT_LEAST_ONCE,
        );
    }

    public function publishEvent(string $imei, array $payload, string $deviceType = self::DEFAULT_DEVICE_TYPE, int $licenseId = self::DEFAULT_LICENSE_ID, string $company = self::DEFAULT_COMPANY): void
    {
        $this->publish($this->topic($this->deviceTopic($company, $licenseId, $deviceType, $imei, 'events')), $payload, false, MqttClient::QOS_AT_LEAST_ONCE);
    }

    public function publishTelemetry(string $imei, array $payload, string $deviceType = self::DEFAULT_DEVICE_TYPE, int $licenseId = self::DEFAULT_LICENSE_ID, string $company = self::DEFAULT_COMPANY): void
    {
        $this->publish($this->topic($this->deviceTopic($company, $licenseId, $deviceType, $imei, 'telemetry')), $payload);
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

    /** O único sítio onde o `licenseId` se torna texto: em todo o resto é um inteiro. */
    public function deviceTopic(string $company, int $licenseId, string $deviceType, string $deviceKey, string $kind): string
    {
        return trim($company, '/') . '/' . $licenseId . '/' . trim($deviceType, '/') . '/' . trim($deviceKey, '/') . '/' . trim($kind, '/');
    }

    /**
     * Apaga o estado retido que um dispositivo deixou no tópico de um cliente.
     *
     * O estado é publicado como retido, e por isso um dispositivo que muda de cliente
     * continua a anunciar-se em todos os tópicos que já usou -- quem subscreve o cliente
     * antigo continua a recebê-lo. O MQTT apaga uma mensagem retida com um payload de
     * comprimento zero; um documento JSON vazio só a substituiria.
     */
    public function clearRetainedStatus(string $company, int $licenseId, string $deviceType, string $imei): void
    {
        $topic = $this->topic($this->deviceTopic($company, $licenseId, $deviceType, $imei, 'status'));

        try {
            $this->publisher->publish($topic, '', MqttClient::QOS_AT_LEAST_ONCE, true);
        } catch (\Throwable $e) {
            if ($this->reconnectPublisher === null) {
                throw $e;
            }

            $this->reconnect();
            $this->publisher->publish($topic, '', MqttClient::QOS_AT_LEAST_ONCE, true);
        }
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
