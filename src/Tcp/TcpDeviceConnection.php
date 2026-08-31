<?php

namespace Hub\Tcp;

use Hub\ConnectionInterface;
use React\Socket\ConnectionInterface as ReactConnection;

class TcpDeviceConnection implements ConnectionInterface
{
    public int $resourceId;
    private ReactConnection $connection;

    public function __construct(ReactConnection $connection, int $resourceId)
    {
        $this->connection = $connection;
        $this->resourceId = $resourceId;
    }

    /** O React devolve isto como `tcp://1.2.3.4:5678`, ou `null` se a ligação já caiu. */
    public function remoteAddress(): ?string
    {
        return $this->connection->getRemoteAddress();
    }

    public function send(string $data): static
    {
        $this->connection->write($data);
        return $this;
    }

    public function close(): static
    {
        $this->connection->end();
        return $this;
    }
}
