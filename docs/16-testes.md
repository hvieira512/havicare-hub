# 16 — Testes

## As quatro suites

| Suite | Onde | Ficheiros | Precisa de |
|---|---|---|---|
| Unitários (PHP) | `tests/Unit/` | 88 | nada |
| Integração (PHP) | `tests/Integration/` | 29 | MySQL e Redis |
| Frontend (Node) | `tests/Frontend/` | 44 | nada |
| Cenários (shell) | `tests/scenarios/` | 5 | a pilha Docker inteira |

```bash
composer test:unit          # ~6 s
composer test:integration   # precisa de base de dados
composer test:frontend
composer test:scenarios     # levanta mosquitto, redis, mysql e o hub
composer test               # tudo, na ordem da integração contínua
```

O `composer test` é o portão completo: estilo, análise estática, lint do
frontend, as duas suites PHP, a do frontend e os cenários.

## O que cada uma cobre

**Unitários** — lógica isolada: descodificadores de protocolo, normalizador de
capacidades, construtor de comandos, validação de pedidos, especificação OpenAPI
e prefixo do Redis. Executam sem dependências externas.

**Integração** — a API ao nível da rota, sobre base de dados real: autenticação,
âmbito por inquilino, validação de escrita, servidor HTTP e stream.

**Frontend** — os módulos da dashboard, com `node --test`. Um dos testes renderiza
a página e verifica a existência no HTML de cada elemento referenciado pelo
JavaScript, impedindo a rutura silenciosa do contrato entre o PHP e o JS.

**Cenários** — o sistema inteiro, de ponta a ponta:

| Cenário | Prova |
|---|---|
| `hub_raw_mqtt_roundtrip` | Um dispositivo TCP simulado chega ao MQTT, e um comando da API chega-lhe de volta |
| `hub_downlink_queue` | Um comando para um aparelho offline fica em fila e é entregue quando ele volta |
| `dashboard_api` | 401 sem token, login, listagem, pedido de medição |
| `ncs_mqtt_ingress` | A ingestão Voerka |
| `location_beacondb_pipeline` | A resolução de localização, com um servidor falso |

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
| **ESLint** | Configuração plana, com estilo próprio. Corre com zero avisos tolerados |

Cada exclusão está justificada no respetivo ficheiro de configuração, com dados
quantitativos. Uma exclusão em particular não foi aplicada: a regra que assinala
comparações sempre verdadeiras **não** está silenciada globalmente, por o
silenciamento anterior ter permitido a passagem de um defeito.

## Integração contínua

Corre em cada push e cada pull request para `main` e `dev`.

Duas escolhas deliberadas:

**As versões são as da produção, não as mais recentes.** MariaDB 10.11, e PHP
8.4 — mas também 8.5, que é o que o contentor de desenvolvimento corre. As duas
correm para uma diferença entre elas aparecer aqui e não no servidor.

**A suite de integração falha se se ignorar a si própria.** Essa suite é
ignorada automaticamente quando a base de dados não responde, e um resultado
verde por omissão não constitui verificação. A ligação ao MariaDB e ao Redis é
exigida antes da execução.

A verificação não recorre a `grep` pela palavra "ignorado" na saída: existe um
teste legitimamente ignorado — o do áudio AMR-NB, quando o `ffmpeg` disponível
não inclui o codec — que essa abordagem reprovaria igualmente.

**Os cenários estão excluídos da integração contínua.** Requerem a pilha Docker
completa, cuja duração excede o tempo aceitável para um push. São executados
manualmente com `composer test:scenarios`.

## Motores de base de dados

O ambiente local executa MySQL 8.4 e a produção executa MariaDB 10.11. O volume
local contém ficheiros escritos pelo MySQL que o MariaDB não lê, o que impede a
substituição.

O esquema e as consultas evitam sintaxe exclusiva de qualquer dos motores,
condição verificada pela integração contínua a cada push.

## Testes de invariantes

Alguns testes não verificam comportamento, mas fixam decisões de projeto contra
regressão involuntária.

| Teste | Invariante |
|---|---|
| `Unit/Database/SeedWhitelistTest` | O seed não grava sentinelas de memória na base de dados e nunca produz licença sem empresa |
| `Unit/Runtime/RedisPrefixTest` | Os seis espaços de chaves recebem o prefixo, e a ausência de prefixo preserva a chave |
| `Unit/Api/OpenApiSpecRoutesTest` | Correspondência entre rotas e especificação nos dois sentidos, com duas exceções declaradas |
| `Unit/Api/OpenApi/SchemaFromRequestTest` | Conjunto de restrições de validação traduzidas para o esquema |
| `Unit/Dashboard/DashboardElementIdsTest` | Existência no HTML de cada elemento referenciado pelo JavaScript |

## Implementação

| Ficheiro | Responsabilidade |
|---|---|
| `phpunit.xml` | As duas suites PHP |
| `phpstan.neon` · `phpcs.xml.dist` · `eslint.config.js` | As três ferramentas, com as exclusões justificadas |
| `.github/workflows/ci.yml` | O portão |
| `tests/scenarios/run-all.sh` | Os seis cenários |
| `tests/Support/` | Casos-base e duplos partilhados |
