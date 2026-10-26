<?php

declare(strict_types=1);

namespace Hub\Ingress\Tuya;

use Hub\Device\CommercialModelResolver;
use Hub\Device\ConnectionAnnouncer;
use Hub\Device\HubMqttBridge;
use Hub\Domain\DeviceMetadata;
use Hub\Ingress\Mqtt\MqttIngress;
use Hub\Log\Logger;
use Hub\Registry\Denylist;
use Hub\Registry\Whitelist;
use Hub\State\DeviceReportStore;
use Hub\State\DeviceRuntimeStore;
use Hub\Support\Throttle;
use Predis\ClientInterface;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * Recebe os aparelhos ligados ao projeto Tuya pelo Message Service, um Pulsar por WebSocket.
 * Cada instância do hub lê a sua própria subscrição: duas na mesma repartiam as mensagens.
 */
final class TuyaBridge implements MqttIngress
{
    private const PROTOCOL = 'tuya-cloud';
    private const PING_SECONDS = 30;
    /** Sem nada do servidor durante três pings, a ligação está meio-morta e fecha-se. */
    private const SILENCE_SECONDS = 90;
    /** Uma falha do hub deixa a mensagem para o Pulsar voltar a entregar, mas não para sempre. */
    private const MAX_DELIVERIES = 5;
    /** A Tuya guarda as mensagens duas horas: lembrada três, uma reentrega já não volta a publicar. */
    private const HANDLED_TTL_SECONDS = 10800;
    private const HANDLED_KEY = 'hub:tuya:handled:';

    private readonly MessageNormalizer $normalizer;
    private readonly ConnectionAnnouncer $connections;
    private readonly LoopInterface $loop;
    private int $attempt = 0;

    /** @var array<string, int> entregas falhadas por `messageId` */
    private array $failedDeliveries = [];

    /** Cada aparelho por registar é anotado uma vez por minuto. */
    private readonly Throttle $rejected;

    public function __construct(
        private readonly string $accessId,
        private readonly string $accessSecret,
        private readonly string $pulsarUrl,
        private readonly string $subscription,
        private readonly Whitelist $whitelist,
        private readonly HubMqttBridge $mqttBridge,
        private readonly ?DeviceReportStore $deviceStore = null,
        private readonly ?CommercialModelResolver $commercialModelResolver = null,
        private readonly ?Denylist $denylist = null,
        ?LoopInterface $loop = null,
        private readonly ?ClientInterface $redis = null,
    ) {
        $this->normalizer = new MessageNormalizer();
        $this->connections = new ConnectionAnnouncer($mqttBridge, $deviceStore);
        $this->loop = $loop ?? Loop::get();
        $this->rejected = new Throttle();
    }

    /** A ligação é assíncrona e volta a tentar sozinha: uma cloud em baixo não impede o hub de arrancar. */
    public function start(): void
    {
        $this->connect();
    }

    /** O socket é conduzido pelo próprio loop. */
    public function tick(float $timeout = 0.01): void
    {
    }

    /**
     * Trata uma trama e diz o que confirmar. Uma que não se lê confirma-se, para não voltar para
     * sempre; uma falha do hub não, e o Pulsar volta a entregá-la até à quinta vez.
     *
     * @return string|null o `messageId` a confirmar
     */
    public function receive(string $frame): ?string
    {
        $wire = json_decode($frame, true);
        $messageId = is_array($wire) && is_string($wire['messageId'] ?? null) ? $wire['messageId'] : null;
        if ($messageId === null) {
            Logger::channel('hub')->warning('Ignoring Tuya frame without a messageId');
            return null;
        }

        try {
            if (!$this->wasHandled($messageId)) {
                $this->handle($wire, $messageId);
            }
        } catch (\Throwable $e) {
            $deliveries = ($this->failedDeliveries[$messageId] ?? 0) + 1;
            if ($deliveries < self::MAX_DELIVERIES) {
                $this->failedDeliveries[$messageId] = $deliveries;
                Logger::channel('hub')->error("Tuya message left for redelivery ({$deliveries}): {$e->getMessage()}");
                return null;
            }
            Logger::channel('hub')->error("Tuya message dropped after {$deliveries} deliveries: {$e->getMessage()}");
        }
        unset($this->failedDeliveries[$messageId]);

        return $messageId;
    }

    public function handleReceivedMessage(string $topic, string $payload): void
    {
        $this->handle((array)json_decode($payload, true));
    }

