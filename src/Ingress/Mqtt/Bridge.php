<?php

namespace Hub\Ingress\Mqtt;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Device\CommercialModelResolver;
use Hub\Device\HubMqttBridge;
use Hub\Log\Logger;
use Hub\Mqtt\ReconnectsOnLoopFailure;
use Hub\Registry\Denylist;
use Hub\Registry\Whitelist;
use PhpMqtt\Client\MqttClient;

abstract class Bridge implements MqttIngress
{
    use ReconnectsOnLoopFailure;

    /** Um aviso de aparelho não registado por identidade e por esta janela, e não por mensagem. */
    private const UNAUTHORIZED_RECORD_INTERVAL_SECONDS = 60;

    private MqttClient $subscriber;

    /** @var null|callable(): MqttClient */
    private $reconnectSubscriber;

    /** @var array<string, int> */
    private array $lastUnauthorizedAt = [];

    public function __construct(
        MqttClient $subscriber,
        protected readonly Whitelist $whitelist,
        protected readonly HubMqttBridge $mqttBridge,
        protected readonly string $topicFilter,
        protected readonly ?string $sourceName = null,
        ?callable $reconnectSubscriber = null,
        protected readonly ?DashboardStoreContract $dashboardStore = null,
        protected readonly ?Denylist $denylist = null,
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
     * O dono só é conhecido por quem o consegue tirar do que recebeu. O radar consegue --
     * publica em `radar/{licenseId}/{uid}` --, e é o que permite à dashboard pré-seleccionar
     * a licença ao registar. Quem se identifica só por MAC ou por endereço não passa nenhum
     * dos dois: a empresa e a licença vêm juntas ou não vêm.
     */
    protected function recordUnauthorizedDevice(
        string $identity,
        string $protocol,
        string $model = '',
        string $ident = '',
        int $licenseId = 0,
        ?string $company = null,
    ): void {
        // Bloqueado de propósito: cala-se na fonte, sem notificação nem sequer a escrita do
        // estrangulamento em memória.
        if ($this->denylist?->contains($identity)) {
            return;
        }

        // Um aparelho não registado que insiste -- um radar publica ~20 msg/s -- não pode dar
        // uma escrita ao MySQL por mensagem. O aviso regista-se uma vez por identidade e janela.
        $now = time();
        $last = $this->lastUnauthorizedAt[$identity] ?? null;
        if ($last !== null && ($now - $last) < self::UNAUTHORIZED_RECORD_INTERVAL_SECONDS) {
            return;
        }
        $this->lastUnauthorizedAt[$identity] = $now;

        try {
            $this->dashboardStore?->recordRejectedDevice(
                $identity,
                $protocol,
                $model,
                $ident,
                'device_not_authorized',
                $licenseId,
                $company
            );
        } catch (\Throwable $e) {
            Logger::channel('hub')->error(
                "Failed to record rejected device identity={$identity}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Acrescenta o nome comercial ao dispositivo, quando o resolvedor o conhece e ele ainda
     * não o traz. Comum aos três ingressos MQTT, que subscrevem o mesmo resolvedor.
     *
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    protected function enrichWithCommercialName(array $device, ?CommercialModelResolver $resolver): array
    {
        // A resolução corre por mensagem, mas assenta num lookup O(1) no índice em memória do
        // ModelRepository, invalidado quando um modelo muda -- não justifica um memo à parte.
        $commercialName = $resolver?->resolveCommercialName(
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? '')
        ) ?? '';

        if ($commercialName !== '') {
            $device['commercialName'] = $commercialName;
        }

        return $device;
    }

    /**
     * Se um gateway e um aparelho retransmitido pertencem ao mesmo cliente.
     *
     * A ligação entre os dois é editável na dashboard, e um engano ali não pode bastar para a
     * telemetria de um cliente sair debaixo de outro: a ligação diz que o gateway *ouve* o
     * aparelho, isto diz que pode *falar* por ele.
     *
     * O `'null'` é a sentinela de sem dono, e vale dos dois lados -- dois aparelhos sem
     * cliente são o mesmo não-cliente, e não dois clientes diferentes.
     *
     * @param array<string, mixed> $gateway
     * @param array<string, mixed> $device
     */
    protected function sameTenant(array $gateway, array $device): bool
    {
        return (string)($gateway['company'] ?? 'null') === (string)($device['company'] ?? 'null')
            && (string)($gateway['licenseId'] ?? '') === (string)($device['licenseId'] ?? '');
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
