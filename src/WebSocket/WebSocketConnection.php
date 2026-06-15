<?php

namespace Hub\WebSocket;

use Ratchet\RFC6455\Messaging\MessageBuffer;

class WebSocketConnection implements ConnectionInterface
{
    private \React\Socket\ConnectionInterface $connection;
    private ?MessageBuffer $msgBuffer = null;

    public function __construct(
        \React\Socket\ConnectionInterface $connection,
        public int $resourceId,
    ) {
        $this->connection = $connection;
    }

    public function setMessageBuffer(MessageBuffer $buffer): void
    {
        $this->msgBuffer = $buffer;
    }

    public function send($data): static
    {
        if ($this->msgBuffer !== null) {
            $this->msgBuffer->sendMessage((string)$data);
        }

        return $this;
    }

    public function close(): static
    {
        $this->connection->end();
        return $this;
    }
}
