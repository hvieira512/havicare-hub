<?php

namespace Hub\Device;

use Hub\Log\Logger;
use Hub\Location\LocationTelemetryEnricherContract;
use Hub\Dashboard\DashboardStoreContract;
use Hub\Protocol\AdapterRegistry;
use Hub\Registry\Whitelist;
use Hub\Device\Watch\WatchMessage;
use Hub\Device\Watch\WatchProtocolRegistry;
use Hub\Device\Watch\WatchResponse;

class DeviceHubServer
{
    private Whitelist $whitelist;
    private ConnectionRegistry $connections;
    private DeviceAuthorizer $authorizer;
    private DeviceIdentityExtractor $identityExtractor;
    private WatchProtocolRegistry $watchProtocols;
    private HubMqttBridge $mqtt;
    private ?PendingDownlinkQueue $downlinkQueue;
    private ?DashboardStoreContract $dashboardStore;
    private int $downlinkQueueTtlSeconds;
    private ?LocationTelemetryEnricherContract $locationTelemetryEnricher;

    public function __construct(
        Whitelist $whitelist,
        HubMqttBridge $mqtt,
        ?CommercialModelResolver $commercialModelResolver = null,
        ?DeviceIdentityExtractor $identityExtractor = null,
        ?DeviceAuthorizer $authorizer = null,
        ?ConnectionRegistry $connections = null,
        ?DeviceEventDecoder $eventDecoder = null,
        ?PendingDownlinkQueue $downlinkQueue = null,
        ?DashboardStoreContract $dashboardStore = null,
        int $downlinkQueueTtlSeconds = 300,
        ?LocationTelemetryEnricherContract $locationTelemetryEnricher = null,
    ) {
        $this->whitelist = $whitelist;
        $this->connections = $connections ?? new ConnectionRegistry();
        $this->authorizer = $authorizer ?? new DeviceAuthorizer($whitelist, $commercialModelResolver);
        $this->mqtt = $mqtt;
        $this->dashboardStore = $dashboardStore;
        $adapters = new AdapterRegistry();
        $this->identityExtractor = $identityExtractor ?? new DeviceIdentityExtractor($adapters);
        $this->watchProtocols = new WatchProtocolRegistry(
            $adapters,
            $eventDecoder ?? new DeviceEventDecoder(),
            fn (DeviceSession $session): array => $this->wonlexState($session)
        );
        $this->downlinkQueue = $downlinkQueue;
        $this->downlinkQueueTtlSeconds = max(1, $downlinkQueueTtlSeconds);
        $this->locationTelemetryEnricher = $locationTelemetryEnricher;
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

        $licenseId = $this->currentLicenseId($session->imei, $session->licenseId);
        $company = $this->currentCompany($session->imei, $session->company);
        $commercialName = $this->currentCommercialName($session->imei, $session->commercialName);
        $this->publishStatus($session->imei, $session->supplier, $session->model, 'offline', $session->deviceType, $licenseId, $company, $commercialName);
        $this->publishEvent($session->imei, $session->supplier, $session->model, 'device.disconnected', $session->deviceType, $licenseId, $company, $commercialName);
        $this->dashboardStore?->deviceOffline($session->imei);
        Logger::channel('hub')->info("Device offline IMEI={$session->imei}");
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        Logger::channel('hub')->error("Connection error id={$conn->resourceId}: {$e->getMessage()}");
        $conn->close();
    }

