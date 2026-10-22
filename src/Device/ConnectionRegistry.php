<?php

declare(strict_types=1);

namespace Hub\Device;

class ConnectionRegistry
{
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var array<int, DeviceSession> */
    private array $sessions = [];
    /** @var array<string, ConnectionInterface> */
    private array $deviceMap = [];
    /** @var array<int, int> */
    private array $lastActivityAt = [];

    /** @param (\Closure(): int)|null $clock */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    public function open(ConnectionInterface $connection): DeviceSession
    {
        $session = new DeviceSession($connection);

        $this->sessions[$connection->resourceId] = $session;
        $this->lastActivityAt[$connection->resourceId] = ($this->clock)();

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

        // Um relógio celular reconecta num socket novo antes de o antigo fechar. Fecha-se já a
        // ligação órfã do IMEI: expirá-la publicaria `device.disconnected` de um aparelho online.
        $previous = $this->deviceMap[$identity->imei] ?? null;
        if ($previous !== null && $previous !== $connection) {
            $this->close($previous);
            $previous->close();
        }

        $this->sessions[$connection->resourceId] = $session;
        $this->deviceMap[$identity->imei] = $connection;
        $this->lastActivityAt[$connection->resourceId] = ($this->clock)();

        return $session;
    }

    public function touch(ConnectionInterface $connection): void
    {
        if (!isset($this->sessions[$connection->resourceId])) {
            return;
        }

        $this->lastActivityAt[$connection->resourceId] = ($this->clock)();
    }

    public function close(ConnectionInterface $connection): ?DeviceSession
    {
        $session = $this->sessions[$connection->resourceId] ?? null;
        unset($this->sessions[$connection->resourceId]);
        unset($this->lastActivityAt[$connection->resourceId]);

        if ($session !== null && $session->imei !== '' && ($this->deviceMap[$session->imei] ?? null) === $connection) {
            unset($this->deviceMap[$session->imei]);
        }

        return $session;
    }

    public function connectionFor(string $imei): ?ConnectionInterface
    {
        return $this->deviceMap[$imei] ?? null;
    }

    /**
     * Fecha as ligações cujo silêncio o `$expired` dá por excessivo.
     *
     * @param \Closure(DeviceSession, int): bool $expired recebe a sessão e os segundos calada
     * @return array<int, DeviceSession>
     */
    public function expireIdleConnections(\Closure $expired): array
    {
        $now = ($this->clock)();
        $closed = [];

        foreach ($this->sessions as $resourceId => $session) {
            if (!$expired($session, $now - ($this->lastActivityAt[$resourceId] ?? $now))) {
                continue;
            }

            $session = $this->close($session->connection) ?? $session;
            $session->connection->close();
            if ($session->authenticated) {
                $closed[] = $session;
            }
        }

        return $closed;
    }
}
