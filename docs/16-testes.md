# 16 — Testes

## Âmbito

O que prende o comportamento do hub contra regressão: as quatro suites de teste
e o que cada uma cobre, as três ferramentas de análise, e o portão local que é o
`composer test`. Uma funcionalidade começa pelo teste que falha, e a regra está no
[`CLAUDE.md`](../CLAUDE.md); este capítulo descreve o que já existe para a
sustentar.

## As quatro suites

| Suite | Onde | Ficheiros | Precisa de |
|---|---|---|---|
| Unitários (PHP) | `tests/Unit/` | ~230 | nada |
| Integração (PHP) | `tests/Integration/` | ~60 | MySQL e Redis |
| Frontend (Node) | `tests/Frontend/` | ~140 | nada |
| Cenários (shell) | `tests/scenarios/` | 5 | a pilha Docker inteira, menos um |

```bash
composer test:unit          # ~6 s
composer test:integration   # precisa de base de dados
composer test:frontend
composer test:scenarios     # levanta mosquitto, redis, mysql e o hub
composer test               # o portão completo
```

O `composer test` é o portão completo: estilo, análise estática, lint do
frontend, as duas suites PHP, a do frontend e os cenários.

Tudo o que é PHP corre no contentor `hub`, que tem o 8.4 da produção: os alvos
passam pelo [`bin/in-hub`](../bin/in-hub), que levanta o contentor se for
preciso. Na máquina correm só o `composer`, o node e o Docker.

As duas suites PHP correm repartidas por vários processos, um ficheiro de teste
de cada vez ([`tests/run-parallel.sh`](../tests/run-parallel.sh)), e no fim somam
os testes e as asserções para se ver que o paralelo corre o mesmo que uma corrida
única. O número de processos vem do `TEST_WORKERS`; acima de oito o MySQL
serializa o DDL e a corrida fica mais lenta. Um erro que só apareça em paralelo
confirma-se com `composer test:unit:serial` ou `composer test:integration:serial`,
que correm a mesma suite num processo só.

Uma classe de teste é a unidade de repartição, por isso uma classe muito maior do
que as outras fixa o chão do tempo. A API dos dispositivos está por isso em três
classes — listagem, detalhe e configuração — que partilham o `DevicesApiFixture`.

## O que cada uma cobre

**Unitários** — lógica isolada: descodificadores de protocolo,
[normalizador de capacidades](06-normalizacao.md), construtor de comandos,
validação de pedidos, especificação OpenAPI e prefixo do Redis. Executam sem
dependências externas.

**Integração** — a [API](09-api.md) ao nível da rota, sobre base de dados real:
autenticação, [âmbito por inquilino](07-multi-inquilino.md), validação de
escrita, servidor HTTP e stream.

**Frontend** — os [módulos da dashboard](20-frontend-da-dashboard.md), com
`node --test`, num DOM do jsdom que o `--import` de
`tests/Frontend/support/browser-env.js` instala antes de cada ficheiro. Um dos testes renderiza
a página e verifica a existência no HTML de cada elemento referenciado pelo
JavaScript, impedindo a rutura silenciosa do contrato entre o PHP e o JS.

**Cenários** — o sistema inteiro, de ponta a ponta:

| Cenário | Prova |
|---|---|
| `backup_rotation` | A [rotação das cópias](18-backups.md) guarda o que tem de guardar ao fim de 400 dias — é a única parte do backup que apaga ficheiros |
| `hub_raw_mqtt_roundtrip` | Um [dispositivo TCP](02-ingestao-tcp-relogios.md) simulado chega ao [MQTT](08-contrato-mqtt.md), e um comando da API chega-lhe de volta |
| `hub_downlink_queue` | Um [comando para um aparelho offline](11-comandos-e-downlink.md) fica em fila e é entregue quando ele volta |
| `ncs_mqtt_ingress` | A [ingestão Voerka](03-ingestao-mqtt-ncs.md) |
| `location_beacondb_pipeline` | A [resolução de localização](12-localizacao-sem-gps.md), com um servidor falso |

O `backup_rotation` é a excepção à coluna «precisa de»: não levanta
infraestrutura nenhuma, porque a rotação decide pelo nome do ficheiro e nomes
bastam para a exercitar.

Cada um tem 240 segundos e deixa os seus registos em `tests/artifacts/`, com
retenção das 20 corridas mais recentes.

### Correm num projeto compose à parte