    public function sendDownlink(string $imei, string $bytes): bool
    {
        $conn = $this->connections->connectionFor($imei);
        if (!$conn instanceof ConnectionInterface) {
            return false;
        }

        $conn->send($bytes);
        $session = $this->connections->get($conn);
        $licenseId = $this->currentLicenseId($imei, $session?->licenseId ?? '0');
        $company = $this->currentCompany($imei, $session?->company ?? 'null');
        $commercialName = $this->currentCommercialName($imei, $session?->commercialName ?? '');
        try {
            $command = $this->commandMetadata($bytes, $session?->protocol);
            $this->mqtt->publishEvent($imei, RawPayload::event(
                $imei,
                $session?->supplier ?? '',
                $session?->model ?? '',
                'device.downlink.sent',
                null,
                $command,
                $commercialName
            ), $session?->deviceType ?? 'watch', $licenseId, $company);
            $this->recordDownlinkEvent(
                $imei,
                $session?->supplier ?? '',
                $session?->model ?? '',
                'device.downlink.sent',
                $bytes,
                $session?->deviceType ?? 'watch',
                $licenseId,
                $command,
                $commercialName
            );
            if ($session !== null) {
                $this->mqtt->publishRaw(
                    $imei,
                    RawPayload::raw($imei, $session->supplier, $session->model, $session->transport, $session->protocol, $bytes, 'downlink', (string)$conn->resourceId, $commercialName),
                    $session->deviceType,
                    $licenseId,
                    $company,
                );
            }
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }

        return true;
    }

    /**
     * @return 'sent'|'queued'|'dropped'
     */
    public function submitDownlink(string $imei, string $bytes, ?array $context = null): string
    {
        if ($this->sendDownlink($imei, $bytes)) {
            return 'sent';
        }

        return $this->queueDownlink($imei, $bytes, $context) ? 'queued' : 'dropped';
    }

