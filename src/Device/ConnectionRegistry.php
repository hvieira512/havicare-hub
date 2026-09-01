<?php

namespace Hub\Device;

class ConnectionRegistry
{
    private \SplObjectStorage $connections;
    /** @var array<int, DeviceSession> */
    private array $sessions = [];
    /** @var array<string, ConnectionInterface> */
    private array $deviceMap = [];
    /** @var array<int, int> */
    private array $lastActivityAt = [];

    public function __construct()
    {
        $this->connections = new \SplObjectStorage();
    }

    public function open(ConnectionInterface $connection): DeviceSession
    {
        // Os dispositivos ligam-se por TCP e mais nada. O ramo alternativo rotulava a
        // ligação como `websocket`, transporte que nunca teve implementação nenhuma -- e o
        // rótulo não ficava aqui: viajava para o `debug.transport` do canal `raw` e para o
        // estado do dispositivo na dashboard.
        $session = new DeviceSession($connection, 'tcp');

        $this->connections->offsetSet($connection, $connection->resourceId);
        $this->sessions[$connection->resourceId] = $session;
        $this->lastActivityAt[$connection->resourceId] = time();

        return $session;
    }

    public function get(ConnectionInterface $connection): ?DeviceSession
    {
        return $this->sessions[$connection->resourceId] ?? null;
    }

    public function authenticate(
        ConnectionInterface $connection,
        DeviceIdentity $identity,
        string $supplier,
        string $model,
        string $commercialName = '',
        string $deviceType = 'watch',
        int $licenseId = 0,
        string $company = 'null',
    ): DeviceSession {
        $current = $this->get($connection) ?? $this->open($connection);
        $session = $current->authenticate($identity, $supplier, $model, $commercialName, $deviceType, $licenseId, $company);

        $this->sessions[$connection->resourceId] = $session;
        $this->deviceMap[$identity->imei] = $connection;
        $this->lastActivityAt[$connection->resourceId] = time();

        return $session;
    }

    public function touch(ConnectionInterface $connection): void
    {
        if (!isset($this->sessions[$connection->resourceId])) {
            return;
        }

        $this->lastActivityAt[$connection->resourceId] = time();
    }

    public function close(ConnectionInterface $connection): ?DeviceSession
    {
        $session = $this->sessions[$connection->resourceId] ?? null;
        unset($this->sessions[$connection->resourceId]);
        unset($this->lastActivityAt[$connection->resourceId]);

        if ($this->connections->offsetExists($connection)) {
            $this->connections->offsetUnset($connection);
        }

        if ($session !== null && $session->imei !== '' && ($this->deviceMap[$session->imei] ?? null) === $connection) {
            unset($this->deviceMap[$session->imei]);
        }

        return $session;
    }

    public function connectionFor(string $imei): ?ConnectionInterface
    {
        return $this->deviceMap[$imei] ?? null;
    }

    public function isOnline(string $imei): bool
    {
        return isset($this->deviceMap[$imei]);
    }

    /** @return array<int, DeviceSession> */
    public function expireIdleConnections(int $idleSeconds): array
    {
        $cutoff = time() - max(1, $idleSeconds);
        $expired = [];

        foreach ($this->sessions as $resourceId => $session) {
            if (($this->lastActivityAt[$resourceId] ?? time()) > $cutoff) {
                continue;
            }

            $session = $this->close($session->connection) ?? $session;
            $session->connection->close();
            if ($session->authenticated) {
                $expired[] = $session;
            }
        }

        return $expired;
    }
}
