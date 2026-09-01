# Documentação técnica

Documentação de desenvolvimento do Havicare Hub. A descrição funcional da
plataforma está no [`README.md`](../README.md) na raiz do repositório.

Cada capítulo descreve o âmbito da camada, a sua implementação e os ficheiros de
`src/` correspondentes. As questões de arquitetura em aberto estão reunidas nas
[notas de arquitetura](99-notas-de-arquitetura.md), separadas da descrição do
sistema implementado.

A documentação técnica vive **inteiramente** nestes capítulos. Não há documentos
temáticos avulsos: o que for preciso acrescentar entra no capítulo que cobre o
assunto, para não existirem duas versões da mesma matéria a discordar uma da
outra.

---

## Percursos de leitura

**Introdução ao sistema.** [Visão geral](01-visao-geral.md), seguida da camada
de ingestão relevante — [relógios](02-ingestao-tcp-relogios.md),
[chamada de enfermagem](03-ingestao-mqtt-ncs.md),
[radar](04-ingestao-mqtt-radar.md) ou [gateways e BLE](05-gateways-ble.md) — e
da [normalização](06-normalizacao.md).

**Integração por MQTT.** [Contrato MQTT](08-contrato-mqtt.md) para a estrutura
de tópicos e as garantias de entrega, [normalização](06-normalizacao.md) para o
formato de cada medição e [multi-inquilino](07-multi-inquilino.md) para a
composição dos dois primeiros segmentos do tópico. O
[sensor de fralda](17-sensor-de-fralda.md) constitui a especificação completa de
uma ingestão, do anúncio ao contrato público, e serve de referência para as
restantes.

**Integração pela API REST.** [Referência da API](09-api.md) para rotas,
autenticação e modelo de erros, e
[configuração de dispositivos](10-configuracao-de-dispositivos.md) para a
alteração de definições e o pedido de medições. A especificação em vigor está
disponível em `/api/docs`.

**Desenvolvimento.** [Visão geral](01-visao-geral.md) e, em seguida, a camada a
alterar. A arquitetura do frontend está documentada em
[`src/Dashboard/README.md`](../src/Dashboard/README.md). As regras de trabalho
com as instâncias de desenvolvimento e produção estão no
[`CLAUDE.md`](../CLAUDE.md).

---

## Índice

### Ingestão

| | |
|---|---|
| [01 — Visão geral](01-visao-geral.md) | Processo, event loop, sequência de arranque e ciclo de vida de uma mensagem |
| [02 — Ingestão TCP: relógios](02-ingestao-tcp-relogios.md) | Wonlex, Vivistar e 4P Touch: enquadramento, autenticação, telemetria e identidade |
| [03 — Ingestão MQTT: NCS](03-ingestao-mqtt-ncs.md) | Voerka W812: chamadas de ajuda e estado de ligação |
| [04 — Ingestão MQTT: radar](04-ingestao-mqtt-radar.md) | Qinglanst: broker dedicado e descodificação binária |
| [05 — Gateways e dispositivos BLE](05-gateways-ble.md) | MOKO MKGW3/MKGW4, pulseiras W6/W6B e sensor de fralda MONIT |

### Contratos públicos

| | |
|---|---|
| [06 — Normalização](06-normalizacao.md) | Envelope de telemetria e as vinte capacidades canónicas |
| [07 — Multi-inquilino](07-multi-inquilino.md) | Empresa e licença, whitelist e representação da ausência de dono |
| [08 — Contrato MQTT](08-contrato-mqtt.md) | Tópicos publicados, qualidade de serviço e retenção |
| [09 — API](09-api.md) | Rotas, autenticação, modelo de erros e especificação OpenAPI |

### Funcionalidades

| | |
|---|---|
| [10 — Configuração de dispositivos](10-configuracao-de-dispositivos.md) | Convergência entre estado desejado e estado reportado |
| [11 — Comandos e downlink](11-comandos-e-downlink.md) | Entrega de comandos a dispositivos intermitentes |
| [12 — Localização sem GPS](12-localizacao-sem-gps.md) | Mapa de rádio privado, cache e BeaconDB |
| [13 — Dashboard](13-dashboard.md) | Funcionalidades e relação com a API |
| [17 — Sensor de fralda](17-sensor-de-fralda.md) | Capacidades, derivação do estado, sensibilidade e contrato congelado |

### Infraestrutura

| | |
|---|---|
| [14 — Persistência](14-persistencia.md) | Esquema relacional e espaços de chaves no Redis |
| [15 — Operação](15-operacao.md) | Instâncias, publicação, registo e verificações |
| [16 — Testes](16-testes.md) | Suites de teste e integração contínua |
| [99 — Notas de arquitetura](99-notas-de-arquitetura.md) | Questões em aberto e decisões pendentes |

---

## Documentação dos fabricantes

Os manuais e as folhas de especificação originais estão em
[`fornecedores/`](fornecedores/) — Voerka, Wonlex, VIVISTAR e 4P Touch — e em
[`fornecedores/gateways/`](fornecedores/gateways/) para os equipamentos MOKO,
incluindo a análise de tramas em
[`MKGW4 payloads — hex vs JSON.md`](fornecedores/gateways/MKGW4%20payloads%20—%20hex%20vs%20JSON.md).
Constituem a fonte primária para o que não estiver coberto nesta documentação.
