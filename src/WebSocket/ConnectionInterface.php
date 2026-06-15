<?php

namespace Hub\WebSocket;

interface ConnectionInterface
{
    public function send($data): static;

    public function close(): static;
}
