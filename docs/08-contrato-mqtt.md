# 08 — Contrato MQTT

## Âmbito

Esta é a referência de quem integra. Descreve o que o hub **publica** — o MQTT é
uma superfície de leitura, e o caminho para enviar comandos é a
[API REST](09-api.md).

## 1. Estrutura dos tópicos

A composição de tópicos está centralizada num único ponto do código e produz
invariavelmente esta forma:

```text
{prefixo}/{empresa}/{licenca}/{tipo}/{dispositivo}/{canal}
```

| Segmento | O que é | Exemplo |
|---|---|---|
| `{prefixo}` | A instância. `havicare-hub` em produção, `havicare-hub-dev` em desenvolvimento | `havicare-hub` |
| `{empresa}` | Nome do cliente, **sempre em minúsculas**. O texto `null` quando não tem dono | `hitcare` |
| `{licenca}` | Número da licença. `0` quando não tem dono | `1001` |
| `{tipo}` | `watch`, `ncs`, `radar`, `gateway`, `diaper_sensor`, `bracelet`, `pill_dispenser` | `watch` |
| `{dispositivo}` | Identidade canónica — a mesma que vai em `device.id` | `861265061009822` |
| `{canal}` | `raw`, `status`, `events`, `telemetry` | `telemetry` |

Exemplo completo:

```text
havicare-hub/hitcare/1001/watch/861265061009822/telemetry
```

E um dispositivo ainda sem cliente atribuído:

```text
havicare-hub/null/0/watch/637507597567372/status
```

> Documentação anterior a setembro de 2026 descreve os tópicos do NCS com quatro
> segmentos, omitindo a empresa. Essa forma está incorreta: o `NcsBridge`
> publica pelos mesmos métodos das restantes ingestões, que produzem sempre
> cinco segmentos. Ver o [capítulo do NCS](03-ingestao-mqtt-ncs.md).

O `{licenca}` no tópico é o **único sítio** em todo o hub onde o número da
licença é texto. Em memória, na base de dados e na API é sempre inteiro.

## 2. Os quatro canais

| Canal | O que leva | QoS | Retido |
|---|---|---|---|
| `telemetry` | Medições normalizadas | 0 | não |
| `events` | Acontecimentos: alarmes, ligações, comandos | **1** | não |
| `status` | O estado atual: online, offline, erro | **1** | **sim** |
| `raw` | A mensagem original do aparelho | 0 | não |

A regra é uma só: **o que não se repete vai a QoS 1; o que a leitura seguinte
substitui vai a QoS 0.**

> **Quem decide em que canal cada capacidade sai é o `CapabilityCatalog`**, pela
> bandeira `isEvent` da definição dela. Era uma lista escrita à mão dentro do
> `DeviceHubServer`, e as duas fontes de verdade discordavam — o custo disso está
> contado na tabela de alterações, no fim deste capítulo.

- **`events` a QoS 1**, porque um pedido de socorro acontece uma vez e nada o
  repete. Em contrapartida, um consumidor tem de tolerar a **repetição**: a
  entrega pelo menos uma vez significa que o mesmo alarme pode chegar duas
  vezes.
- **`status` a QoS 1**, pela mesma razão e com uma consequência pior. Uma
  transição de estado não é substituída pela leitura seguinte, e a mensagem é
  **retida** — um `offline` que se perca entre o hub e o broker deixa lá
  `online`, e o broker passa a servir esse valor a toda a gente que subscreva a
  partir daí, até o aparelho mudar de estado outra vez. Não é uma leitura
  perdida; é um facto errado a persistir.
- **`telemetry` a QoS 0**, porque cada medição é sucedida pela seguinte. Uma
  leitura perdida é substituída, e a garantia de entrega não compensa o custo no
  volume em que ela chega.
- **`status` retido**, por ser a única forma de um subscritor conhecer o estado
  atual sem aguardar uma transição.

> **O QoS do publicador é um teto, não um piso.** A qualidade efetiva de uma
> entrega é o mínimo entre a com que a mensagem foi publicada e a com que o
> consumidor subscreveu. Quem subscrever a QoS 0 recebe tudo a QoS 0, por muito
> que o hub publique a 1.

### Implicações para a subscrição

Um subscritor recebe **imediatamente** o `status` de cada dispositivo e, a
partir daí, apenas as mensagens subsequentes. O MQTT não disponibiliza
histórico; o estado anterior é obtido através da API REST.

