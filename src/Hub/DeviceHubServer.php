<?php

namespace Hub;

use Hub\Log\Logger;
use Hub\Dashboard\DashboardStore;
use Hub\Protocol\AdapterRegistry;
use Hub\Registry\Whitelist;

class DeviceHubServer
{
    private ConnectionRegistry $connections;
    private DeviceAuthorizer $authorizer;
    private DeviceIdentityExtractor $identityExtractor;
    private AdapterRegistry $adapters;
    private DeviceEventDecoder $eventDecoder;
    private HubMqttBridge $mqtt;
    private ?PendingDownlinkQueue $downlinkQueue;
    private ?DashboardStore $dashboardStore;
    private int $downlinkQueueTtlSeconds;

    public function __construct(
        Whitelist $whitelist,
        HubMqttBridge $mqtt,
        ?DeviceIdentityExtractor $identityExtractor = null,
        ?DeviceAuthorizer $authorizer = null,
        ?ConnectionRegistry $connections = null,
        ?DeviceEventDecoder $eventDecoder = null,
        ?PendingDownlinkQueue $downlinkQueue = null,
        ?DashboardStore $dashboardStore = null,
        int $downlinkQueueTtlSeconds = 300,
    ) {
        $this->connections = $connections ?? new ConnectionRegistry();
        $this->authorizer = $authorizer ?? new DeviceAuthorizer($whitelist);
        $this->mqtt = $mqtt;
        $this->adapters = new AdapterRegistry();
        $this->identityExtractor = $identityExtractor ?? new DeviceIdentityExtractor($this->adapters);
        $this->eventDecoder = $eventDecoder ?? new DeviceEventDecoder();
        $this->downlinkQueue = $downlinkQueue;
        $this->dashboardStore = $dashboardStore;
        $this->downlinkQueueTtlSeconds = max(1, $downlinkQueueTtlSeconds);
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->connections->open($conn);

        Logger::channel('hub')->info("Connection open id={$conn->resourceId}");
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

        try {
            $this->mqtt->publishRaw(
                $session->imei,
                RawPayload::raw($session->imei, $session->supplier, $session->model, $session->transport, $session->protocol, $raw, 'uplink', (string)$rid),
                $session->deviceType,
                $session->licenseId
            );
            $this->recordRaw($session, $raw, (string)$rid);
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $session->imei, $e);
        }

        $this->publishDecodedEvents($session, $raw);
        $this->sendProtocolAck($from, $session, $raw);
        $this->sendWonlexUploadAck($from, $session, $raw);
        $this->sendWonlexHeartbeatReply($from, $session, $raw);
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $session = $this->connections->close($conn);

        if ($session === null || !$session->authenticated) {
            Logger::channel('hub')->info("Connection closed id={$conn->resourceId}");
            return;
        }