    public function reportDownlinkDropped(string $imei, string $reason, ?string $bytes = null): void
    {
        $error = $this->errorPayload($reason);
        $metadata = $this->authorizer->metadataFor($imei);
        try {
            $command = $bytes !== null ? $this->commandMetadata($bytes) : null;
            $this->mqtt->publishEvent($imei, RawPayload::event(
                $imei,
                $metadata['supplier'],
                $metadata['model'],
                'device.downlink.dropped',
                $error,
                $command,
                (string)($metadata['commercialName'] ?? '')
            ), $metadata['deviceType'], $metadata['licenseId'], $metadata['company']);
            $this->recordEvent(
                $imei,
                $metadata['supplier'],
                $metadata['model'],
                'device.downlink.dropped',
                $command,
                $metadata['deviceType'],
                $metadata['licenseId'],
                (string)($metadata['commercialName'] ?? '')
            );
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }

    public function queueDownlink(string $imei, string $bytes, ?array $context = null): bool
    {
        if ($this->downlinkQueue === null) {
            $this->reportDownlinkDropped($imei, 'device_offline', $bytes);
            return false;
        }

        $metadata = $this->authorizer->metadataFor($imei);
        $command = array_merge($this->commandMetadata($bytes) ?? [], $context ?? []);
        $commercialName = (string)($metadata['commercialName'] ?? '');

        try {
            $this->downlinkQueue->enqueue($imei, $bytes, $command, $this->downlinkQueueTtlSeconds);
            $this->mqtt->publishEvent($imei, RawPayload::event(
                $imei,
                $metadata['supplier'],
                $metadata['model'],
                'device.downlink.queued',
                null,
                $command,
                $commercialName
            ), $metadata['deviceType'], $metadata['licenseId'], $metadata['company']);
            $this->recordEvent($imei, $metadata['supplier'], $metadata['model'], 'device.downlink.queued', $command, $metadata['deviceType'], $metadata['licenseId'], $commercialName);
            return true;
        } catch (\Throwable $e) {
            Logger::channel('hub')->error("Failed to queue downlink for IMEI={$imei}: {$e->getMessage()}");
            $this->reportDownlinkDropped($imei, 'queue_unavailable', $bytes);
            return false;
        }
    }

    public function isOnline(string $imei): bool
    {
        return $this->connections->isOnline($imei);
    }

    public function expireIdleConnections(int $idleSeconds): void
    {
        foreach ($this->connections->expireIdleConnections($idleSeconds) as $session) {
            $licenseId = $this->currentLicenseId($session->imei, $session->licenseId);
            $company = $this->currentCompany($session->imei, $session->company);
            $commercialName = $this->currentCommercialName($session->imei, $session->commercialName);
            $this->publishStatus($session->imei, $session->supplier, $session->model, 'offline', $session->deviceType, $licenseId, $company, $commercialName);
            $this->publishEvent($session->imei, $session->supplier, $session->model, 'device.disconnected', $session->deviceType, $licenseId, $company, $commercialName);
            $this->dashboardStore?->deviceOffline($session->imei);
            Logger::channel('hub')->warning("Device offline by idle timeout IMEI={$session->imei} idle_seconds={$idleSeconds}");
        }
    }

    private function authenticate(ConnectionInterface $conn, string $raw, DeviceSession $session): void
    {
        $identity = $this->identityExtractor->identify($raw, $session->identityContext());
        if ($identity === null) {
            if (!$session->unidentifiedWarningLogged) {
                $session->unidentifiedWarningLogged = true;
                // A origem vai junto de propósito: sem ela, um varredor de portas e um
                // dispositivo cujo protocolo não estamos a saber ler dão exactamente a mesma
                // linha, e é o segundo que é preciso ver.
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
        $this->dashboardStore?->deviceSeen($identity->imei, [
            'supplier' => $session->supplier,
            'model' => $session->model,
            'commercialName' => $session->commercialName,
            'deviceType' => $session->deviceType,
            'licenseId' => $this->currentLicenseId($session->imei, $session->licenseId),
            'company' => $this->currentCompany($session->imei, $session->company),
            'protocol' => $identity->protocol,
            'transport' => $session->transport,
            'online' => '1',
            'lastConnectionId' => (string)$conn->resourceId,
        ]);

        $licenseId = $this->currentLicenseId($session->imei, $session->licenseId);
        $company = $this->currentCompany($session->imei, $session->company);
        $commercialName = $this->currentCommercialName($session->imei, $session->commercialName);
        $this->publishStatus($identity->imei, $session->supplier, $session->model, 'online', $session->deviceType, $licenseId, $company, $commercialName);
        $this->publishEvent($identity->imei, $session->supplier, $session->model, 'device.connected', $session->deviceType, $licenseId, $company, $commercialName);

        $this->handleAuthenticatedMessage($conn, $session, $raw, (string)$conn->resourceId);
        $this->flushPendingDownlinks($session);

        Logger::channel('hub')->info("Device online IMEI={$identity->imei} protocol={$identity->protocol}");
    }

    private function handleAuthenticatedMessage(ConnectionInterface $conn, DeviceSession $session, string $raw, string $connectionId): void
    {
        try {
            $this->mqtt->publishRaw(
                $session->imei,
                RawPayload::raw($session->imei, $session->supplier, $session->model, $session->transport, $session->protocol, $raw, 'uplink', $connectionId, $session->commercialName),
                $session->deviceType,
                $this->currentLicenseId($session->imei, $session->licenseId),
                $this->currentCompany($session->imei, $session->company),
            );
            $this->recordRaw($session, $raw, $connectionId);
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $session->imei, $e);
        }

        $protocol = $this->watchProtocols->get($session->protocol);
        if ($protocol === null) {
            return;
        }

        $message = $protocol->handleIncoming($session, $raw);
        if (!($message instanceof WatchMessage)) {
            return;
        }

        $replyAccepted = null;
        if ($session->protocol === 'four-p-touch' && ($message->decoded['type'] ?? null) === 'TAKEPILLS') {
            $configAck = (string)($message->decoded['data']['configAck'] ?? '');
            $replyAccepted = match ($configAck) {
                '1' => true,
                '0' => false,
                default => null,
            };
        }
        $this->dashboardStore?->markCommandReply(
            $session->imei,
            (string)($message->decoded['type'] ?? ''),
            $message->decoded['ident'] ?? null,
            (string)($message->decoded['ref'] ?? ''),
            $replyAccepted,
        );

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
                $this->publishTelemetryEvent($session, $event);
            }
        }

        foreach ($message->responses as $response) {
            $this->sendWatchResponse($session, $response, $conn, $connectionId);
        }
    }

