<?php

declare(strict_types=1);

namespace Hub\Device;

use Hub\Command\DeviceCommandCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\Medication\MedicationOccurrence;
use Hub\Domain\Capability\Medication\MedicationRunout;
use Hub\Domain\ProtocolRegistry;
use Hub\Log\Logger;
use Hub\Location\LocationTelemetryEnricherContract;
use Hub\State\DeviceStoreContract;
use Hub\Protocol\AdapterRegistry;
use Hub\Registry\Denylist;
use Hub\Registry\Whitelist;
use Hub\Ingress\Tcp\TcpMessage;
use Hub\Device\Firmware\FirmwareUpgradeStore;
use Hub\Ingress\Tcp\TcpProtocolRegistry;
use Hub\Ingress\Tcp\TcpResponse;

class DeviceHubServer
{
    private Whitelist $whitelist;
    private ConnectionRegistry $connections;
    private DeviceAuthorizer $authorizer;
    private DeviceIdentityExtractor $identityExtractor;
    private TcpProtocolRegistry $tcpProtocols;
    private HubMqttBridge $mqtt;
    private ?RedisPendingDownlinkQueue $downlinkQueue;
    private ?DeviceStoreContract $deviceStore;

    /** @var array<string, int> */
    private array $withheldWarnedAt = [];
    private int $downlinkQueueTtlSeconds;
    private ?LocationTelemetryEnricherContract $locationTelemetryEnricher;
    private ?Denylist $denylist;
    private LowBatteryTransitions $lowBattery;
    /** @var (\Closure(string): array<string, mixed>)|null */
    private ?\Closure $effectiveConfiguration;

    public function __construct(
        Whitelist $whitelist,
        HubMqttBridge $mqtt,
        ?CommercialModelResolver $commercialModelResolver = null,
        ?RedisPendingDownlinkQueue $downlinkQueue = null,
        ?DeviceStoreContract $deviceStore = null,
        int $downlinkQueueTtlSeconds = 300,
        ?LocationTelemetryEnricherContract $locationTelemetryEnricher = null,
        ?Denylist $denylist = null,
        ?FirmwareUpgradeStore $firmwareUpgrades = null,
        ?\Closure $effectiveConfiguration = null,
        private readonly ?\Closure $downlinkQueued = null,
        ?\Closure $clock = null,
    ) {
        $this->effectiveConfiguration = $effectiveConfiguration;
        $this->whitelist = $whitelist;
        $this->denylist = $denylist;
        $this->connections = new ConnectionRegistry($clock);
        $this->authorizer = new DeviceAuthorizer($whitelist, $commercialModelResolver);
        $this->mqtt = $mqtt;
        $this->deviceStore = $deviceStore;
        $adapters = new AdapterRegistry();
        $this->identityExtractor = new DeviceIdentityExtractor($adapters);
        $this->tcpProtocols = new TcpProtocolRegistry(
            $adapters,
            fn (DeviceSession $session): array => $this->wonlexState($session),
            $firmwareUpgrades,
        );
        $this->downlinkQueue = $downlinkQueue;
        $this->downlinkQueueTtlSeconds = max(1, $downlinkQueueTtlSeconds);
        $this->locationTelemetryEnricher = $locationTelemetryEnricher;
        $this->lowBattery = new LowBatteryTransitions();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->connections->open($conn);
    }

    public function onMessage(ConnectionInterface $from, string $msg): void
    {
        $rid = $from->resourceId;
        $raw = (string)$msg;
        $session = $this->connections->get($from) ?? $this->connections->open($from);
        $this->connections->touch($from);

        if (!$session->authenticated) {
            $this->authenticate($from, $raw, $session);
            return;
        }

        $this->handleAuthenticatedMessage($from, $session, $raw, (string)$rid);
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $session = $this->connections->close($conn);

        if ($session === null || !$session->authenticated) {
            return;
        }

        $this->goOffline($session);
        Logger::channel('hub')->info("Device offline IMEI={$session->imei}");
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        Logger::channel('hub')->error("Connection error id={$conn->resourceId}: {$e->getMessage()}");
        $conn->close();
    }

