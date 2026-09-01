# Instruções do projeto

As instruções deste projeto vivem todas no [`CLAUDE.md`](CLAUDE.md). Este
ficheiro existe só para os agentes que procuram por `AGENTS.md` e não devia
guardar cópia nenhuma: teve uma, ficou para trás quando o servidor passou a ter
duas instâncias do hub, e durante semanas mandou trabalhar diretamente em
produção enquanto o `CLAUDE.md` já dizia o contrário.

Lê lá:

- **As duas instâncias** — o que separa a de desenvolvimento da de produção, e
  o que nunca pode ser tocado sem intenção.
- **O teste vem primeiro** — uma funcionalidade começa pelo teste que falha, e
  confirma-se que falha pela razão certa antes de se escrever o código.
- **Trabalho em paralelo** — vários agentes a alterar código correm cada um no
  seu worktree.
- **Fluxo de trabalho** — o trabalho vai primeiro à instância de dev, e só
  depois de confirmado ali é que se promove.
- **Verificações que valem a pena** — isolamento das chaves do Redis, o broker
  MQTT, e os sentinelas contra `NULL`.
- **Segurança operacional** — o que um pedido para analisar produção autoriza,
  e o que não autoriza.

A documentação técnica do hub está em [`docs/README.md`](docs/README.md).