Os cenários precisam de um hub apontado ao mosquitto local e com a ingestão do
radar desligada, para nunca tocarem no broker de produção. Fazem-no num projeto
compose próprio — `havicare-scenarios`, com contentores, volume de base de dados
e porta próprios, declarados em `docker-compose.scenarios.yml`.

A separação não é cosmética. Enquanto os cenários recriavam o contentor de
desenvolvimento, quem corresse um ficava com o hub local ligado ao broker errado
até o recriar à mão — sem erro nenhum no log, porque o hub arranca perfeitamente
assim. A dashboard dos cenários responde na porta **8181**, e a de
desenvolvimento continua na 8081.

A pilha é desmontada no fim, tenham os cenários passado ou não. A política de
reinício do compose base é anulada — uma pilha de testes não se levanta sozinha
depois de a máquina reiniciar — e o `run-all.sh` faz `docker compose down` num
`trap`. O volume `scenario_mysql_data` é que fica: é o que evita migrar e semear
a base de dados de raiz a cada corrida.

## Ferramentas

| | Configuração |
|---|---|
| **PHPStan** | Nível 4, sobre `src/` e `bin/`. O nível 4 liga as regras de código morto e de condição impossível — é o que apanha uma propriedade não declarada ou um ramo que nunca corre |
| **PHPCS** | PSR-12, menos o limite de comprimento de linha |
| **ESLint** | Configuração plana, com estilo próprio. Corre com zero avisos tolerados, que é o que apanha um import órfão ao mover código |

Cada exclusão está justificada no respetivo ficheiro de configuração, com dados
quantitativos. Das regras de comparação sempre verdadeira, o
`notIdentical.alwaysTrue` **não** está silenciado globalmente — só em quatro
ficheiros, nomeados um a um; o `booleanAnd.rightAlwaysTrue` e o
`instanceof.alwaysTrue` estão, por assinalarem estilo defensivo e não defeito.

## Não há integração contínua

A verificação é local e corre inteira antes de cada push. O CI do GitHub foi
removido a 01/10/2026: o que dava de único era o **PHP 8.4** da produção, e esse
é agora o de todas as corridas, porque o PHP corre no contentor.

O que se perdeu com o CI, e vale a pena ter presente:

- **A instalação de raiz.** O CI fazia `composer install` e `npm ci` num disco
  vazio, e apanhava uma dependência em falta ou um teste que dependesse de
  estado local. Aqui o `vendor/` e o `node_modules/` já existem.
- **A exigência de base de dados.** A suite de integração ignora-se a si própria
  quando o MySQL não responde, e um verde por omissão não é verificação. Quem
  corre a suite é que tem de reparar nos testes ignorados.

## Motores de base de dados

O ambiente local e a produção executam o mesmo motor, MariaDB 11.8: a suite de
integração e os cenários correm contra a base de dados da produção.

## Testes de invariantes

Alguns testes não verificam comportamento, mas fixam decisões de projeto contra
regressão involuntária.

| Teste | Invariante |
|---|---|
| `Unit/Database/SeedWhitelistTest` | O seed não grava [sentinelas de memória](07-multi-inquilino.md) na base de dados e nunca produz licença sem empresa |
| `Unit/Runtime/RedisPrefixTest` | Os oito [espaços de chaves](14-persistencia.md) recebem o prefixo, e a ausência de prefixo preserva a chave |
| `Unit/Api/OpenApiSpecRoutesTest` | Correspondência entre [rotas e especificação](09-api.md) nos dois sentidos, com duas exceções declaradas |
| `Unit/Api/OpenApi/SchemaFromRequestTest` | Conjunto de restrições de validação traduzidas para o esquema |
| `Unit/Dashboard/DashboardElementIdsTest` | Existência no HTML de cada elemento referenciado pelo [JavaScript](20-frontend-da-dashboard.md) |

## Implementação

| Ficheiro | Responsabilidade |
|---|---|
| `phpunit.xml` | As duas suites PHP |
| `tests/run-parallel.sh` | Reparte uma suite PHP por vários processos |
| `phpstan.neon` · `phpcs.xml.dist` · `eslint.config.js` | As três ferramentas, com as exclusões justificadas |
| `composer.json` | O portão: os sete alvos que o `composer test` encadeia |
| `bin/in-hub` | Leva os alvos de PHP para o contentor `hub` |
| `tests/scenarios/run-all.sh` | Os cinco cenários |
| `tests/Support/` | Casos-base e duplos partilhados |
