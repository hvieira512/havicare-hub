<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt;

use Hub\State\DeviceReportStore;
use Hub\Device\CommercialModelResolver;
use Hub\Device\HubMqttBridge;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\Gateway\GatewayTopic;
use Hub\Ingress\Mqtt\Gateway\GattDeviceContext;
use Hub\Log\Logger;
use Hub\Mqtt\ReconnectsOnLoopFailure;
use Hub\Registry\Denylist;
use Hub\Registry\Whitelist;
use PhpMqtt\Client\MqttClient;

abstract class MqttBridgeBase implements MqttIngress
{
    use ReconnectsOnLoopFailure;

    /** Um aviso de aparelho não registado por identidade e por esta janela, e não por mensagem. */
    private const UNAUTHORIZED_RECORD_INTERVAL_SECONDS = 60;

    /** E o mesmo para as queixas que se repetem a cada trama. */
    private const REPEATED_WARNING_INTERVAL_SECONDS = 60;

    private MqttClient $subscriber;

    /** @var null|callable(): MqttClient */
    private $reconnectSubscriber;

    /** @var array<string, int> */
    private array $lastUnauthorizedAt = [];

    /**
     * Quando cada queixa repetida foi escrita pela última vez.
     *
     * @var array<string, int>
     */
    private array $lastWarnedAt = [];

    private int $lastUnauthorizedPruneAt = 0;

    private const LINK_SIGNAL_SECONDS = 10.0;

    /** @var array<string, float> */
    private array $linkSignalAt = [];

    private \Closure $clock;

