<?php

namespace App\Hub;

use App\Log\Logger;
use App\Protocol\AdapterRegistry;
use App\Registry\Whitelist;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

class DeviceHubServer implements MessageComponentInterface
{
    private ConnectionRegistry $connections;
    private DeviceAuthorizer $authorizer;
    private DeviceIdentityExtractor $identityExtractor;
    private AdapterRegistry $adapters;
    private HubMqttBridge $mqtt;

    public function __construct(
        Whitelist $whitelist,
        HubMqttBridge $mqtt,
        ?DeviceIdentityExtractor $identityExtractor = null,
        ?DeviceAuthorizer $authorizer = null,
        ?ConnectionRegistry $connections = null,
    ) {
        $this->connections = $connections ?? new ConnectionRegistry();
        $this->authorizer = $authorizer ?? new DeviceAuthorizer($whitelist);
        $this->mqtt = $mqtt;
        $this->adapters = new AdapterRegistry();
        $this->identityExtractor = $identityExtractor ?? new DeviceIdentityExtractor($this->adapters);
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->connections->open($conn);

        Logger::channel('hub')->info("Connection open id={$conn->resourceId}");
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $rid = $from->resourceId;
        $raw = (string)$msg;
        $session = $this->connections->get($from) ?? $this->connections->open($from);

        if (!$session->authenticated) {
            $this->authenticate($from, $raw, $session);
            return;
        }

        try {
            $this->mqtt->publishRaw(
                $session->imei,
                RawPayload::raw($session->imei, $session->model, $session->transport, $session->protocol, $raw, 'uplink', (string)$rid)
            );
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $session->imei, $e);
        }

        $this->sendProtocolKeepaliveAck($from, $session, $raw);
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $session = $this->connections->close($conn);

        if ($session === null || !$session->authenticated) {
            Logger::channel('hub')->info("Connection closed id={$conn->resourceId}");
            return;
        }

        $this->publishStatus($session->imei, $session->model, 'offline');
        $this->publishEvent($session->imei, $session->model, 'device.disconnected');
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
            $this->mqtt->publishEvent($imei, RawPayload::event($imei, $session?->model ?? '', 'device.downlink.sent'));
            if ($session !== null) {
                $this->mqtt->publishRaw(
                    $imei,
                    RawPayload::raw($imei, $session->model, $session->transport, $session->protocol, $bytes, 'downlink', (string)$conn->resourceId)
                );
            }
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }

        return true;
    }

    public function reportDownlinkDropped(string $imei, string $reason): void
    {
        $error = $this->errorPayload($reason);
        try {
            $this->mqtt->publishEvent($imei, RawPayload::event($imei, '', 'device.downlink.dropped', $error));
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }

    public function isOnline(string $imei): bool
    {
        return $this->connections->isOnline($imei);
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

        $session = $this->connections->authenticate($conn, $identity, $authorization->model);

        $this->sendLoginAccepted($conn, $identity);
        $this->publishStatus($identity->imei, $session->model, 'online');
        $this->publishEvent($identity->imei, $session->model, 'device.connected');

        try {
            $this->mqtt->publishRaw(
                $identity->imei,
                RawPayload::raw($identity->imei, $session->model, $session->transport, $identity->protocol, $raw, 'uplink', (string)$conn->resourceId)
            );
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $identity->imei, $e);
        }

        $this->sendProtocolKeepaliveAck($conn, $session, $raw);

        Logger::channel('hub')->info("Device online IMEI={$identity->imei} protocol={$identity->protocol}");
    }

    private function reject(ConnectionInterface $conn, DeviceIdentity $identity, string $reason): void
    {
        $error = $this->errorPayload($reason);
        try {
            $this->mqtt->publishStatus($identity->imei, RawPayload::status($identity->imei, $identity->model, 'error', $error));
            $this->mqtt->publishEvent($identity->imei, RawPayload::event($identity->imei, $identity->model, 'device.rejected', $error));
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

    private function sendProtocolKeepaliveAck(ConnectionInterface $conn, DeviceSession $session, string $raw): void
    {
        if ($session->protocol !== 'four-p-touch') {
            return;
        }

        $adapter = $this->adapters->get($session->protocol);
        if ($adapter === null) {
            return;
        }

        $decoded = $adapter->decodeIncoming($raw);
        if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'LK') {
            return;
        }

        $conn->send($adapter->encodeOutgoing([
            'type' => 'LK',
            'imei' => $session->imei,
            'deviceId' => $decoded['ident'] ?? $session->imei,
            'manufacturer' => $decoded['data']['manufacturer'] ?? '3G',
        ]));
    }

    private function publishStatus(string $imei, string $model, string $state): void
    {
        try {
            $this->mqtt->publishStatus($imei, RawPayload::status($imei, $model, $state));
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }

    private function publishEvent(string $imei, string $model, string $type): void
    {
        try {
            $this->mqtt->publishEvent($imei, RawPayload::event($imei, $model, $type));
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }

    private function errorPayload(string $code): array
    {
        return [
            'code' => $code,
            'message' => match ($code) {
                'device_not_authorized' => 'Device is not authorized',
                'device_offline' => 'Device is offline',
                default => str_replace('_', ' ', ucfirst($code)),
            },
        ];
    }
}
