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
            $this->mqtt->publishUplink(
                $session->imei,
                RawPayload::envelope($session->imei, $session->transport, $session->protocol, $raw, 'uplink', (string)$rid)
            );
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $session->imei, $e);
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $session = $this->connections->close($conn);

        if ($session === null || !$session->authenticated) {
            Logger::channel('hub')->info("Connection closed id={$conn->resourceId}");
            return;
        }

        $this->publishStatus($session->imei, 'offline', $session->transport, $session->protocol, (string)$conn->resourceId);
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
            $this->mqtt->publishStatus($imei, [
                'event' => ['type' => 'device.downlink.sent', 'id' => 'raw_' . bin2hex(random_bytes(8))],
                'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'device' => ['imei' => $imei],
                'transport' => $session?->transport ?? '',
                'protocol' => $session?->protocol ?? '',
            ], false);
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }

        return true;
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
        $this->publishStatus($identity->imei, 'online', $session->transport, $identity->protocol, (string)$conn->resourceId);

        try {
            $this->mqtt->publishUplink(
                $identity->imei,
                RawPayload::envelope($identity->imei, $session->transport, $identity->protocol, $raw, 'uplink', (string)$conn->resourceId)
            );
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $identity->imei, $e);
        }

        Logger::channel('hub')->info("Device online IMEI={$identity->imei} protocol={$identity->protocol}");
    }

    private function reject(ConnectionInterface $conn, DeviceIdentity $identity, string $reason): void
    {
        try {
            $this->mqtt->publishError($identity->imei, [
                'event' => ['type' => 'device.auth.rejected', 'id' => 'raw_' . bin2hex(random_bytes(8))],
                'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'device' => ['imei' => $identity->imei],
                'protocol' => $identity->protocol,
                'reason' => $reason,
            ]);
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

    private function publishStatus(string $imei, string $state, string $transport, string $protocol, string $connectionId): void
    {
        try {
            $this->mqtt->publishStatus($imei, [
                'event' => ['type' => 'device.status.changed', 'id' => 'raw_' . bin2hex(random_bytes(8))],
                'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'device' => ['imei' => $imei],
                'transport' => $transport,
                'protocol' => $protocol,
                'connectionId' => $connectionId,
                'data' => ['state' => $state],
            ]);
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }
}