    private function publishTelemetryEvent(DeviceSession $session, array $event): bool
    {
        try {
            $licenseId = $this->currentLicenseId($session->imei, $session->licenseId);
            $company = $this->currentCompany($session->imei, $session->company);
            $this->mqtt->publishTelemetry($session->imei, $event, $session->deviceType, $licenseId, $company);
            // O heartbeat continua a sair no MQTT, para quem o subscreve, mas não entra no
            // histórico do dashboard: a lista de cada dispositivo guarda cem eventos, e um
            // terço deles seriam keep-alives a repetir a bateria e os passos que já chegam
            // como eventos próprios no mesmo instante.
            if (($event['type'] ?? null) !== 'heartbeat') {
                $this->dashboardStore?->append($session->imei, 'telemetry', array_merge(
                    $event,
                    ['deviceType' => $session->deviceType, 'licenseId' => $licenseId]
                ));
            }
            return true;
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $session->imei, $e);
            return false;
        }
    }

    private function reject(ConnectionInterface $conn, DeviceIdentity $identity, string $reason): void
    {
        if ($reason === 'device_not_authorized') {
            try {
                $this->dashboardStore?->recordRejectedDevice(
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
            $this->mqtt->publishStatus($identity->imei, RawPayload::status($identity->imei, '', '', 'error', $error));
            $this->mqtt->publishEvent($identity->imei, RawPayload::event($identity->imei, '', '', 'device.rejected', $error));
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
                    && $this->dashboardStore !== null
                    && !$this->dashboardStore->isCurrentOperation($operationId)
                ) {
                    $this->dashboardStore->markCommand($session->imei, $operationId, [
                        'status' => 'superseded',
                        'error' => '',
                    ]);
                    $this->downlinkQueue->remove($downlink);
                    continue;
                }
                if (!$this->sendDownlink($session->imei, $downlink->bytes)) {
                    continue;
                }
                $nativeType = is_array($downlink->command) ? (string)($downlink->command['nativeType'] ?? '') : '';
                if ($operationId !== '') {
                    $this->dashboardStore?->markCommand($session->imei, $operationId, [
                        'status' => 'waiting',
                        'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                    ]);
                } elseif ($nativeType !== '') {
                    $this->dashboardStore?->markLatestCommand($session->imei, $nativeType, [
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

    /**
     * Chamado quando o cliente de um dispositivo muda, com o cliente que ele está a deixar.
     */
    public function clearRetainedStatus(string $company, int $licenseId, string $deviceType, string $imei): void
    {
        try {
            $this->mqtt->clearRetainedStatus($company, $licenseId, $deviceType, $imei);
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }

    private function publishStatus(string $imei, string $supplier, string $model, string $state, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null', string $commercialName = ''): void
    {
        try {
            $this->mqtt->publishStatus($imei, RawPayload::status($imei, $supplier, $model, $state, null, $commercialName), true, $deviceType, $licenseId, $company);
            if ($state === 'online') {
                $this->dashboardStore?->deviceSeen($imei, [
                    'supplier' => $supplier,
                    'model' => $model,
                    'commercialName' => $commercialName,
                    'deviceType' => $deviceType,
                    'licenseId' => $licenseId,
                    'company' => $company,
                    'online' => '1',
                ]);
            } elseif ($state === 'offline') {
                $this->dashboardStore?->deviceOffline($imei);
            }
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }

    private function publishEvent(string $imei, string $supplier, string $model, string $type, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null', string $commercialName = ''): void
    {
        try {
            $this->mqtt->publishEvent($imei, RawPayload::event($imei, $supplier, $model, $type, null, null, $commercialName), $deviceType, $licenseId, $company);
            $this->recordEvent($imei, $supplier, $model, $type, null, $deviceType, $licenseId, $commercialName);
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }

    private function recordRaw(DeviceSession $session, string $raw, string $connectionId): void
    {
        $this->dashboardStore?->deviceSeen($session->imei, [
            'supplier' => $session->supplier,
            'model' => $session->model,
            'commercialName' => $session->commercialName,
            'deviceType' => $session->deviceType,
            'licenseId' => $this->currentLicenseId($session->imei, $session->licenseId),
            'company' => $this->currentCompany($session->imei, $session->company),
            'protocol' => $session->protocol,
            'transport' => $session->transport,
            'online' => '1',
            'lastConnectionId' => $connectionId,
        ]);
        $this->dashboardStore?->append($session->imei, 'raw', RawPayload::raw(
            $session->imei,
            $session->supplier,
            $session->model,
            $session->transport,
            $session->protocol,
            $raw,
            'uplink',
            $connectionId,
            $session->commercialName
        ));
    }

    private function recordEvent(
        string $imei,
        string $supplier,
        string $model,
        string $type,
        ?array $command = null,
        string $deviceType = 'watch',
        int $licenseId = 0,
        string $commercialName = ''
    ): void {
        $this->dashboardStore?->append($imei, 'events', array_merge(
            RawPayload::event($imei, $supplier, $model, $type, null, $command, $commercialName),
            ['deviceType' => $deviceType, 'licenseId' => $licenseId]
        ));
    }

    private function recordDownlinkEvent(
        string $imei,
        string $supplier,
        string $model,
        string $type,
        string $bytes,
        string $deviceType = 'watch',
        int $licenseId = 0,
        ?array $command = null,
        string $commercialName = ''
    ): void {
        $this->recordEvent($imei, $supplier, $model, $type, $command ?? $this->commandMetadata($bytes), $deviceType, $licenseId, $commercialName);
    }

    // A leitura é da whitelist e não da sessão: reassociar um dispositivo tem de valer já
    // numa ligação viva, e a sessão guardou os valores do login.
    private function currentLicenseId(string $imei, int $fallback = 0): int
    {
        $licenseId = $this->whitelist->getMetadata($imei)?->licenseId ?? 0;

        // O 0 quer dizer "sem atribuição", e por isso um valor ausente recorre ao anterior
        // em vez de restringir o dispositivo à licença 0 em silêncio.
        return $licenseId !== 0 ? $licenseId : $fallback;
    }

    private function currentCompany(string $imei, string $fallback = 'null'): string
    {
        $company = $this->whitelist->getMetadata($imei)?->company ?? '';
        return $company !== '' ? $company : $fallback;
    }

    private function currentCommercialName(string $imei, string $fallback = ''): string
    {
        $metadata = $this->authorizer->metadataFor($imei);
        $commercialName = (string)($metadata['commercialName'] ?? '');
        return $commercialName !== '' ? $commercialName : $fallback;
    }

    private function wonlexState(DeviceSession $session): array
    {
        $state = [
            'configurations' => $this->dashboardStore?->desiredConfigurations($session->imei) ?? [],
        ];
        foreach ($this->dashboardStore?->recent($session->imei, 'telemetry') ?? [] as $event) {
            $type = (string)($event['type'] ?? '');
            if ($type === 'sleep' && !isset($state['sleep']) && is_array($event['data'] ?? null)) {
                $state['sleep'] = $event['data'];
            }
        }

        return $state;
    }

    private function commandMetadata(string $bytes, ?string $protocol = null): ?array
    {
        return $this->watchProtocols->commandMetadata($bytes, $protocol);
    }

    private function sendWatchResponse(DeviceSession $session, WatchResponse $response, ConnectionInterface $conn, string $connectionId): void
    {
        $conn->send($response->bytes);
        if ($response->publishRaw !== true) {
            return;
        }

        try {
            $this->mqtt->publishRaw(
                $session->imei,
                RawPayload::raw($session->imei, $session->supplier, $session->model, $session->transport, $session->protocol, $response->bytes, 'downlink', $connectionId, $session->commercialName),
                $session->deviceType,
                $this->currentLicenseId($session->imei, $session->licenseId),
                $this->currentCompany($session->imei, $session->company),
            );
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $session->imei, $e);
        }
    }

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
