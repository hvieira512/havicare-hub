<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\ConnectionInterface;

/**
 * Uma ligação que não liga a lado nenhum, para montar sessões nos testes do dispensador.
 *
 * Escrita à mão e não com `createStub`: o `resourceId` da interface é uma propriedade com
 * hook, e o gerador de duplos do PHPUnit não a sabe implementar.
 */
final class PillFakeConnection implements ConnectionInterface
{
    public int $resourceId = 1;

    public function remoteAddress(): ?string
    {
        return null;
    }

    public function send(string $data): static
    {
        return $this;
    }

    public function close(): static
    {
        return $this;
    }
}
