# 14 — Persistência

## Âmbito

A persistência assenta em duas bases de dados com funções distintas:

- **MySQL** armazena o estado durável: inventário de dispositivos, atribuição a
  clientes, capacidades suportadas e configuração.
- **Redis** armazena o estado corrente: presença, mensagens recentes, comandos
  pendentes e sessões abertas.

**Não existe tabela de telemetria.** A plataforma não constitui um arquivo
histórico — ver a
secção 3.

## 1. MySQL — as 16 tabelas

```mermaid
erDiagram
    suppliers ||--o{ models : "tem"
    device_types ||--o{ models : "tipifica"
    device_types ||--o{ capabilities : "tipifica"
    device_types ||--o{ whitelist : "tipifica"
    models ||--o{ model_capabilities : "suporta"
    capabilities ||--o{ model_capabilities : "é suportada por"
    companies ||--o{ licenses : "tem"
    licenses ||--o{ api_users : "dá acesso a"
    whitelist ||--o{ gateway_device_links : "liga"
    whitelist ||--o{ device_configurations : "configura"
    device_configuration_changes ||--o{ device_configuration_operations : "entrega por"
```

### Catálogo — o que existe no mundo

| Tabela | Guarda |
|---|---|
| `device_types` | Os tipos de dispositivo. Conteúdo vindo do `config/device-types.json` |
| `suppliers` | Os sete fornecedores. Nome único |
| `models` | Modelo interno, nome comercial, tipo de dispositivo, imagem |
| `capabilities` | O catálogo genérico: chave, secção, e as bandeiras de configurável e pedível |
| `model_capabilities` | Que capacidades cada modelo tem, com a possibilidade de sobrepor |

**O tipo de dispositivo tem uma tabela e três chaves estrangeiras.** Era um
`ENUM` repetido em `whitelist`, `models` e `capabilities`, e acrescentar um tipo
eram três `ALTER TABLE` que tinham de concordar — a discordância não produzia
erro, produzia um dispositivo registado e sem capacidade alguma. O conteúdo vem
do `config/device-types.json`, que o frontend também lê, e o semeador mantém a
tabela igual ao ficheiro.

**A `model_capabilities` refere a capacidade pelo par natural**, e não pelo
`capabilities.id`. O identificador de substituição não era falado pelo código:
todas as leituras juntavam a `capabilities` apenas para o traduzir de volta à
chave. Com `ON UPDATE CASCADE`, renomear uma capacidade leva as ligações consigo.
O `capabilities.id` permanece como identificador exposto pela API.

**O `is_telemetry` deixou de ser coluna.** Coincidia com `section = 'telemetry'`
nas 93 linhas do catálogo, e é assim que a consulta o calcula; o campo
`isTelemetry` da API mantém-se.

### Registo — o que temos e de quem é

| Tabela | Guarda |
|---|---|
| `whitelist` | **O registo de dispositivos.** IMEI como chave, fornecedor, modelo, tipo, e o par empresa+licença |
| `gateway_device_links` | Que dispositivos BLE cada gateway pode retransmitir |
| `companies` · `licenses` | Os clientes. Uma licença é única dentro da sua empresa |
| `api_users` | Contas da API, com papel e âmbito |

### Configuração — o que pedimos e o que foi feito

| Tabela | Guarda |
|---|---|
| `device_configurations` | Estado atual por dispositivo e capacidade: desejado, reportado, revisões |
| `device_configuration_changes` | Uma linha por pedido de alteração, com o seu estado |
| `device_configuration_operations` | Que comandos foram construídos, com tentativas e erro |

Ver o [capítulo da configuração](10-configuracao-de-dispositivos.md).

**As operações não guardam os bytes do comando.** A entrega faz-se pela fila
`hub:downlink` do Redis, com TTL, e é lá que residem os bytes que saem no fio. A
tabela é um registo do que foi pedido e de como correu, e é lida apenas por
`change_id` — não existe despachante a consultá-la por estado.

**O histórico guarda a marca do áudio e não o áudio.** O aviso de medicação da 4P
Touch transporta a gravação em base64, e um ficheiro de 42 s ocupa 978 KB. Uma
cópia por revisão representava 69% da base de produção. As linhas de
`device_configuration_changes` conservam `voiceDataAvailable` e `voiceDataBytes`,
que é o vocabulário que a API já usava para o mesmo efeito.

A gravação permanece em `device_configurations`, porque essa tabela é a base de
fusão de uma alteração parcial: sem ela, uma alteração que mude apenas a hora do
aviso apagaria a voz do relógio. É também a cópia mais reduzida — uma linha por
dispositivo e capacidade, substituída e não acumulada.