    /** Com o broker em baixo, o aparelho repete cada pacote: o aviso sai uma vez por minuto. */
    private function warnWithheld(string $imei): void
    {
        $now = time();
        if ($now - ($this->withheldWarnedAt[$imei] ?? 0) < 60) {
            return;
        }
        $this->withheldWarnedAt[$imei] = $now;
        Logger::channel('hub')->warning("Reply withheld until the event is published IMEI={$imei}");
    }

    public function isConnected(string $imei): bool
    {
        return $this->connections->connectionFor($imei) instanceof ConnectionInterface;
    }

    /** @param array<string, mixed>|null $context o pedido de onde o comando vem; o `id` segue no evento */
    public function sendDownlink(string $imei, string $bytes, ?array $context = null): bool
    {
        $conn = $this->connections->connectionFor($imei);
        if (!$conn instanceof ConnectionInterface) {
            return false;
        }

        $conn->send($bytes);
        $session = $this->connections->get($conn);
        $device = ['supplier' => $session?->supplier ?? '', 'model' => $session?->model ?? ''] + $this->current($imei, $session);
        try {
            $this->announce($imei, $device, 'device.downlink.sent', null, self::withRequestId($this->commandMetadata($bytes, $session?->protocol), $context));
            if ($session !== null) {
                $this->mqtt->publishRaw(
                    $imei,
                    TelemetryEnvelope::raw($imei, $session->supplier, $session->model, 'tcp', $session->protocol, $bytes, 'downlink', (string)$conn->resourceId, $device['commercialName']),
                    $session->deviceType,
                    $device['licenseId'],
                    $device['company'],
                );
            }
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }

        return true;
    }

    /**
     * @param array<string, mixed>|null $context
     * @return 'sent'|'queued'|'dropped'
     */
    public function submitDownlink(string $imei, string $bytes, ?array $context = null): string
    {
        if ($this->sendDownlink($imei, $bytes, $context)) {
            return 'sent';
        }

        return $this->queueDownlink($imei, $bytes, $context) ? 'queued' : 'dropped';
    }

