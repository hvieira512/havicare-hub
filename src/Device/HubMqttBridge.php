<?php

declare(strict_types=1);

namespace Hub\Device;

use Hub\Domain\Capability\EventSeverity;
use Hub\Log\Logger;
use Hub\Mqtt\ReconnectsOnLoopFailure;
use PhpMqtt\Client\MqttClient;

class HubMqttBridge
{
    use ReconnectsOnLoopFailure;

    private const DEFAULT_COMPANY = 'null';
    private const DEFAULT_LICENSE_ID = 0;
    private const DEFAULT_DEVICE_TYPE = 'watch';

    private MqttClient $publisher;
    private string $topicPrefix;
    /** @var null|callable(): MqttClient */
    private $reconnectPublisher;
    private MessageFanout $messages;

    public function __construct(
        MqttClient $publisher,
        string $topicPrefix = '',
        ?callable $reconnectPublisher = null,
        ?MessageFanout $messages = null,
    ) {
        $this->publisher = $publisher;
        $this->topicPrefix = trim($topicPrefix, '/');
        $this->reconnectPublisher = $reconnectPublisher;
        $this->messages = $messages ?? new MessageFanout();
    }

    /**
     * Quem serve os streams pede-o aqui: a ingestão e o servidor HTTP têm de partilhar este
     * fan-out, e uma segunda instância ficaria calada sem falhar nenhum teste.
     */
    public function messages(): MessageFanout
    {
        return $this->messages;
    }

    /** @param array<string, mixed> $payload */
    public function publishRaw(string $imei, array $payload, string $deviceType = self::DEFAULT_DEVICE_TYPE, int $licenseId = self::DEFAULT_LICENSE_ID, string $company = self::DEFAULT_COMPANY): void
    {
        $this->publish(
            $this->topic($this->deviceTopic($company, $licenseId, $deviceType, $imei, 'raw')),
            $payload,
            $company,
            $licenseId,
            'raw',
        );
    }

    /** @param array<string, mixed> $payload */
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
            $company,
            $licenseId,
            'status',
            $retain,
            MqttClient::QOS_AT_LEAST_ONCE,
        );
    }

    /** @param array<string, mixed> $payload */
    public function publishEvent(string $imei, array $payload, string $deviceType = self::DEFAULT_DEVICE_TYPE, int $licenseId = self::DEFAULT_LICENSE_ID, string $company = self::DEFAULT_COMPANY): void
    {
        $this->publish(
            $this->topic($this->deviceTopic($company, $licenseId, $deviceType, $imei, 'events')),
            EventSeverity::stamp($payload),
            $company,
            $licenseId,
            'events',
            false,
            MqttClient::QOS_AT_LEAST_ONCE,
        );
    }

    /** @param array<string, mixed> $payload */
    public function publishTelemetry(string $imei, array $payload, string $deviceType = self::DEFAULT_DEVICE_TYPE, int $licenseId = self::DEFAULT_LICENSE_ID, string $company = self::DEFAULT_COMPANY): void
    {
        $this->publish(
            $this->topic($this->deviceTopic($company, $licenseId, $deviceType, $imei, 'telemetry')),
            $payload,
            $company,
            $licenseId,
            'telemetry',
        );
    }

    /** @param array<string, mixed> $payload */
    private function publish(
        string $topic,
        array $payload,
        string $company,
        int $licenseId,
        string $channel,
        bool $retain = false,
        int $qualityOfService = MqttClient::QOS_AT_MOST_ONCE,
    ): void {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode MQTT payload');
        }

        // A derivação para os streams vem antes do fio, com a mensagem já em memória; o
        // `hasListeners()` poupa a composição da chave quando ninguém escuta.
        if ($this->messages->hasListeners()) {
            $this->messages->dispatch(MessageFanout::scope($company, $licenseId, $channel), $topic, $json);
        }

        try {
            $this->publisher->publish($topic, $json, $qualityOfService, $retain);
        } catch (\Throwable $e) {
            if ($this->reconnectPublisher === null) {
                throw $e;
            }

            $this->reconnect($e);
            $this->publisher->publish($topic, $json, $qualityOfService, $retain);
        }
    }

    /**
     * Entrega uma instrução a um gateway, no espaço de tópicos fixo com que foi provisionado e
     * sem o prefixo da instância. É a entrega, o equivalente ao socket de um relógio.
     *
     * @param array<string, mixed> $payload
     */
    public function publishGatewayCommand(string $topic, array $payload): void
    {
        $this->publish($topic, $payload, 'null', 0, 'cmd', false, MqttClient::QOS_AT_LEAST_ONCE);
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
     * Apaga o estado retido que um dispositivo deixou no tópico de um cliente que já não é o dele.
     * O MQTT só apaga uma retida com payload de comprimento zero; um JSON vazio substituía-a.
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

            $this->reconnect($e);
            $this->publisher->publish($topic, '', MqttClient::QOS_AT_LEAST_ONCE, true);
        }
    }

    public function logPublishFailure(string $channel, string $imei, \Throwable $e): void
    {
        Logger::channel($channel)->error("MQTT publish failed for IMEI=$imei: {$e->getMessage()}");
    }

    /**
     * Processa os PUBACK pendentes: cada QoS 1 deixa um `PublishedMessage` à espera, e aos 65 535
     * o cliente rebenta. Não bloqueia, porque o `loopOnce` só lê o que já está no socket.
     */
    public function drainPublisher(): void
    {
        try {
            $this->publisher->loopOnce(microtime(true), false);
        } catch (\Throwable $e) {
            if ($this->reconnectPublisher === null) {
                throw $e;
            }
            $this->reconnect($e);
        }
    }

    private function reconnect(\Throwable $failure): void
    {
        $this->reconnectAfterLoopFailure(
            $failure,
            'MQTT publisher',
            function (): void {
                try {
                    if ($this->publisher->isConnected()) {
                        $this->publisher->disconnect();
                    }
                } catch (\Throwable) {
                }

                $this->publisher = ($this->reconnectPublisher)();
            },
            function (): void {
                $this->markConnected();
            },
        );
    }
}
