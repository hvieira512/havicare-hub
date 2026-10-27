<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Ingress\Tcp\ConnectionInterface;

/** Escrita à mão porque o `resourceId` é uma propriedade com hook que o `createStub` não sabe implementar. */
final class FakeConnection implements ConnectionInterface
{
    /** @var list<string> */
    public array $sent = [];
    public bool $closed = false;

    public function __construct(public int $resourceId = 1, private ?string $remoteAddress = null)
    {
    }

    public function remoteAddress(): ?string
    {
        return $this->remoteAddress;
    }

    public function send(string $data): static
    {
        $this->sent[] = $data;
        return $this;
    }

    public function close(): static
    {
        $this->closed = true;
        return $this;
    }
}