### Resto

| Tabela | Guarda |
|---|---|
| `dashboard_notifications` | Recusas e avisos, com contagem de ocorrências e marca de lido |
| `private_radio_map_access_points` | O mapa de rádio. **Só resumos HMAC**, nunca endereços |
| `schema_migrations` | Que versões já foram aplicadas |

### Ausências deliberadas de chave estrangeira

**A `whitelist` não referencia `models`.** O fornecedor e o modelo são
armazenados como texto e associados ao catálogo por correspondência de nome. A
ausência de restrição permite registar um dispositivo de um modelo ainda não
catalogado.

**A tabela `device_configurations` não referencia a `whitelist`.** A remoção de
um dispositivo elimina as respetivas configurações numa transação explícita. Uma
chave estrangeira converteria uma mensagem em trânsito, no caminho crítico do
MQTT, numa exceção.

## 2. Esquema e migrações

O esquema **nunca** é alterado por uma ligação normal. `php bin/migrate.php` é um
passo explícito de instalação, e o hub **recusa arrancar** se a base estiver
atrasada.

```mermaid
flowchart LR
  A["bin/migrate.php"] --> B["schema.sql<br/><small>idempotente</small>"]
  B --> C["Migrações versionadas"]
  C --> D["Catálogo de referência<br/><small>só se estiver vazio</small>"]
```

O catálogo de referência — fornecedores, modelos, capacidades — é semeado **só
numa base sem capacidades**. Reiniciar o hub não recria o que foi apagado nem
desfaz escolhas de um administrador.

> **Uma migração corre antes do semeador, e isso tem uma armadilha.** A guarda do
> semeador é "a tabela `capabilities` está vazia?". Uma migração que escreva nessa
> tabela numa base de raiz faz a guarda saltar, e a instalação nasce sem
> fornecedores, sem modelos e sem empresas. Por isso a migração do catálogo
> começa por desistir quando a tabela está vazia: numa base nova não há nada a
> trazer a dia, e a linha de base trata do assunto.

Há hoje **nove** migrações. As seis de 4 de setembro fecham a auditoria ao
esquema:

| Migração | O que faz |
|---|---|
| `drop_unread_lifecycle_columns` | Larga o `imei` e o `config_key` das operações, que copiavam a alteração, e o `confirmed_revision`, escrito e nunca lido |
| `drop_capability_telemetry_flag` | Larga o `is_telemetry`, que repetia a secção |
| `drop_api_user_license_number` | Larga o `api_users.license_id`, que repetia o número da licença apontada |
| `configuration_timestamps_to_datetime` | Converte os onze instantes de texto para `DATETIME`, e a cadeia vazia para `NULL` |
| `device_types_table` | Dá aos tipos de dispositivo uma tabela e três chaves estrangeiras |
| `shrink_legacy_varchar_191` | Encurta as vinte e quatro colunas em `VARCHAR(191)` |
| `model_capabilities_by_natural_key` | Aponta as ligações ao par `(device_type, capability_key)` |

Cada uma verifica antes de converter e desiste em vez de perder informação: uma
coluna que discorde da origem, um valor que não seja ISO-8601, um texto que não
caiba na largura nova.

As três de 3 de setembro:

A `2026_09_03_drop_configuration_supplier_and_model` larga o `supplier` e o
`model` da `device_configurations`. Eram cópia do que a `whitelist` diz sobre o
IMEI, escritas em três caminhos e lidas em nenhum, e a cópia divergiu: as linhas
órfãs do IMEI `000060060298220` declaravam dois modelos para o mesmo aparelho.

A `2026_09_03_drop_supplier_device_types` larga a tabela que respondia a «que
fornecedores fazem cada tipo de dispositivo». A pergunta responde-se com um
`SELECT DISTINCT` sobre a `models`, que é a origem — ver
`ModelRepository::supplierDeviceTypes()`. A tabela era escrita num sítio só e
apenas a inserir, pelo que apagar o último modelo de um tipo, ou mudar-lhe o
tipo, deixava lá o par a afirmar o contrário.

A `2026_09_03_shrink_configuration_lifecycle` larga as
três colunas de `device_configuration_operations` que eram escritas e nunca
lidas — `command_bytes`, `expected_reply_types` e `retry_delay_seconds` —, larga
os cinco índices que nenhuma consulta podia usar, repõe dois deles pela ordem em
que são procurados, e converte os `desired_payload` e `effective_payload` que já
existiam para a marca do áudio.

A conversão dos dados recorre ao `VoiceDataMarker`, que é o mesmo código que
escreve as linhas novas. As linhas antigas e as novas não podem, por isso,
divergir de forma.