## 3. `telemetry`

O envelope está descrito em detalhe no [capítulo da normalização](06-normalizacao.md).
Em resumo:

```json
{
  "type": "heart_rate",
  "occurredAt": "2026-09-01T10:35:10Z",
  "device": { "id": "861265061009822", "supplier": "Vivistar", "model": "L08 Pro" },
  "data": { "bpm": 74 },
  "source": { "protocol": "vivistar-iw", "nativeType": "AP49" }
}
```

**Todos os valores de `type` são publicados no mesmo tópico.** Os wildcards do
MQTT filtram pelo caminho e não pelo conteúdo, pelo que a seleção de uma
capacidade específica exige a subscrição de `telemetry` e a filtragem do lado do
cliente.

### Filtros de subscrição

```text
havicare-hub/+/+/+/+/telemetry                    tudo
havicare-hub/hitcare/1001/+/+/telemetry           um cliente
havicare-hub/+/+/watch/+/telemetry                só relógios
havicare-hub/hitcare/1001/watch/861265061009822/# um dispositivo, todos os canais
```

## 4. `events`

```json
{
  "type": "device.connected",
  "occurredAt": "2026-09-01T10:35:10Z",
  "device": { "id": "861265061009822", "supplier": "Vivistar", "model": "L08 Pro" }
}
```

Este canal transporta duas coisas diferentes: o **ciclo de vida** de um
dispositivo, que descreve a ligação, e os **acontecimentos de domínio**, que
descrevem o que aconteceu a uma pessoa. Os primeiros levam `type`, `occurredAt` e
`device`, e ainda, quando se aplica, um objecto `command` — nos `device.downlink.*`,
com o comando que saiu ou ficou em fila — ou um `error`. Os segundos acrescentam
`data` e `source`, com a mesma forma da telemetria.

### Eventos de ciclo de vida

| `type` | Condição de emissão |
|---|---|
| `device.connected` | Um aparelho autenticou-se, um NCS reportou-se online, ou um radar ou aparelho retransmitido voltou a falar |
| `device.disconnected` | Ligação fechada, inatividade, gateway calado, radar calado 3 minutos, ou retransmitido sem gateway que o ouça durante 30 |
| `device.rejected` | Um aparelho não registado tentou entrar |
| `device.downlink.sent` | Um comando saiu para o aparelho |
| `device.downlink.queued` | O aparelho estava offline; o comando ficou em fila. Sai uma vez por pedido: renovar a fila enquanto o aparelho não volta não o repete |
| `device.downlink.dropped` | O comando não foi entregue nem guardado |
| `device.measurement_failed` | Pulseira — uma medição pedida não produziu valor, e o aparelho disse porquê |

Os `dropped` levam `error.code`, que vale `device_offline` ou `queue_unavailable`.

O `measurement_failed` leva `error.reason`, que vale `not_worn` quando a deteção de uso
não passou, `low_battery` e `sensor_fault` quando é o firmware a recusar, `no_signal`
quando o traçado saiu todo a zeros, e `no_response` ou `no_reading` quando a pulseira não
respondeu ou respondeu sem valor. É a resposta a um pedido, e não um alerta: a bateria
fraca da pulseira sai em `low_battery`. A mesma queixa do mesmo aparelho fica calada durante
um minuto, para uma medição que insiste não encher o histórico.

### Eventos de domínio

O `type` diz **o que aconteceu**, com o mesmo nome em todos os aparelhos que o
reportam: quem subscreve `fall` recebe as quedas dos relógios e dos radares sem
conhecer nenhum dos dois. O `data` traz o pormenor, e a `severity` diz quão grave
é.

```json
{
  "type": "heart_rate_high",
  "severity": "alarm",
  "occurredAt": "2026-10-08T10:35:10Z",
  "device": { "id": "594B3CD2D097", "supplier": "Qinglanst", "model": "RD-V1" },
  "data": { "bpm": 172 },
  "source": { "protocol": "qinglanst-radar", "nativeType": "heartbreath", "topic": "…" }
}
```

#### A gravidade

Todo o evento de domínio leva `severity`, logo a seguir ao `type`. Os eventos de
ligação (`device.*`) não a levam.

| `severity` | Quer dizer |
|---|---|
| `alarm` | Perigo para a pessoa: pede alguém já |
| `alert` | Pede atenção, mas não é urgente |
| `info` | Um facto, sem nada a fazer |