    /** @param array<string, mixed> $wire */
    private function handle(array $wire, ?string $messageId = null): void
    {
        try {
            $business = PulsarFrame::open($wire, $this->accessSecret);
        } catch (\RuntimeException $e) {
            Logger::channel('hub')->warning("Ignoring Tuya message: {$e->getMessage()}");
            return;
        }

        $deviceId = (string)($business['bizData']['devId'] ?? '');
        if ($deviceId === '' || !MessageNormalizer::isFromDevice($business)) {
            return;
        }

        $device = $this->whitelist->resolve($deviceId, self::PROTOCOL);
        if ($device === null) {
            if (!$this->denylist?->contains($deviceId) && $this->rejected->allow($deviceId, 60, time())) {
                $this->deviceStore?->recordRejectedDevice($deviceId, self::PROTOCOL, '', $deviceId, 'device_not_authorized');
            }
            return;
        }

        $device = $this->commercialModelResolver?->enrich($device) ?? $device;
        $normalized = $this->normalizer->normalize($business, $device);
        $deviceType = (string)$device['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');

        $this->mqttBridge->publishAndStore($this->deviceStore, 'raw', $deviceId, $normalized['raw'], $deviceType, $licenseId, $company);
        // Adormecido, o botão alcança-se ao carregar: fica `idle`, e o prazo continua a contar do
        // último despertar.
        if (($business['bizCode'] ?? null) === 'deviceOffline') {
            if ($this->deviceStore?->deviceAsleep($deviceId, true) ?? false) {
                $this->connections->status($deviceId, $device, true, asleep: true);
            }
            return;
        }
        $woke = $this->deviceStore?->deviceAsleep($deviceId, false) ?? false;
        $cameOnline = $this->deviceStore?->deviceSeen($deviceId, DeviceRuntimeStore::seenFields($device, self::PROTOCOL, 'pulsar')) ?? false;
        if ($cameOnline) {
            $this->connections->announce($deviceId, $device, true);
        } elseif ($woke) {
            $this->connections->status($deviceId, $device, true);
        }

        foreach ($normalized['telemetry'] as $telemetry) {
            $this->mqttBridge->publishAndStore($this->deviceStore, 'telemetry', $deviceId, $telemetry, $deviceType, $licenseId, $company);
        }
        // Marca-se depois de o alarme sair e antes de se guardar: uma falha a guardar já não o repete.
        foreach ($normalized['events'] as $event) {
            $this->mqttBridge->publishAndStore(null, 'events', $deviceId, $event, $deviceType, $licenseId, $company);
        }
        if ($messageId !== null) {
            $this->markHandled($messageId);
        }
        foreach ($normalized['events'] as $event) {
            $this->deviceStore?->append($deviceId, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
        }
    }

    /** Sem Redis não se sabe se já saiu: publica-se, porque um alarme repetido vale mais do que um perdido. */
    private function wasHandled(string $messageId): bool
    {
        try {
            return $this->redis?->get(self::HANDLED_KEY . $messageId) !== null;
        } catch (\Throwable $e) {
            Logger::channel('hub')->warning("Tuya redelivery check unavailable: {$e->getMessage()}");
            return false;
        }
    }

    private function markHandled(string $messageId): void
    {
        try {
            $this->redis?->setex(self::HANDLED_KEY . $messageId, self::HANDLED_TTL_SECONDS, '1');
        } catch (\Throwable $e) {
            Logger::channel('hub')->warning("Tuya message not marked as handled: {$e->getMessage()}");
        }
    }

    private function connect(): void
    {
        $url = rtrim($this->pulsarUrl, '/')
            . "/ws/v2/consumer/persistent/{$this->accessId}/out/event/{$this->subscription}"
            . '?ackTimeoutMillis=3000&subscriptionType=Failover';
        $headers = ['username' => $this->accessId, 'password' => PulsarFrame::password($this->accessId, $this->accessSecret)];

        (new Connector($this->loop))($url, [], $headers)->then(
            function (WebSocket $socket): void {
                $this->attempt = 0;
                Logger::channel('hub')->info("Tuya ingress subscribed as {$this->subscription}");
                $heardAt = microtime(true);
                $heard = static function () use (&$heardAt): void {
                    $heardAt = microtime(true);
                };

                $ping = $this->loop->addPeriodicTimer(self::PING_SECONDS, static function () use ($socket, &$heardAt): void {
                    if (microtime(true) - $heardAt > self::SILENCE_SECONDS) {
                        $socket->close();
                        return;
                    }
                    $socket->send(new Frame('', true, Frame::OP_PING));
                });

                $socket->on('pong', $heard);
                $socket->on('message', function ($message) use ($socket, $heard): void {
                    $heard();
                    $messageId = $this->receive((string)$message);
                    if ($messageId !== null) {
                        $socket->send((string)json_encode(['messageId' => $messageId]));
                    }
                });
                $socket->on('close', function ($code, $reason) use ($ping): void {
                    $this->loop->cancelTimer($ping);
                    Logger::channel('hub')->warning("Tuya ingress connection closed ({$code} {$reason})");
                    $this->retry();
                });
            },
            function (\Throwable $e): void {
                Logger::channel('hub')->error("Tuya ingress connection failed: {$e->getMessage()}");
                $this->retry();
            },
        );
    }

    private function retry(): void
    {
        $this->loop->addTimer(min(60, 2 ** $this->attempt++), fn() => $this->connect());
    }
}
