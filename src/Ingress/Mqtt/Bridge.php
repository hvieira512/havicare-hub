<?php

namespace Hub\Ingress\Mqtt;

use Hub\Dashboard\DashboardStoreContract;
use Hub\HubMqttBridge;
use Hub\Log\Logger;
use Hub\Mqtt\ReconnectsOnLoopFailure;
use Hub\Registry\Whitelist;
use PhpMqtt\Client\MqttClient;

abstract class Bridge implements MqttIngress
{
    use ReconnectsOnLoopFailure;

    private MqttClient $subscriber;

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

    /**
     * `$licenseId` só é conhecido por quem o consegue tirar do que recebeu. O radar
     * consegue -- publica em `radar/{licenseId}/{uid}` --, e é o que permite à dashboard
     * pré-seleccionar a licença ao registar. Quem se identifica só por MAC ou por endereço
     * deixa ficar a zero.
     */
    protected function recordUnauthorizedDevice(
        string $identity,
        string $protocol,
        string $model = '',
        string $ident = '',
        int $licenseId = 0,
    ): void {
        try {
            $this->dashboardStore?->recordRejectedDevice(
                $identity,
                $protocol,
                $model,
                $ident,
                'device_not_authorized',
                $licenseId
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

        $this->markConnected();
        $source = $this->sourceName ?? $this->topicFilter;
        Logger::channel('hub')->info("MQTT ingress {$source} subscribed to {$this->topicFilter} qos=1");
    }

    private function handleLoopFailure(\Throwable $e): void
    {
        if ($this->reconnectSubscriber === null) {
            throw $e;
        }

        $this->reconnectAfterLoopFailure(
            $e,
            'MQTT ingress ' . ($this->sourceName ?? $this->topicFilter),
            function (): void {
                try {
                    if ($this->subscriber->isConnected()) {
                        $this->subscriber->disconnect();
                    }
                } catch (\Throwable) {
                }

                $this->subscriber = ($this->reconnectSubscriber)();
            },
            function (): void {
                $this->subscribe();
            },
        );
    }
}