Quem a decide é o hub, num sítio só (`EventSeverity`), e não o fabricante. Os
eventos guardados no histórico da dashboard levam-na igual.

#### Os tipos

| `type` | `data` | Quem o publica | `severity` |
|---|---|---|---|
| `help_call` | `pressType` (pulseira), `state` (dispensador), `pagerId` (NCS) | relógio (SOS), pulseira, NCS, dispensador | `alarm` |
| `fall` | `confirmed`, `posture` (`lying` · `sitting_on_ground`), `personIndex` | relógio, radar | `alarm`; `alert` com `confirmed: false` |
| `heart_rate_high` | `bpm`, quando há | radar | `alarm` acima de 160 bpm, `alert` abaixo ou sem valor |
| `heart_rate_low` | `bpm`, quando há | radar | `alarm` abaixo de 20 bpm, `alert` acima ou sem valor |
| `heart_rate_abnormal` | — | relógio 4P Touch | `alert` |
| `breath_rate_high` · `breath_rate_low` | `breathsPerMinute`, quando há | radar | `alert` |
| `apnea` | — | radar | `alarm` |
| `weak_vital_signs` | — | radar | `alert` |
| `zone_entry` · `zone_exit` | `zone` (`room` · `area` · `geofence`), `personIndex`, `areaId`, `areaName`, `areaType` | radar, relógio 4P Touch | `alert` a sair da cerca; `info` o resto |
| `device_removed` | — | relógio | `alert` |
| `low_battery` | `percent` ou `voltageMv`, quando há | relógios, pulseira Veepoo, dispensador, MKGW4 | `alert` |
| `change_required` | `previousState` | sensor de fralda | `alarm` |
| `check_required` | `previousState` | sensor de fralda | `alert` |
| `device_fault` | `fault` | dispensador | `alert` |
| `storage_environment` | `outOfRange` | dispensador | `alert` |
| `device_state` | `state` | relógio Wonlex | `alert` |
| `medication_alarm_change` | `alarm`, `state` | dispensador | `alert` numa dose falhada; `info` o resto |
| `medication_intake` | `result`, … | dispensador | `alert` numa toma anormal ou falhada; `info` o resto |
| `reset` | `pagerId` | NCS | `info` |

O 4P Touch diz só que a frequência cardíaca está anormal, sem o valor e sem dizer
para que lado: é a única origem do `heart_rate_abnormal`.

Um relógio pode reportar vários alarmes de uma vez — a trama do 4P Touch é uma
máscara de bits —, e nesse caso sai **um evento por bit**, com o mesmo
`occurredAt`. A posição de um alarme de relógio vai à parte, como `location` no
canal `telemetry` com `data.reportKind: "alarm"`.

#### Entradas e saídas

`zone` diz de onde: a divisão que o radar cobre, uma das áreas desenhadas na
planta dele, ou a cerca do relógio. Numa área, o `areaId` é o número dela na
planta, e o `areaName` e o `areaType` vêm da planta que o hub guardou desse radar
— sem planta sincronizada, fica só o número.

| `areaType` | Na planta do fabricante |
|---|---|
| `custom` | Customize |
| `bed` | Bed |
| `interference` | Exclusive area |
| `door` | Door |
| `monitoring_bed` | Monitoring Bed |
| `alarm_area` | Sensing area |
| `furniture` | Furniture |

```json
{ "type": "zone_exit", "severity": "info",
  "data": { "zone": "area", "personIndex": 0, "areaId": 2, "areaName": "Porta", "areaType": "door" } }
```

#### O que dura sai uma vez

Um evento sai quando a condição **começa**, e não enquanto dura:

- **O radar** repete a postura e os vitais em cada trama, uma por segundo. Uma
  queda, uma frequência alta ou uma apneia saem quando aparecem, e voltam a poder
  sair depois de desaparecerem.
- **O dispensador** repete as TAGs de estado em cada heartbeat. Uma avaria, a
  chamada de ajuda e o ambiente fora da gama saem quando acendem, e voltam a
  poder sair depois de o aparelho os dar por apagados.
- **A bateria fraca** sai quando a bandeira acende: o alarme do próprio aparelho
  (relógios, MKGW4) ou a passagem do `battery.lowBattery` a verdadeiro (4P Touch,
  Veepoo, dispensador). O `battery` na telemetria leva `lowBattery` sempre que o
  aparelho o diz.

