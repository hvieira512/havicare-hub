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
     * Existe por causa do aviso de quem se liga e fala sem se identificar. A porta do TCP
     * está aberta ao mundo, e portanto os varredores de portas batem-lhe: quarenta e quatro
     * avisos destes em dois dias. Sem a origem, um varredor e um dispositivo verdadeiro cujo
     * protocolo não estamos a saber ler são a mesma linha no registo -- e o segundo caso é o
     * que interessa, porque é um cliente com um aparelho que não funciona.
     */
    public function remoteAddress(): ?string;

    public function send(string $data): static;

    public function close(): static;
}