    /** @param array<string, mixed>|null $context */
    public function reportDownlinkDropped(string $imei, string $reason, ?string $bytes = null, ?array $context = null): void
    {
        try {
            $this->announce(
                $imei,
                $this->authorizer->metadataFor($imei),
                'device.downlink.dropped',
                $this->errorPayload($reason),
                self::withRequestId($bytes !== null ? $this->commandMetadata($bytes) : null, $context),
            );
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }

    /** @param array<string, mixed>|null $context */
    public function queueDownlink(string $imei, string $bytes, ?array $context = null): bool
    {
        if ($this->downlinkQueue === null) {
            $this->reportDownlinkDropped($imei, 'device_offline', $bytes, $context);
            return false;
        }

        $command = self::withoutBinaryValues(array_merge($this->commandMetadata($bytes) ?? [], $context ?? []));

        // A manutenção renova de minuto a minuto o comando que espera pelo aparelho: o anúncio
        // é do pedido, e só sai quando ele entra na fila.
        $id = (string)($command['id'] ?? '');
        $renewal = $id !== '' && array_filter(
            $this->downlinkQueue->pendingFor($imei),
            static fn (PendingDownlink $pending): bool => (string)($pending->command['id'] ?? '') === $id,
        ) !== [];

        try {
            $this->downlinkQueue->enqueue($imei, $bytes, $command, $this->downlinkQueueTtlSeconds);
            // Quem entrega fora do socket, como a cloud dos radares, não espera pelo aparelho.
            if ($this->downlinkQueued !== null) {
                ($this->downlinkQueued)($imei);
            }
            if ($renewal) {
                return true;
            }
            $this->announce($imei, $this->authorizer->metadataFor($imei), 'device.downlink.queued', null, $command);
            return true;
        } catch (\Throwable $e) {
            Logger::channel('hub')->error("Failed to queue downlink for IMEI={$imei}: {$e->getMessage()}");
            $this->reportDownlinkDropped($imei, 'queue_unavailable', $bytes, $context);
            return false;
        }
    }

    public function expireIdleConnections(int $idleSeconds): void
    {
        foreach ($this->connections->expireIdleConnections($idleSeconds) as $session) {
            $this->goOffline($session);
            Logger::channel('hub')->warning("Device offline by idle timeout IMEI={$session->imei} idle_seconds={$idleSeconds}");
        }
    }

    private function authenticate(ConnectionInterface $conn, string $raw, DeviceSession $session): void
    {
        $identity = $this->identityExtractor->identify($raw, $session->identityContext());
        if ($identity === null) {
            if (!$session->unidentifiedWarningLogged) {
                $session->unidentifiedWarningLogged = true;
                // A origem vai junto: separa um varredor de portas de um dispositivo cujo protocolo não
                // sabemos ler.
                $from = $conn->remoteAddress() ?? 'desconhecida';
                Logger::channel('hub')->warning(
                    "Connection id={$conn->resourceId} from={$from} sent data before identifiable login"
                );
            }
            return;
        }

        $authorization = $this->authorizer->authorize($identity);
        if (!$authorization->allowed) {
            $this->reject($conn, $identity, $authorization->reason ?? 'authorization_failed');
            return;
        }

        if ($authorization->imei !== '' && $authorization->imei !== $identity->imei) {
            $identity = $identity->withImei($authorization->imei);
        }

        $session = $this->connections->authenticate(
            $conn,
            $identity,
            $authorization->supplier,
            $authorization->model,
            $authorization->commercialName,
            $authorization->deviceType,
            $authorization->licenseId,
            $authorization->company,
        );
        $this->markSeen($session, (string)$conn->resourceId);
        $this->publishStatus($session, 'online');
        $this->publishEvent($session, 'device.connected');

        // Um registo por confirmar ainda não deixou o aparelho pronto: as ordens esperam.
        if ($this->handleAuthenticatedMessage($conn, $session, $raw, (string)$conn->resourceId)) {
            $this->flushPendingDownlinks($session);
        }

        Logger::channel('hub')->info("Device online IMEI={$identity->imei} protocol={$identity->protocol}");
    }

    /** Devolve se as respostas saíram: só não saem quando o aparelho apagaria o que não ficou publicado. */
    private function handleAuthenticatedMessage(ConnectionInterface $conn, DeviceSession $session, string $raw, string $connectionId): bool
    {
        $rawPublished = false;
        try {
            $this->mqtt->publishRaw(
                $session->imei,
                TelemetryEnvelope::raw($session->imei, $session->supplier, $session->model, 'tcp', $session->protocol, $raw, 'uplink', $connectionId, $session->commercialName),
                $session->deviceType,
                ...$this->owner($session),
            );
            $rawPublished = true;
            $this->recordRaw($session, $raw, $connectionId);
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $session->imei, $e);
        }

        $protocol = $this->tcpProtocols->get($session->protocol);
        if ($protocol === null) {
            return true;
        }

        $message = $protocol->handleIncoming($session, $raw);
        if (!($message instanceof TcpMessage)) {
            return true;
        }

        // Se a trama confirma uma configuração é conhecimento do protocolo: aqui serve-se
        // tudo o que fala TCP, e um `if` por fornecedor neste sítio cresce com a frota.
        $this->deviceStore?->markCommandReply(
            $session->imei,
            (string)($message->decoded['type'] ?? ''),
            $message->decoded['ident'] ?? null,
            (string)($message->decoded['ref'] ?? ''),
            $protocol->replyAccepted($message->decoded),
        );

        $published = true;
        foreach ($message->telemetry as $event) {
            if (($event['type'] ?? null) === 'location' && $this->locationTelemetryEnricher !== null) {
                $this->locationTelemetryEnricher->enrich($event)->then(
                    fn (array $enriched): bool => $this->publishTelemetryEvent($session, $enriched),
                    function (\Throwable $error) use ($session, $event): bool {
                        Logger::channel('hub')->warning(
                            "Location resolution failed IMEI={$session->imei}: {$error->getMessage()}"
                        );
                        return $this->publishTelemetryEvent($session, $event);
                    }
                );
            } else {
                $published = $this->publishTelemetryEvent($session, $this->withMedicationPlan($session, $event)) && $published;
            }
        }

        // Sem resposta, o aparelho guarda o evento e volta a mandá-lo.
        if ($message->onlyInRaw && !$rawPublished) {
            $published = false;
        }
        if (!$published && $message->repliesConfirmDelivery) {
            $this->warnWithheld($session->imei);
            if ($message->withheld !== null) {
                ($message->withheld)();
            }
            return false;
        }

        foreach ($message->responses as $response) {
            $this->sendTcpResponse($session, $response, $conn, $connectionId);
        }

        return true;
    }