        $this->publishStatus($session->imei, $session->supplier, $session->model, 'offline', $session->deviceType, $session->licenseId);
        $this->publishEvent($session->imei, $session->supplier, $session->model, 'device.disconnected', $session->deviceType, $session->licenseId);
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
        try {
            $this->mqtt->publishEvent($imei, RawPayload::event(
                $imei,
                $session?->supplier ?? '',
                $session?->model ?? '',
                'device.downlink.sent',
                null,
                $this->commandMetadata($bytes, $session?->protocol)
            ), $session?->deviceType ?? 'watch', $session?->licenseId ?? '0');
            $this->recordDownlinkEvent(
                $imei,
                $session?->supplier ?? '',
                $session?->model ?? '',
                'device.downlink.sent',
                $bytes,
                $session?->deviceType ?? 'watch',
                $session?->licenseId ?? '0'
            );
            if ($session !== null) {
                $this->mqtt->publishRaw(
                    $imei,
                    RawPayload::raw($imei, $session->supplier, $session->model, $session->transport, $session->protocol, $bytes, 'downlink', (string)$conn->resourceId),
                    $session->deviceType,
                    $session->licenseId
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
    public function submitDownlink(string $imei, string $bytes): string
    {
        if ($this->sendDownlink($imei, $bytes)) {
            return 'sent';
        }

        return $this->queueDownlink($imei, $bytes) ? 'queued' : 'dropped';
    }

    public function reportDownlinkDropped(string $imei, string $reason, ?string $bytes = null): void
    {
        $error = $this->errorPayload($reason);
        $metadata = $this->authorizer->metadataFor($imei);
        try {
            $this->mqtt->publishEvent($imei, RawPayload::event(
                $imei,
                $metadata['supplier'],
                $metadata['model'],
                'device.downlink.dropped',
                $error,
                $bytes !== null ? $this->commandMetadata($bytes) : null
            ), $metadata['deviceType'], $metadata['licenseId']);
            $this->recordEvent(
                $imei,
                $metadata['supplier'],
                $metadata['model'],
                'device.downlink.dropped',
                $bytes !== null ? $this->commandMetadata($bytes) : null,
                $metadata['deviceType'],
                $metadata['licenseId']
            );
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }

    public function queueDownlink(string $imei, string $bytes): bool
    {
        if ($this->downlinkQueue === null) {
            $this->reportDownlinkDropped($imei, 'device_offline', $bytes);
            return false;
        }

        $metadata = $this->authorizer->metadataFor($imei);
        $command = $this->commandMetadata($bytes);

        try {
            $this->downlinkQueue->enqueue($imei, $bytes, $command, $this->downlinkQueueTtlSeconds);
            $this->mqtt->publishEvent($imei, RawPayload::event(
                $imei,
                $metadata['supplier'],
                $metadata['model'],
                'device.downlink.queued',
                null,
                $command
            ), $metadata['deviceType'], $metadata['licenseId']);
            $this->recordEvent($imei, $metadata['supplier'], $metadata['model'], 'device.downlink.queued', $command, $metadata['deviceType'], $metadata['licenseId']);
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
            $this->publishStatus($session->imei, $session->supplier, $session->model, 'offline', $session->deviceType, $session->licenseId);
            $this->publishEvent($session->imei, $session->supplier, $session->model, 'device.disconnected', $session->deviceType, $session->licenseId);
            $this->dashboardStore?->deviceOffline($session->imei);
            Logger::channel('hub')->warning("Device offline by idle timeout IMEI={$session->imei} idle_seconds={$idleSeconds}");
        }
    }

    private function authenticate(ConnectionInterface $conn, string $raw, DeviceSession $session): void
    {
        $identity = $this->identityExtractor->identify($raw, $session->identityContext());
        if ($identity === null) {
            Logger::channel('hub')->warning("Connection id={$conn->resourceId} sent data before identifiable login");
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
            $authorization->deviceType,
            $authorization->licenseId
        );
        $this->dashboardStore?->deviceSeen($identity->imei, [
            'supplier' => $session->supplier,
            'model' => $session->model,
            'deviceType' => $session->deviceType,
            'licenseId' => $session->licenseId,
            'protocol' => $identity->protocol,
            'transport' => $session->transport,
            'online' => '1',
            'lastConnectionId' => (string)$conn->resourceId,
        ]);

        $this->sendLoginAccepted($conn, $identity);
        $this->publishStatus($identity->imei, $session->supplier, $session->model, 'online', $session->deviceType, $session->licenseId);
        $this->publishEvent($identity->imei, $session->supplier, $session->model, 'device.connected', $session->deviceType, $session->licenseId);

        try {
            $this->mqtt->publishRaw(
                $identity->imei,
                RawPayload::raw($identity->imei, $session->supplier, $session->model, $session->transport, $identity->protocol, $raw, 'uplink', (string)$conn->resourceId),
                $session->deviceType,
                $session->licenseId
            );
            $this->recordRaw($session, $raw, (string)$conn->resourceId);
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $identity->imei, $e);
        }

        $this->publishDecodedEvents($session, $raw);
        $this->sendProtocolAck($conn, $session, $raw);
        $this->sendWonlexUploadAck($conn, $session, $raw);
        $this->flushPendingDownlinks($session);

        Logger::channel('hub')->info("Device online IMEI={$identity->imei} protocol={$identity->protocol}");
    }

    private function reject(ConnectionInterface $conn, DeviceIdentity $identity, string $reason): void
    {
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

    private function sendLoginAccepted(ConnectionInterface $conn, DeviceIdentity $identity): void
    {
        $adapter = $this->adapters->get($identity->protocol);
        if ($adapter === null) {
            return;
        }

        $timestamp = (int)round(microtime(true) * 1000);
        if ($identity->protocol === 'wonlex-json') {
            $conn->send($adapter->encodeOutgoing([
                'type' => 'login',
                'ident' => $identity->ident,
                'ref' => 's:reply',
                'imei' => $identity->imei,
                'data' => [
                    'type' => 'login',
                    'imei' => $identity->imei,
                    'bindStatus' => 1,
                    'timestamp' => $timestamp,
                ],
                'timestamp' => $timestamp,
            ]));
            return;
        }

        if ($identity->protocol === 'vivistar-iw') {
            $conn->send($adapter->encodeOutgoing(['type' => 'login_ok']));
        }

    }

    private function publishDecodedEvents(DeviceSession $session, string $raw): void
    {
        $adapter = $this->adapters->get($session->protocol);
        if ($adapter === null) {
            return;
        }

        $decoded = $adapter->decodeIncoming($raw, ['session' => $session->identityContext()]);
        if (!is_array($decoded)) {
            return;
        }

        $this->dashboardStore?->markCommandReply($session->imei, (string)($decoded['type'] ?? ''));

        foreach ($this->eventDecoder->decode($session, $decoded) as $event) {
            try {
                $payload = DeviceEventPayloadBuilder::decoded($session, $event);
                $this->mqtt->publishTelemetry($session->imei, $payload, $session->deviceType, $session->licenseId);
                $this->dashboardStore?->append($session->imei, 'telemetry', array_merge(
                    $payload,
                    ['deviceType' => $session->deviceType, 'licenseId' => $session->licenseId]
                ));
            } catch (\Throwable $e) {
                $this->mqtt->logPublishFailure('hub', $session->imei, $e);
            }
        }
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
                if (!$this->sendDownlink($session->imei, $downlink->bytes)) {
                    continue;
                }
                $nativeType = is_array($downlink->command) ? (string)($downlink->command['nativeType'] ?? '') : '';
                if ($nativeType !== '') {
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

    private function sendWonlexHeartbeatReply(ConnectionInterface $conn, DeviceSession $session, string $raw): void
    {
        if ($session->protocol !== 'wonlex-json') {
            return;
        }

        $adapter = $this->adapters->get($session->protocol);
        if ($adapter === null) {
            return;
        }

        $decoded = $adapter->decodeIncoming($raw);
        if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'heartbeat') {
            return;
        }

        $timestamp = (int)round(microtime(true) * 1000);
        $ident = (string)($decoded['ident'] ?? '');
        $conn->send($adapter->encodeOutgoing([
            'type' => 'heartbeat',
            'ident' => $ident !== '' ? $ident : random_int(100000, 999999),
            'ref' => 's:reply',
            'imei' => $session->imei,
            'data' => [
                'type' => 'heartbeat',
                'imei' => $session->imei,
                'deviceModel' => $session->model,
                'timestamp' => $timestamp,
            ],
            'timestamp' => $timestamp,
        ]));
    }

    private function sendWonlexUploadAck(ConnectionInterface $conn, DeviceSession $session, string $raw): void
    {
        if ($session->protocol !== 'wonlex-json') {
            return;
        }

        $adapter = $this->adapters->get($session->protocol);
        if ($adapter === null) {
            return;
        }

        $decoded = $adapter->decodeIncoming($raw);
        if (!is_array($decoded) || (string)($decoded['ref'] ?? '') !== 'w:update') {
            return;
        }

        $type = (string)($decoded['type'] ?? '');
        if ($type === '' || in_array($type, ['login', 'heartbeat'], true)) {
            return;
        }

        $timestamp = (int)round(microtime(true) * 1000);
        $ident = is_int($decoded['ident'] ?? null)
            ? $decoded['ident']
            : (int)($decoded['ident'] ?? random_int(100000, 999999));
        if ($ident <= 0) {
            $ident = random_int(100000, 999999);
        }

        $bytes = $adapter->encodeOutgoing([
            'type' => $type,
            'ident' => $ident,
            'ref' => 's:reply',
            'imei' => $session->imei,
            'data' => [
                'type' => $type,
                'imei' => $session->imei,
                'timestamp' => $timestamp,
            ],
            'timestamp' => $timestamp,
        ]);

        $conn->send($bytes);
        try {
            $this->mqtt->publishRaw(
                $session->imei,
                RawPayload::raw($session->imei, $session->supplier, $session->model, $session->transport, $session->protocol, $bytes, 'downlink', (string)$conn->resourceId)
            );
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $session->imei, $e);
        }
    }

    private function sendProtocolAck(ConnectionInterface $conn, DeviceSession $session, string $raw): void
    {
        if ($session->protocol !== 'four-p-touch') {
            return;
        }

        $adapter = $this->adapters->get($session->protocol);
        if ($adapter === null) {
            return;
        }

        $decoded = $adapter->decodeIncoming($raw);
        if (!is_array($decoded)) {
            return;
        }

        $type = (string)($decoded['type'] ?? '');
        $ackFields = $this->fourPTouchAckFields($type);
        if ($ackFields === null) {
            return;
        }

        $conn->send($adapter->encodeOutgoing([
            'type' => $type,
            'imei' => $session->imei,
            'deviceId' => $decoded['ident'] ?? $session->imei,
            'manufacturer' => $decoded['data']['manufacturer'] ?? '3G',
            'data' => ['fields' => $ackFields],
        ]));
    }

    /**
     * @return array<int, string>|null
     */
    private function fourPTouchAckFields(string $type): ?array
    {
        if ($type === 'LK' || $type === 'bphrt' || $type === 'btemp2' || $type === 'TKQ' || $type === 'TKQ2') {
            return [];
        }

        if (in_array($type, ['AL', 'AL_WCDMA', 'AL_LTE'], true)) {
            return [];
        }

        return match ($type) {
            'CONFIG', 'oxygen', 'WIFIINFOUP', 'TK' => ['1'],
            default => null,
        };
    }

    private function publishStatus(string $imei, string $supplier, string $model, string $state, string $deviceType = 'watch', string $licenseId = '0'): void
    {
        try {
            $this->mqtt->publishStatus($imei, RawPayload::status($imei, $supplier, $model, $state), true, $deviceType, $licenseId);
            if ($state === 'online') {
                $this->dashboardStore?->deviceSeen($imei, [
                    'supplier' => $supplier,
                    'model' => $model,
                    'deviceType' => $deviceType,
                    'licenseId' => $licenseId,
                    'online' => '1',
                ]);
            } elseif ($state === 'offline') {
                $this->dashboardStore?->deviceOffline($imei);
            }
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }

    private function publishEvent(string $imei, string $supplier, string $model, string $type, string $deviceType = 'watch', string $licenseId = '0'): void
    {
        try {
            $this->mqtt->publishEvent($imei, RawPayload::event($imei, $supplier, $model, $type), $deviceType, $licenseId);
            $this->recordEvent($imei, $supplier, $model, $type, null, $deviceType, $licenseId);
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }

    private function recordRaw(DeviceSession $session, string $raw, string $connectionId): void
    {
        $this->dashboardStore?->deviceSeen($session->imei, [
            'supplier' => $session->supplier,
            'model' => $session->model,
            'deviceType' => $session->deviceType,
            'licenseId' => $session->licenseId,
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
            $connectionId
        ));
    }

    private function recordEvent(
        string $imei,
        string $supplier,
        string $model,
        string $type,
        ?array $command = null,
        string $deviceType = 'watch',
        string $licenseId = '0'
    ): void
    {
        $this->dashboardStore?->append($imei, 'events', array_merge(
            RawPayload::event($imei, $supplier, $model, $type, null, $command),
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
        string $licenseId = '0'
    ): void
    {
        $this->recordEvent($imei, $supplier, $model, $type, $this->commandMetadata($bytes), $deviceType, $licenseId);
    }

    private function commandMetadata(string $bytes, ?string $protocol = null): ?array
    {
        $decoded = null;
        $resolvedProtocol = $protocol;
        if ($protocol !== null && $protocol !== '') {
            $adapter = $this->adapters->get($protocol);
            $decoded = $adapter?->decodeIncoming($bytes);
        }
        if (!is_array($decoded)) {
            $decoded = $this->adapters->decodeAny($bytes);
            $resolvedProtocol = is_array($decoded) ? (string)($decoded['_protocol'] ?? $resolvedProtocol) : $resolvedProtocol;
        }
        if (!is_array($decoded)) {
            return $this->outgoingCommandMetadata($bytes, $protocol);
        }

        $metadata = array_filter([
            'nativeType' => (string)($decoded['type'] ?? ''),
            'protocol' => $resolvedProtocol,
            'ident' => $decoded['ident'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return $metadata !== [] ? $metadata : null;
    }

    private function outgoingCommandMetadata(string $bytes, ?string $protocol = null): ?array
    {
        $message = trim($bytes);
        if (($protocol === null || $protocol === '' || $protocol === 'vivistar-iw')
            && preg_match('/^IW(BP[A-Z0-9]{2}),([^,#]+),([^,#]+)/', $message, $matches) === 1
        ) {
            return [
                'nativeType' => $matches[1],
                'protocol' => 'vivistar-iw',
                'ident' => $matches[3],
            ];
        }

        return null;
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
