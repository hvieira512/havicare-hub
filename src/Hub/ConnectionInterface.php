<?php

namespace Hub;

interface ConnectionInterface
{
    /**
     * Every caller already reads this -- the registry, the hub server and the
     * session all key connections by it -- so the interface should say so.
     * Without it, an implementation missing the property fails only at runtime.
     */
    public int $resourceId { get; }

    public function send($data): static;

    public function close(): static;
}