O estado vive em memória: um reinício do hub volta a anunciar o que estiver
ativo. Quem consome a QoS 1 já tem de tolerar repetidos.

## 5. `status`

```json
{
  "state": "online",
  "updatedAt": "2026-09-01T10:35:10Z",
  "device": { "id": "861265061009822", "supplier": "Vivistar", "model": "L08 Pro" }
}
```

`state` vale `online`, `offline` ou `error`. O `error` traz um objeto `error` com
o código, e é o **único** que não é retido — uma recusa é um acontecimento, não
um estado que valha a pena guardar.

Quem tem `status`: relógios, NCS, gateways, radares e a pulseira Veepoo.
**Não têm:** as pulseiras W6/W6B e os sensores de fralda, que só se ouvem através de
um gateway — sabe-se deles pela `proximity` a passar a `unknown`, ou pela ausência
de telemetria.

### O estado retido e a mudança de cliente

Ao reassociar um dispositivo, o hub publica uma mensagem **de comprimento zero**
no tópico do cliente antigo, para apagar a retida. Um subscritor que a receba
deve interpretar um payload vazio como "este dispositivo já não está aqui", e
não tentar analisá-lo como JSON.

## 6. `raw`

A mensagem original, com contexto suficiente para a reconstruir:

```json
{
  "direction": "uplink",
  "occurredAt": "2026-09-01T10:35:10Z",
  "device": { "id": "861265061009822" },
  "debug": {
    "protocol": "vivistar-iw",
    "transport": "tcp",
    "encoding": "text",
    "payload": "IWAP49,74#",
    "size": 10
  }
}
```

`encoding` diz em que forma vem o `payload`, e depende do caminho de ingestão. Na
ingestão TCP dos relógios vale `text` ou `base64` — o hub decide olhando para os
bytes —, e quando o protocolo é descodificável o `payload` traz o objeto já
interpretado, com o original em `debug.encoded`, em base64. Nos gateways MOKO vale
`json`, quando a trama chega em JSON e o `payload` é o objeto, ou `binary`, quando
chega em bytes e o `payload` é a sua representação em hexadecimal. Os campos
`encoding` e `size` são do caminho TCP; o `raw` dos ingressos MQTT pode não os
trazer.

O campo `direction` assume `uplink` ou `downlink`, uma vez que os comandos
enviados são igualmente publicados neste canal.

**Cada mensagem de um dispositivo autorizado é publicada em `raw`**, antes da
deduplicação e incluindo as que o hub não interpreta — relógios, NCS, gateways,
radares e os aparelhos retransmitidos por um gateway (pulseiras e sensor de
fralda). Um dispositivo que o hub ignora, por não estar na whitelist, não chega a
este canal.

## 7. Tópicos subscritos

> **O MQTT é uma superfície de leitura.** O hub não aceita comandos por MQTT: o
> caminho de comandos é a API REST, descrita em
> [comandos e downlink](11-comandos-e-downlink.md). Os comandos enviados
> continuam a ser **publicados** no canal `raw`, com `direction: "downlink"`,
> para quem quiser observá-los.

Os tópicos que o hub subscreve não são contrato dele — são o que a firmware de
cada fabricante já publica:

| Origem | Filtro | Sessão |
|---|---|---|
| NCS Voerka | `/voerka/#` | a do hub |
| Gateways MOKO | `havicare-hub/null/0/gw/+/raw` | a do hub |
| Radar Qinglanst | `radar/+/+` | própria, no **mesmo** broker |

O radar abre sessão à parte — credenciais e identificador de cliente próprios —
mas no mesmo broker que todo o resto.

### A norma de um tópico de entrada

Os gateways MOKO são o único fornecedor já apontado para o espaço do hub, e é
deles que sai a forma a seguir quando um aparelho for reapontado:

```text
sobe:   havicare-hub/null/0/gw/{mac}/raw
desce:  havicare-hub/null/0/gw/{mac}/cmd
```

| Segmento | Regra |
|---|---|
| prefixo | o `MQTT_TOPIC_PREFIX` da instância |
| empresa e licença | `null/0` — o aparelho não sabe de quem é, e quem resolve o dono é a [whitelist](07-multi-inquilino.md) |
| tipo | **diferente do que o hub publica**: entra `gw`, sai `gateway` |
| último | `raw` para o que sobe, `cmd` para o que desce |

