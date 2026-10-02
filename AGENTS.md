# Instruções do projeto

As instruções deste projeto vivem todas no [`CLAUDE.md`](CLAUDE.md). Este
ficheiro existe só para os agentes que procuram por `AGENTS.md`, e não guarda
cópia nenhuma: uma cópia fica para trás sem ninguém reparar.

Lê lá:

- **As duas instâncias** — o que separa a de desenvolvimento da de produção, e
  o que nunca pode ser tocado sem intenção.
- **Nomes no contrato MQTT** — o `type` em snake_case, os campos do `data` em
  camelCase, a unidade dentro do nome e os valores em enumerações inglesas.
- **Comentários** — só quando necessários, em português, sucintos e diretos, e
  nunca sobre o que estava antes. Identificadores em inglês.
- **O teste vem primeiro** — uma funcionalidade começa pelo teste que falha, e
  confirma-se que falha pela razão certa antes de se escrever o código.
- **A verificação é o `composer test`** — são oito coisas, e não há CI no
  GitHub a apanhar o que escapar. Corre antes de cada push, e não depois.
- **Uma migração nova aplica-se logo** — na mesma alteração em que se escreve,
  senão o hub local fica em ciclo de crash.
- **Trabalho em paralelo** — vários agentes a alterar código correm cada um no
  seu worktree.
- **Fluxo de trabalho** — o trabalho vai primeiro à instância de dev, e só
  depois de confirmado ali é que se promove.
- **Verificações que valem a pena** — isolamento das chaves do Redis, o broker
  MQTT, e os sentinelas contra `NULL`.
- **Segurança operacional** — o que um pedido para analisar produção autoriza,
  e o que não autoriza.

A documentação técnica do hub está em [`docs/README.md`](docs/README.md).