    public function __construct(
        MqttClient $subscriber,
        protected readonly Whitelist $whitelist,
        protected readonly HubMqttBridge $mqttBridge,
        protected readonly string $topicFilter,
        protected readonly ?string $sourceName = null,
        ?callable $reconnectSubscriber = null,
        protected readonly ?DeviceReportStore $deviceStore = null,
        protected readonly ?Denylist $denylist = null,
        ?callable $clock = null,
        protected readonly ?CommercialModelResolver $commercialModelResolver = null,
    ) {
        $this->subscriber = $subscriber;
        $this->reconnectSubscriber = $reconnectSubscriber;
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): float => microtime(true);
    }

    /** O relógio do ingress, em segundos com fracção; na base porque o travão dos avisos também o usa. */
    protected function clockNow(): float
    {
        return (float)($this->clock)();
    }

    abstract protected function handleMessage(string $topic, string $payload): void;

    /**
     * Escreve um aviso uma vez por assunto e por janela. O `$subject` é o que conta como a
     * mesma queixa, como o par aparelho/gateway.
     */
    protected function warnRepeatedly(string $subject, string $message): void
    {
        $now = (int)$this->clockNow();
        foreach ($this->lastWarnedAt as $key => $at) {
            if (($now - $at) >= self::REPEATED_WARNING_INTERVAL_SECONDS) {
                unset($this->lastWarnedAt[$key]);
            }
        }

        if (isset($this->lastWarnedAt[$subject])) {
            return;
        }

        $this->lastWarnedAt[$subject] = $now;
        Logger::channel('hub')->warning($message);
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

    public function handleReceivedMessage(string $topic, string $payload): void
    {
        $this->handleMessage($topic, $payload);
    }

    /**
     * O dono só o passa quem o tira do que recebeu, como o radar pelo tópico; a empresa e a
     * licença vêm juntas ou não vêm.
     */
    protected function recordUnauthorizedDevice(
        string $identity,
        string $protocol,
        string $model = '',
        string $ident = '',
        int $licenseId = 0,
        ?string $company = null,
    ): void {
        // Uma trama sem identidade é malformada, e não um aparelho por autorizar.
        if ($identity === '') {
            return;
        }

        // Bloqueado cala-se na fonte, sem notificação nem escrita no estrangulamento.
        if ($this->denylist?->contains($identity)) {
            return;
        }

        // Um radar publica ~20 msg/s: o aviso regista-se uma vez por identidade e janela.
        $now = (int)$this->clockNow();
        $this->forgetExpiredUnauthorized($now);
        $last = $this->lastUnauthorizedAt[$identity] ?? null;
        if ($last !== null && ($now - $last) < self::UNAUTHORIZED_RECORD_INTERVAL_SECONDS) {
            return;
        }
        $this->lastUnauthorizedAt[$identity] = $now;

        try {
            $this->deviceStore?->recordRejectedDevice(
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
     * Esquece as identidades que já saíram da janela do travão, uma vez por janela: o processo
     * corre meses e o varrimento é linear.
     */
    private function forgetExpiredUnauthorized(int $now): void
    {
        if (($now - $this->lastUnauthorizedPruneAt) < self::UNAUTHORIZED_RECORD_INTERVAL_SECONDS) {
            return;
        }
        $this->lastUnauthorizedPruneAt = $now;

        foreach ($this->lastUnauthorizedAt as $identity => $at) {
            if (($now - $at) >= self::UNAUTHORIZED_RECORD_INTERVAL_SECONDS) {
                unset($this->lastUnauthorizedAt[$identity]);
            }
        }
    }

    /**
     * Acrescenta o nome comercial ao dispositivo, quando o resolvedor o conhece e ele não o traz.
     *
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    protected function enrichWithCommercialName(array $device): array
    {
        // Lookup O(1) no índice em memória do ModelRepository; não precisa de memo.
        return $this->commercialModelResolver?->enrich($device) ?? $device;
    }

    /**
     * Se um gateway pode *falar* por um aparelho retransmitido: pertencem ao mesmo cliente,
     * com `'null'` como sentinela de sem dono dos dois lados.
     *
     * @param array<string, mixed> $gateway
     * @param array<string, mixed> $device
     */
    protected function sameTenant(array $gateway, array $device): bool
    {
        return (string)($gateway['company'] ?? 'null') === (string)($device['company'] ?? 'null')
            && (string)($gateway['licenseId'] ?? '') === (string)($device['licenseId'] ?? '');
    }

    /**
     * O aparelho de uma mensagem de um gateway GATT, com o gateway que o serve: os dois na
     * whitelist, ligados e do mesmo cliente. Um aparelho desconhecido fica registado como
     * não autorizado.
     *
     * @param array<string, mixed> $message
     */
    protected function resolveGattDevice(string $topic, array $message, GatewayDeviceLinkLookup $links, string $protocol, string $deviceType): ?GattDeviceContext
    {
        $parsed = GatewayTopic::parse($topic);
        $gateway = $parsed === null ? null : $this->whitelist->resolve($parsed->gatewayMac);
        if ($gateway === null || ($gateway['deviceType'] ?? '') !== 'gateway') {
            return null;
        }

        $mac = GatewayTopic::normalizeMac((string)($message['device']['mac'] ?? ''));
        $device = $mac === null ? null : $this->whitelist->resolve($mac);
        if ($device === null || ($device['deviceType'] ?? '') !== $deviceType) {
            $this->recordUnauthorizedDevice((string)$mac, $protocol, trim((string)($message['device']['model'] ?? '')), ident: (string)$mac);
            return null;
        }
        if (!$links->isEnabled((string)$gateway['imei'], (string)$device['imei']) || !$this->sameTenant($gateway, $device)) {
            $this->warnRepeatedly(
                "unlinked:{$mac}:{$gateway['imei']}",
                "Ignoring unlinked {$protocol} device={$mac} gateway={$gateway['imei']}"
            );
            return null;
        }

        $device = $this->enrichWithCommercialName($device);
        $this->recordLinkSignal((string)$device['imei'], (string)$gateway['imei'], $message);

        return new GattDeviceContext(
            (string)$device['imei'],
            (string)$gateway['imei'],
            $device,
            (int)($device['licenseId'] ?? 0),
            (string)($device['company'] ?? 'null'),
        );
    }

    /**
     * O sinal com que um gateway BLE ouve o aparelho, quando o diz em `device.rssiDbm`: é da ligação
     * entre os dois, como o de um MOKO. Guarda-se de 10 em 10 s, porque há aparelhos a 11 tramas por segundo.
     *
     * @param array<string, mixed> $message
     */
    protected function recordLinkSignal(string $deviceKey, string $gatewayKey, array $message): void
    {
        $rssiDbm = $message['device']['rssiDbm'] ?? null;
        if (!is_int($rssiDbm)) {
            return;
        }
        $now = $this->clockNow();
        if ($now - ($this->linkSignalAt[$deviceKey . '|' . $gatewayKey] ?? -INF) < self::LINK_SIGNAL_SECONDS) {
            return;
        }
        $this->linkSignalAt[$deviceKey . '|' . $gatewayKey] = $now;
        $this->deviceStore?->recordGatewaySighting($deviceKey, $gatewayKey, $rssiDbm);
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
