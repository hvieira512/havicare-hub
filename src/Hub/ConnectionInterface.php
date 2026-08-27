<?php

namespace Hub;

interface ConnectionInterface
{
    /**
     * Toda a gente já lê isto -- o registo, o servidor do hub e a sessão indexam as ligações
     * por ele --, e por isso a interface tem de o dizer: sem ela, uma implementação a que
     * falte a propriedade só falha em execução.
     */
    public int $resourceId { get; }

    public function send(string $data): static;

    public function close(): static;
}