A terceira regra é a que não se adivinha: se o tipo de entrada fosse igual ao de
saída, o hub subscrevia o que ele próprio publica.

## 8. Divergências face a documentação anterior

As versões anteriores do contrato contêm as seguintes incorreções:

| Descrição anterior | Comportamento efetivo |
|---|---|
| NCS publica em `{licenca}/ncs/…` | Publica com cinco segmentos, como as restantes ingestões |
| `blood_pressure` inclui `pulseBpm` | O campo não existe; o pulso é emitido como evento `heart_rate` autónomo |
| `activity` não inclui `distanceKm` | O campo existe quando o dispositivo reporta quilómetros |
| Doze capacidades de telemetria | Eram muitas mais já nessa altura, e continuam a crescer — a lista em vigor é a da [normalização](06-normalizacao.md) |
| O envelope leva `schemaVersion` | O campo foi removido; ver abaixo |
| Os alarmes dos relógios saem em `telemetry` | Saem em `events`, a QoS 1 |
| O `device_state` dos relógios sai em `telemetry` | Sai em `events`, a QoS 1. Está declarado como acontecimento desde sempre; o que o mandava para o outro canal era a lista à mão que o `isEvent` substituiu |
| O estado de toma dos alarmes do dispensador é sempre `medication_alarm_status` | A **leitura dos nove** continua a sê-lo, em `telemetry`; a **mudança de um** é `medication_alarm_change`, em `events` a QoS 1. Uma dose falhada não gera `medication_intake` nenhum, e esta mudança é o único sinal dela |
| Os alarmes dos relógios saem num `type: "alarm"` com um `reason` | Cada motivo é um tipo: `help_call`, `fall`, `low_battery`, `device_removed`, `zone_entry`/`zone_exit`, `heart_rate_abnormal` |
| As detecções do radar saem em `fall`, `vitals_alarm` e `presence_event`, com `detectionType`, `detectionCategory`, `detectionLevel`, `detectionSource` e `details` | Cada detecção é um tipo, com os campos no `data`; o grau é a `severity` |
| O radar publica `vitals_signal_lost` quando não mede ninguém | Um quarto vazio não publica nada; o sinal fraco do `hbstatics` é `weak_vital_signs` |
| A bateria fraca sai em `alarm`, em `battery.lowBattery`, em `batteryType` ou em `chargingState` | Sai em `low_battery`, igual para todos, e o `battery` leva `lowBattery` |
| O radar publica com o `uid` do tópico de origem | Publica com o IMEI canónico, como as restantes ingestões |
| Existe um tópico de downlink por MQTT | Foi removido; os comandos entram pela API REST |

### Sobre o `schemaVersion`

O campo foi removido de todos os canais. Nunca chegou a ser um contrato de
versão: era escrito e nunca lido, e o valor seguia o produtor da mensagem e não
o canal — o `events` transportava `1` e `2` conforme o dispositivo. Versionar é
assunto da API; aqui a estabilidade é mantida por não se partir o que já está
publicado.

> **Mensagens retidas.** O canal `status` é retido, pelo que um `status`
> publicado antes desta alteração continua a ser entregue na forma antiga até o
> dispositivo mudar de estado. Um consumidor não deve exigir a ausência do
> campo, apenas deixar de depender dele.

## Implementação

| Ficheiro | Responsabilidade |
|---|---|
| `src/Device/HubMqttBridge.php` | Compõe todos os tópicos e publica os quatro canais |
| `src/Device/RawPayload.php` | As formas de `raw`, `status` e do ciclo de vida |
| `src/Device/DeviceEventPayloadBuilder.php` | A forma de `telemetry` e dos alarmes |
| `src/Domain/Capability/EventSeverity.php` | A `severity` de cada evento, carimbada no `HubMqttBridge::publishEvent` e no histórico |
| `src/Device/LowBatteryTransitions.php` | O `low_battery` de quem repete a bandeira em cada leitura |
| `src/Ingress/Mqtt/Qinglanst/ActiveDetections.php` | As detecções do radar que duram saem uma vez |
| `src/Device/DeviceHubServer.php` | A escolha do canal na ingestão TCP, que pergunta ao `CapabilityCatalog::isEventType()` |
| `src/Domain/Capability/CapabilityCatalog.php` | O `isEvent` de cada definição, que é quem decide o canal |
| `src/Mqtt/BrokerSettings.php` · `ConnectionFactory.php` | Ligação, TLS, identificadores de cliente |
