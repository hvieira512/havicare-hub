<?php

namespace Hub\Device;

interface ConnectionInterface
{
    /**
     * Toda a gente já lê isto -- o registo, o servidor do hub e a sessão indexam as ligações
     * por ele --, e por isso a interface tem de o dizer: sem ela, uma implementação a que
     * falte a propriedade só falha em execução.
     */
    public int $resourceId { get; }

    /**
     * De onde veio esta ligação, quando se sabe.
     *
     * Existe por causa do aviso de quem se liga e fala sem se identificar: sem a origem, um
     * varredor de portas e um dispositivo verdadeiro cujo protocolo não sabemos ler são a
     * mesma linha no registo, e é o segundo caso que interessa.
     */
    public function remoteAddress(): ?string;

    public function send(string $data): static;

    public function close(): static;
}