    /**
     * O que o dispensador conta no relógio dele sai situado pelo plano e pelo fuso que o hub confirmou:
     * a data em que o prato acaba, e as horas das tomas em UTC.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function withMedicationPlan(DeviceSession $session, array $event): array
    {
        $type = $event['type'] ?? null;
        $remaining = $event['data']['remaining'] ?? null;
        $applies = ($type === 'cells_remaining' && is_int($remaining) && $this->effectiveConfiguration !== null)
            || $type === 'medication_intake'
            || $type === 'medication_alarm_change';
        if (!$applies) {
            return $event;
        }

        try {
            $configurations = $this->effectiveConfiguration === null ? [] : ($this->effectiveConfiguration)($session->imei);
        } catch (\Throwable $error) {
            Logger::channel('hub')->warning("Medication plan unavailable IMEI={$session->imei}: {$error->getMessage()}");
            $configurations = [];
        }

        $now = new \DateTimeImmutable();
        $event['data'] = match ($type) {
            'cells_remaining' => $event['data'] + ['runoutAt' => MedicationRunout::at($remaining, $configurations, $now)],
            'medication_intake' => MedicationOccurrence::intake($event['data'], $configurations),
            default => MedicationOccurrence::alarmChange($event['data'], $configurations, $now),
        };

        return $event;
    }

    /** @param array<string, mixed> $event */
    private function publishTelemetryEvent(DeviceSession $session, array $event): bool
    {
        try {
            ['licenseId' => $licenseId, 'company' => $company] = $this->current($session->imei, $session);
            $type = $event['type'] ?? null;

            // Os eventos saem por `events` (QoS 1) e as leituras por `telemetry` (QoS 0); a `location` de
            // um alarme fica em `telemetry`, ligada por `reportKind: "alarm"`. Decide o `isEvent` do catálogo.
            $channel = CapabilityCatalog::isEventType((string)$type) ? 'events' : 'telemetry';
            if ($channel === 'events') {
                $this->mqtt->publishEvent($session->imei, $event, $session->deviceType, $licenseId, $company);
            } else {
                $this->mqtt->publishTelemetry($session->imei, $event, $session->deviceType, $licenseId, $company);
            }
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $session->imei, $e);
            return false;
        }

        // O histórico é um extra: falhar aqui não desfaz o que saiu.
        try {
            $this->deviceStore?->append($session->imei, $channel, array_merge(
                $event,
                ['deviceType' => $session->deviceType, 'licenseId' => $licenseId]
            ));
        } catch (\Throwable $e) {
            Logger::channel('hub')->error("Failed to record {$type} IMEI={$session->imei}: {$e->getMessage()}");
        }

        $lowBattery = $this->lowBattery->observe($session->imei, $event);
        if ($lowBattery !== null && !$this->publishTelemetryEvent($session, $lowBattery)) {
            // Por publicar, o alerta tem de voltar a acender na repetição.
            $this->lowBattery->forget($session->imei);
            return false;
        }