O inventário de exemplo — 26 dispositivos, licenças, imagens — é `bin/seed-inventory.php`
e está **fora** do plano de migrações de propósito: senão cada teste de integração
começava com 26 dispositivos lá dentro.

## 3. Redis — os seis espaços de chaves

| Prefixo | Guarda | Expira? |
|---|---|---|
| `hub:dashboard` | Presença, metadados em execução, histórico, comandos, avistamentos | não — mas limitado |
| `hub:api-tokens` | Os três tipos de token | sim, é o TTL que os valida |
| `hub:downlink` | A fila de comandos por entregar | sim, 300 s |
| `hub:moko` | De-duplicação, refrescamento e transições de estado BLE | parcialmente |
| `hub:location:circuit` | O estado do disjuntor | sim |
| `hub:location:resolution` | Cache de resoluções de localização | sim, 24 h ou 60 s |

### O histórico é limitado, não é um arquivo

Cada dispositivo tem quatro listas — `raw`, `telemetry`, `events`, `commands` — e
cada uma guarda as últimas **100** entradas (`DASHBOARD_HISTORY_LIMIT`). O corte
é feito no mesmo pipeline da escrita.

A retenção é dimensionada para a apresentação do passado recente na dashboard.
**A conservação de série temporal cabe às aplicações que subscrevem o MQTT.**

Dois tipos de mensagem são excluídos do histórico, pelo volume que representam:
os sinais de vida dos relógios e os relatórios de varrimento dos gateways.

## 4. Duas instâncias no mesmo Redis

A raiz `hub:` **já é partilhada** com o reencaminhador, que é outra aplicação e
lá tem as suas `hub:forward:*` e `hub:crm:target:*`.

A variável `REDIS_PREFIX` permite executar uma segunda instância contra o mesmo
Redis. O valor vazio corresponde a produção, mantendo as chaves nas suas
posições originais; a instância de desenvolvimento usa `dev:`.

**O prefixo é aplicado ao cliente e não a cada componente.** Existem seis
espaços de chaves, e a aplicação por componente faria com que um sétimo espaço
adicionado posteriormente ficasse sem prefixo, sem erro visível. Um teste
verifica os seis.

Uma segunda instância a escrever nas mesmas chaves não produziria erro:
sobreporia o estado dos dispositivos da primeira e as sessões das duas
dashboards seriam partilhadas.

## 5. O que se perde ao reiniciar

| | Sobrevive? |
|---|---|
| MySQL | tudo |
| `hub:dashboard` | sim — não tem TTL |
| `hub:moko:last` e `:condition` | sim, por ausência deliberada de TTL |
| `hub:api-tokens`, `hub:downlink`, `hub:location:*`, `hub:moko:dedupe` | conforme o TTL |
| Ligações TCP abertas, gateways online, janelas de proximidade | **não**, por residirem em memória do processo |

O estado do disjuntor reside no Redis por decisão: um processo reiniciado
durante uma indisponibilidade externa não retoma de imediato as chamadas ao
serviço.

## 6. Verificação do isolamento

A contagem das chaves de cada instância, antes e depois de uma alteração,
identifica eventuais escritas no espaço da outra:

```bash
redis-cli --scan --pattern 'hub:*'     | grep -vc '^dev:'
redis-cli --scan --pattern 'dev:hub:*' | wc -l
```

As chaves `hub:forward:*` e `hub:crm:target:*` pertencem ao reencaminhador e
integram a primeira contagem sem pertencerem ao hub.

## Implementação

| Ficheiro | Responsabilidade |
|---|---|
| `database/schema.sql` | A linha de base — as 16 tabelas |
| `database/seed.sql` · `bin/seed-inventory.php` | O inventário de exemplo |
| `src/Infrastructure/Persistence/DatabaseMigrator.php` | Esquema, migrações, catálogo |
| `src/Api/Configuration/VoiceDataMarker.php` | A marca do áudio, no histórico e na resposta |
| `src/Infrastructure/Persistence/DatabaseSchemaGuard.php` | Recusa arrancar atrasado |
| `src/Infrastructure/Persistence/ReferenceCatalogSeeder.php` | Fornecedores, modelos e capacidades |
| `src/Api/Repository/` | Um repositório por assunto |
| `src/Dashboard/DeviceRuntimeStore.php` · `DeviceEventStore.php` · `DeviceCommandStore.php` | Os três espaços de `hub:dashboard` |
| `src/Runtime/HubServices.php` | Onde o prefixo do Redis é aplicado |
| `tests/Unit/Runtime/RedisPrefixTest.php` | Tranca os seis espaços de chaves |
