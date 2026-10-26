<?php

declare(strict_types=1);

namespace Hub\Device;

use Hub\Domain\Capability\EventSeverity;
use Hub\Domain\Capability\ReadingMarks;
use Hub\Log\Logger;
use Hub\Mqtt\ReconnectsOnLoopFailure;
use Hub\State\DeviceReportStore;
use PhpMqtt\Client\MqttClient;

class HubMqttBridge
{
    use ReconnectsOnLoopFailure;


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
        private readonly bool $gatewayCommands = true,
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
    public function publishRaw(string $imei, array $payload, string $deviceType, int $licenseId, string $company): void
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
        bool $retain,
        string $deviceType,
        int $licenseId,
        string $company,
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
    public function publishEvent(string $imei, array $payload, string $deviceType, int $licenseId, string $company): void
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
    public function publishTelemetry(string $imei, array $payload, string $deviceType, int $licenseId, string $company): void
    {
        $this->publish(
            $this->topic($this->deviceTopic($company, $licenseId, $deviceType, $imei, 'telemetry')),
            ReadingMarks::mark($payload),
            $company,
            $licenseId,
            'telemetry',
        );
    }

    /**
     * Publica no canal e guarda o mesmo no histórico do aparelho, carimbado com o tipo e a licença.
     *
     * @param 'raw'|'telemetry'|'events' $channel
     * @param array<string, mixed> $payload
     */
    public function publishAndStore(
        ?DeviceReportStore $store,
        string $channel,
        string $imei,
        array $payload,
        string $deviceType,
        int $licenseId,
        string $company,
    ): void {
        match ($channel) {
            'raw' => $this->publishRaw($imei, $payload, $deviceType, $licenseId, $company),
            'telemetry' => $this->publishTelemetry($imei, $payload, $deviceType, $licenseId, $company),
            'events' => $this->publishEvent($imei, $payload, $deviceType, $licenseId, $company),
        };
        $store?->append($imei, $channel, $payload + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
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
        // O `data` é sempre um objecto: vazio, o PHP codifica-o como lista.
        if (($payload['data'] ?? null) === []) {
            $payload['data'] = new \stdClass();
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode MQTT payload');
        }

        // A derivação para os streams vem antes do fio, com a mensagem já em memória; o
        // `hasListeners()` poupa a composição da chave quando ninguém escuta.
        if ($this->messages->hasListeners()) {
            $this->messages->dispatch(MessageFanout::scope($company, $licenseId, $channel), $topic, $json);
        }

        $this->withPublisher(static fn (MqttClient $publisher) => $publisher->publish($topic, $json, $qualityOfService, $retain));
    }

    /**
     * Entrega uma instrução a um gateway, no espaço de tópicos fixo com que foi provisionado e
     * sem o prefixo da instância. É a entrega, o equivalente ao socket de um relógio.
     *
     * @param array<string, mixed> $payload
     */
    public function publishGatewayCommand(string $topic, array $payload): void
    {
        // Desligado na instância que lê os gateways reais sem ser a que os comanda.
        if (!$this->gatewayCommands) {
            Logger::channel('hub')->debug("Gateway commands off: {$topic} " . (string)($payload['operation'] ?? ''));
            return;
        }
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
        $this->withPublisher(static fn (MqttClient $publisher) => $publisher->publish($topic, '', MqttClient::QOS_AT_LEAST_ONCE, true));
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
        $this->withPublisher(static fn (MqttClient $publisher) => $publisher->loopOnce(microtime(true), false), retry: false);
    }

    /**
     * Usa o publicador; se a ligação caiu, volta a ligar e, com `$retry`, tenta uma vez mais.
     *
     * @param \Closure(MqttClient): mixed $use
     */
    private function withPublisher(\Closure $use, bool $retry = true): void
    {
        try {
            $use($this->publisher);
        } catch (\Throwable $e) {
            if ($this->reconnectPublisher === null) {
                throw $e;
            }

            $this->reconnect($e);
            if ($retry) {
                $use($this->publisher);
            }
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
