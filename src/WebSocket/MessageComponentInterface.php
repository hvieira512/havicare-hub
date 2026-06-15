<?php

namespace Hub\WebSocket;

interface MessageComponentInterface
{
    public function onOpen(ConnectionInterface $conn): void;

    public function onMessage(ConnectionInterface $from, string $msg): void;

    public function onClose(ConnectionInterface $conn): void;

    public function onError(ConnectionInterface $conn, \Exception $e): void;
}
