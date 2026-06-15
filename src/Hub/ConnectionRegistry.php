<?php

namespace Hub;

use Hub\Tcp\TcpDeviceConnection;
use Hub\WebSocket\ConnectionInterface;

class ConnectionRegistry
{
    private \SplObjectStorage $connections;
    /** @var array<int, DeviceSession> */
    private array $sessions = [];
    /** @var array<string, ConnectionInterface> */
    private array $deviceMap = [];

    public function __construct()
    {
        $this->connections = new \SplObjectStorage();
    }

    public function open(ConnectionInterface $connection): DeviceSession
    {
        $transport = $connection instanceof TcpDeviceConnection ? 'tcp' : 'websocket';
        $session = new DeviceSession($connection, $transport);

        $this->connections->offsetSet($connection, $connection->resourceId);
        $this->sessions[$connection->resourceId] = $session;

        return $session;
    }

    public function get(ConnectionInterface $connection): ?DeviceSession
    {
        return $this->sessions[$connection->resourceId] ?? null;
    }

    public function authenticate(ConnectionInterface $connection, DeviceIdentity $identity, string $supplier, string $model): DeviceSession
    {
        $current = $this->get($connection) ?? $this->open($connection);
        $session = $current->authenticate($identity, $supplier, $model);

        $this->sessions[$connection->resourceId] = $session;
        $this->deviceMap[$identity->imei] = $connection;

        return $session;
    }

    public function close(ConnectionInterface $connection): ?DeviceSession
    {
        $session = $this->sessions[$connection->resourceId] ?? null;
        unset($this->sessions[$connection->resourceId]);

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
    public function allAuthenticatedSessions(): array
    {
        return array_values(array_filter(
            $this->sessions,
            static fn (DeviceSession $session): bool => $session->authenticated
        ));
    }
}
