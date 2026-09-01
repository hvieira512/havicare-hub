# 08 — Contrato MQTT

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
| `{tipo}` | `watch`, `ncs`, `radar`, `gateway`, `diaper_sensor`, `bracelet` | `watch` |
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
> segmentos, omitindo a empresa. Essa forma está incorreta: o `Ncs\Bridge`
> publica pelos mesmos métodos das restantes ingestões, que produzem sempre
> cinco segmentos. Ver o [capítulo do NCS](03-ingestao-mqtt-ncs.md).

O `{licenca}` no tópico é o **único sítio** em todo o hub onde o número da
licença é texto. Em memória, na base de dados e na API é sempre inteiro.

## 2. Os quatro canais

| Canal | O que leva | QoS | Retido |
|---|---|---|---|
| `telemetry` | Medições normalizadas | 0 | não |
| `events` | Acontecimentos: alarmes, ligações, comandos | **1** | não |
| `status` | O estado atual: online, offline, erro | 0 | **sim** |
| `raw` | A mensagem original do aparelho | 0 | não |

Fundamento de cada opção:

- **`events` a QoS 1**, porque a perda de um pedido de socorro é inaceitável e o
  custo da entrega pelo menos uma vez é reduzido no volume destes eventos. Em
  contrapartida, um consumidor tem de tolerar a **repetição**: a entrega pelo
  menos uma vez significa que o mesmo alarme pode chegar duas vezes.
- **`telemetry` a QoS 0**, porque cada medição é sucedida pela seguinte. Uma
  leitura perdida é substituída, e a garantia de entrega não compensa o custo.
- **`status` retido**, por ser a única forma de um subscritor conhecer o estado
  atual sem aguardar uma transição.

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
descrevem o que aconteceu a uma pessoa. Os primeiros levam apenas `type`,
`occurredAt` e `device`; os segundos acrescentam `data` e `source`, com a mesma
forma da telemetria.

### Eventos de ciclo de vida

| `type` | Condição de emissão |
|---|---|
| `device.connected` | Um aparelho autenticou-se, ou um NCS reportou-se online |
| `device.disconnected` | Ligação fechada, inatividade, ou gateway calado |
| `device.rejected` | Um aparelho não registado tentou entrar |
| `device.downlink.sent` | Um comando saiu para o aparelho |
| `device.downlink.queued` | O aparelho estava offline; o comando ficou em fila |
| `device.downlink.dropped` | O comando não foi entregue nem guardado |

Os `dropped` levam `error.code`, que vale `device_offline` ou `queue_unavailable`.

### Eventos de domínio

| `type` | Origem |
|---|---|
| `alarm` | Relógio — SOS, queda, bateria fraca ou aviso de uso |
| `help_call` | Pulseira e NCS |
| `reset` | NCS |
| `change_required` | Sensor de fralda |
| `fall` · `vitals_alarm` · `presence_event` | Radar |

Um alarme de relógio leva em `data` o motivo, e vai acompanhado de uma
`location` no canal `telemetry` com `data.reportKind: "alarm"` — a posição
pertence à telemetria, e é esse campo que volta a ligar as duas metades do mesmo
acontecimento:

```json
{
  "type": "alarm",
  "occurredAt": "2026-09-01T10:35:10Z",
  "device": { "id": "861265061009822", "supplier": "Vivistar", "model": "L08 Pro" },
  "data": { "code": "sos", "sos": true, "lowBattery": false, "fall": false, "wearingNotice": false },
  "source": { "protocol": "vivistar-iw", "nativeType": "AP10" }
}
```

O `code` só está presente quando **exatamente um** motivo está ativo; com mais
do que um, valem os campos booleanos.

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

Quem tem `status`: relógios, NCS e gateways. **Não têm:** radares, pulseiras e
sensores de fralda. A presença de um sensor BLE não é acompanhada — sabe-se pela
mensagem de `proximity` a passar a `unknown`, ou pela ausência de telemetria.

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

`encoding` vale `text` ou `base64` — o hub decide olhando para os bytes. Quando
o protocolo é descodificável, `payload` traz o objeto já interpretado e o
original vai em `debug.encoded`, em base64.

O campo `direction` assume `uplink` ou `downlink`, uma vez que os comandos
enviados são igualmente publicados neste canal.

**Todas as mensagens recebidas são publicadas em `raw`**, incluindo as que o hub
não interpreta. Este canal garante a preservação integral dos dados de origem.

## 7. Tópicos subscritos

> **O MQTT é uma superfície de leitura.** O hub não aceita comandos por MQTT: o
> caminho de comandos é a API REST, descrita em
> [comandos e downlink](11-comandos-e-downlink.md). Os comandos enviados
> continuam a ser **publicados** no canal `raw`, com `direction: "downlink"`,
> para quem quiser observá-los.

Os tópicos que o hub subscreve não são contrato dele — são o que a firmware de
cada fabricante já publica:

| Origem | Filtro | Broker |
|---|---|---|
| NCS Voerka | `/voerka/#` | o do hub |
| Gateways MOKO | `havicare-hub/null/0/gw/+/raw` | o do hub |
| Radar Qinglanst | `radar/1001/#` | **outro** |

## 8. Divergências face a documentação anterior

As versões anteriores do contrato contêm as seguintes incorreções:

| Descrição anterior | Comportamento efetivo |
|---|---|
| NCS publica em `{licenca}/ncs/…` | Publica com cinco segmentos, como as restantes ingestões |
| `blood_pressure` inclui `pulseBpm` | O campo não existe; o pulso é emitido como evento `heart_rate` autónomo |
| `activity` não inclui `distanceKm` | O campo existe quando o dispositivo reporta quilómetros |
| Doze capacidades de telemetria | São vinte — ver a [normalização](06-normalizacao.md) |
| O envelope leva `schemaVersion` | O campo foi removido; ver abaixo |
| Os alarmes dos relógios saem em `telemetry` | Saem em `events`, a QoS 1 |
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
| `src/Device/DeviceHubServer.php` | A escolha do canal: `alarm` vai a `events`, o resto a `telemetry` |
| `src/Mqtt/BrokerSettings.php` · `ConnectionFactory.php` | Ligação, TLS, identificadores de cliente |
