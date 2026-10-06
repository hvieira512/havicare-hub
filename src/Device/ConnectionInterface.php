<?php

declare(strict_types=1);

namespace Hub\Device;

interface ConnectionInterface
{
    /** O registo, o servidor e a sessão indexam as ligações por isto. */
    public int $resourceId { get; }

    /**
     * De onde veio a ligação, quando se sabe: separa no registo um varredor de portas de um
     * dispositivo cujo protocolo não sabemos ler.
     */
    public function remoteAddress(): ?string;

    public function send(string $data): static;

    public function close(): static;
}
