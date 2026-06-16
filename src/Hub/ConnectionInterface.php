<?php

namespace Hub;

interface ConnectionInterface
{
    public function send($data): static;

    public function close(): static;
}