        return true;
    }

    private function reject(ConnectionInterface $conn, DeviceIdentity $identity, string $reason): void
    {
        // Bloqueado de propósito: fecha-se em silêncio, sem notificação na dashboard nem
        // estado/evento no broker. O socket fecha na mesma.
        if ($this->denylist?->contains($identity->imei) || $this->denylist?->contains($identity->ident)) {
            $conn->close();
            return;
        }

        if ($reason === 'device_not_authorized') {
            try {
                $this->deviceStore?->recordRejectedDevice(
                    $identity->imei,
                    $identity->protocol,
                    $identity->model,
                    $identity->ident,
                    $reason
                );
            } catch (\Throwable $e) {
                Logger::channel('hub')->error(
                    "Failed to record rejected device IMEI={$identity->imei}: {$e->getMessage()}"
                );
            }
        }

        $error = $this->errorPayload($reason);
        try {
            // `retain: false`: uma recusa é um acontecimento e não um estado, e retida chegava a quem
            // subscrevesse depois.
            $deviceType = ProtocolRegistry::describe($identity->protocol)['deviceType'];
            $this->mqtt->publishStatus($identity->imei, TelemetryEnvelope::status($identity->imei, '', '', 'error', $error), false, $deviceType, 0, 'null');
            $this->mqtt->publishEvent($identity->imei, TelemetryEnvelope::event($identity->imei, '', '', 'device.rejected', $error), $deviceType, 0, 'null');
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $identity->imei, $e);
        }

        Logger::channel('hub')->warning("Device rejected IMEI={$identity->imei} reason=$reason");
        $conn->close();
    }

    private function flushPendingDownlinks(DeviceSession $session): void
    {
        if ($this->downlinkQueue === null) {
            return;
        }

        try {
            $pending = $this->downlinkQueue->pendingFor($session->imei);
        } catch (\Throwable $e) {
            Logger::channel('hub')->error("Failed to read pending downlinks for IMEI={$session->imei}: {$e->getMessage()}");
            return;
        }

        foreach ($pending as $downlink) {
            try {
                $operationId = is_array($downlink->command) ? (string)($downlink->command['operationId'] ?? '') : '';
                if (
                    $operationId !== ''
                    && $this->deviceStore !== null
                    && !$this->deviceStore->isCurrentOperation($operationId)
                ) {
                    $this->deviceStore->markCommand($session->imei, $operationId, [
                        'status' => 'superseded',
                        'error' => '',
                    ]);
                    $this->downlinkQueue->remove($downlink);
                    continue;
                }
                if (!$this->sendDownlink($session->imei, $downlink->bytes, is_array($downlink->command) ? $downlink->command : null)) {
                    continue;
                }
                $nativeType = is_array($downlink->command) ? (string)($downlink->command['nativeType'] ?? '') : '';
                // O pedido encontra-se pelo seu identificador; o tipo da trama não é o nome com que foi registado.
                $commandId = $operationId !== '' ? $operationId : (is_array($downlink->command) ? (string)($downlink->command['id'] ?? '') : '');
                if ($commandId !== '') {
                    $record = $this->deviceStore?->findCommand($commandId)['command'] ?? [];
                    // Sem resposta prevista, sair é o fim; com ela, a repetição conta o prazo a partir de agora.
                    $this->deviceStore?->markCommand($session->imei, $commandId, [
                        'status' => ($record['expectedReplyTypes'] ?? null) === [] ? 'sent' : 'waiting',
                        'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                        'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() + (int)($record['retryDelaySeconds'] ?? 60)),
                    ]);
                } elseif ($nativeType !== '') {
                    $this->deviceStore?->markLatestCommand($session->imei, $nativeType, [
                        'status' => 'waiting',
                        'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                    ]);
                }
                $this->downlinkQueue->remove($downlink);
            } catch (\Throwable $e) {
                Logger::channel('hub')->error("Failed to flush pending downlink for IMEI={$session->imei}: {$e->getMessage()}");
            }
        }
    }

    /** Chamado quando o cliente de um dispositivo muda, com o cliente que ele está a deixar. */
    public function clearRetainedStatus(string $company, int $licenseId, string $deviceType, string $imei): void
    {
        try {
            $this->mqtt->clearRetainedStatus($company, $licenseId, $deviceType, $imei);
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }

    /** O fim de uma ligação: o estado e o evento no broker, e o aparelho dado por desligado. */
    private function goOffline(DeviceSession $session): void
    {
        $this->publishStatus($session, 'offline');
        $this->publishEvent($session, 'device.disconnected');
        $this->deviceStore?->deviceOffline($session->imei);
    }

    private function publishStatus(DeviceSession $session, string $state): void
    {
        $current = $this->current($session->imei, $session);
        try {
            $this->mqtt->publishStatus(
                $session->imei,
                TelemetryEnvelope::status($session->imei, $session->supplier, $session->model, $state, null, $current['commercialName']),
                true,
                $session->deviceType,
                $current['licenseId'],
                $current['company'],
            );
            if ($state === 'online') {
                $this->deviceStore?->deviceSeen($session->imei, [
                    'supplier' => $session->supplier,
                    'model' => $session->model,
                    'commercialName' => $current['commercialName'],
                    'deviceType' => $session->deviceType,
                    'licenseId' => $current['licenseId'],
                    'company' => $current['company'],
                    'online' => '1',
                ]);
            } elseif ($state === 'offline') {
                $this->deviceStore?->deviceOffline($session->imei);
            }
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $session->imei, $e);
        }
    }

    private function publishEvent(DeviceSession $session, string $type): void
    {
        $device = ['supplier' => $session->supplier, 'model' => $session->model, 'deviceType' => $session->deviceType]
            + $this->current($session->imei, $session);
        try {
            $this->announce($session->imei, $device, $type);
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $session->imei, $e);
        }
    }

    /**
     * Um evento do hub sobre o aparelho, no broker e no histórico; o erro só segue no broker.
     *
     * @param array{supplier: string, model: string, commercialName: string, deviceType: string, licenseId: int, company: string} $device
     * @param array<string, mixed>|null $error
     * @param array<string, mixed>|null $command
     */
    private function announce(string $imei, array $device, string $type, ?array $error = null, ?array $command = null): void
    {
        $event = static fn (?array $error): array => TelemetryEnvelope::event(
            $imei,
            $device['supplier'],
            $device['model'],
            $type,
            $error,
            $command,
            $device['commercialName'],
        );
        $this->mqtt->publishEvent($imei, $event($error), $device['deviceType'], $device['licenseId'], $device['company']);
        $this->deviceStore?->append($imei, 'events', array_merge(
            $event(null),
            ['deviceType' => $device['deviceType'], 'licenseId' => $device['licenseId']]
        ));
    }

    private function markSeen(DeviceSession $session, string $connectionId): void
    {
        $this->deviceStore?->deviceSeen($session->imei, [
            'supplier' => $session->supplier,
            'model' => $session->model,
            'commercialName' => $session->commercialName,
            'deviceType' => $session->deviceType,
            ...$this->owner($session),
            'protocol' => $session->protocol,
            'transport' => 'tcp',
            'online' => '1',
            'lastConnectionId' => $connectionId,
        ]);
    }

    private function recordRaw(DeviceSession $session, string $raw, string $connectionId): void
    {
        $this->markSeen($session, $connectionId);
        $this->deviceStore?->append($session->imei, 'raw', TelemetryEnvelope::raw(
            $session->imei,
            $session->supplier,
            $session->model,
            'tcp',
            $session->protocol,
            $raw,
            'uplink',
            $connectionId,
            $session->commercialName
        ));
    }

    /**
     * O dono, o nome comercial e o tipo de agora, lidos da whitelist e não da sessão: reassociar
     * um aparelho tem de valer já numa ligação viva. O que a whitelist não sabe fica o da sessão,
     * e a licença 0 é «sem atribuição», que não restringe o aparelho à licença 0.
     *
     * @return array{licenseId: int, company: string, commercialName: string, deviceType: string}
     */
    private function current(string $imei, ?DeviceSession $session): array
    {
        $metadata = $this->whitelist->getMetadata($imei);
        $licenseId = $metadata?->licenseId ?? 0;
        $company = $metadata?->company ?? '';
        $commercialName = $this->authorizer->commercialNameFor($metadata?->supplier ?? '', $metadata?->model ?? '');
        $deviceType = $metadata?->deviceType ?? 'watch';

        return [
            'licenseId' => $licenseId !== 0 ? $licenseId : ($session?->licenseId ?? 0),
            'company' => $company !== '' ? $company : ($session?->company ?? 'null'),
            'commercialName' => $commercialName !== '' ? $commercialName : ($session?->commercialName ?? ''),
            // Um tipo assumido publica no tópico errado sem erro: vale o da whitelist.
            'deviceType' => $deviceType !== '' ? $deviceType : ($session?->deviceType ?? ''),
        ];
    }

    /** @return array{licenseId: int, company: string} */
    private function owner(DeviceSession $session): array
    {
        $current = $this->current($session->imei, $session);

        return ['licenseId' => $current['licenseId'], 'company' => $current['company']];
    }

    /** @return array<string, mixed> */
    private function wonlexState(DeviceSession $session): array
    {
        $state = [
            'configurations' => $this->deviceStore?->desiredConfigurations($session->imei) ?? [],
        ];
        foreach ($this->deviceStore?->recent($session->imei, 'telemetry') ?? [] as $event) {
            $type = (string)($event['type'] ?? '');
            if ($type === 'sleep' && !isset($state['sleep']) && is_array($event['data'] ?? null)) {
                $state['sleep'] = $event['data'];
            }
        }

        return $state;
    }

    /**
     * @param array<string, mixed>|null $command
     * @param array<string, mixed>|null $context
     * @return array<string, mixed>|null
     */
    private static function withRequestId(?array $command, ?array $context): ?array
    {
        $id = (string)($context['operationId'] ?? $context['id'] ?? '');

        return $id === '' ? $command : ['id' => $id] + ($command ?? []);
    }

    /** @return array<string, mixed>|null */
    private function commandMetadata(string $bytes, ?string $protocol = null): ?array
    {
        return $this->tcpProtocols->commandMetadata($bytes, $protocol);
    }

    private function sendTcpResponse(DeviceSession $session, TcpResponse $response, ConnectionInterface $conn, string $connectionId): void
    {
        $conn->send($response->bytes);
        if ($response->publishRaw !== true) {
            return;
        }

        try {
            $this->mqtt->publishRaw(
                $session->imei,
                TelemetryEnvelope::raw($session->imei, $session->supplier, $session->model, 'tcp', $session->protocol, $response->bytes, 'downlink', $connectionId, $session->commercialName),
                $session->deviceType,
                ...$this->owner($session),
            );
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $session->imei, $e);
        }
    }

    /**
     * Os bytes vão em base64 à parte; binário nos metadados só rebenta o `json_encode` da
     * fila e do evento. É o caso do AMR de um lembrete com voz.
     *
     * @param array<array-key, mixed> $metadata
     * @return array<array-key, mixed>
     */
    private static function withoutBinaryValues(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                $metadata[$key] = self::withoutBinaryValues($value);
            } elseif (is_string($value) && preg_match('//u', $value) !== 1) {
                $metadata[$key] = '';
            }
        }

        return $metadata;
    }

    /** @return array<string, string> */
    private function errorPayload(string $code): array
    {
        return [
            'code' => $code,
            'message' => match ($code) {
                'device_not_authorized' => 'Device is not authorized',
                'device_offline' => 'Device is offline',
                'queue_unavailable' => 'Downlink queue is unavailable',
                default => str_replace('_', ' ', ucfirst($code)),
            },
        ];
    }
}
