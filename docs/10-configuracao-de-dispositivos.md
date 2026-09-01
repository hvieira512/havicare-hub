# 10 — Configuração de dispositivos

## Âmbito

A configuração de um dispositivo não se resume à escrita de um valor. Implica a
emissão de um comando através de uma ligação que pode estar fechada, a espera
pela resposta e a determinação posterior de se a configuração foi efetivamente
aplicada.

Entre o estado solicitado e o estado em vigor no dispositivo existe uma
divergência que pode persistir durante horas. O modelo de dados representa
explicitamente essa divergência.

```mermaid
flowchart LR
  A["Estado solicitado<br/><small>desejado</small>"] -.->|"latência e falha possíveis"| B["Estado em vigor<br/><small>reportado</small>"]
```

A isso junta-se um segundo problema: os fabricantes não concordam em nada. Um
despertador chama-se `reminders` na Vivistar, `REMIND` na 4P Touch e `alarmClock`
na Wonlex — e as três listas têm formatos que não se parecem.

## 1. Capacidades genéricas

A API só fala em **nomes genéricos**. `alarm_clock`, `sos_contacts`,
`call_whitelist`, `medication_reminders`, `fall_detection`. Quem integra nunca
vê um nome de protocolo.

```mermaid
flowchart TB
  API["PATCH /api/devices/{imei}/configurations<br/><small>{ alarm_clock: { items: [...] } }</small>"]
  API --> C["CapabilityContract"]
  C --> V["Vivistar<br/><small>reminders</small>"]
  C --> W["Wonlex<br/><small>alarmClock</small>"]
  C --> F["4P Touch<br/><small>REMIND</small>"]
  V --> B1["bytes"]
  W --> B2["bytes"]
  F --> B3["bytes"]
```

Cada capacidade complexa é um objeto que sabe traduzir nos dois sentidos:
genérico → nativo para enviar, nativo → genérico para responder. As simples —
interruptores, números, telefones — caem numa implementação genérica.

A tradução precisa de saber o **protocolo** nas duas direções, e não só na de
ida: a mesma chave nativa quer dizer coisas diferentes em fabricantes
diferentes. A Wonlex e a 4P Touch chamam ambas `alarmClock` a listas com
formatos incompatíveis. Sem o protocolo, descodificar é adivinhar.

**Chaves nativas nunca aparecem na API.** Um pedido com `configs` ou com nomes de
protocolo é recusado.

## 2. As três tabelas

O estado de configuração vive em três tabelas, e cada uma responde a uma
pergunta diferente:

| Tabela | Responde a |
|---|---|
| `device_configurations` | Qual é o estado atual de cada capacidade, por dispositivo |
| `device_configuration_changes` | Que alterações foram pedidas, e em que pé estão |
| `device_configuration_operations` | Que comandos concretos foram construídos para as entregar |

Uma alteração pode dar **várias** operações: uma lista de dez alarmes pode ser
dez comandos, e a alteração só está confirmada quando todos estiverem.

```mermaid
flowchart TB
  R["device_configurations<br/><small>desejado + reportado, por capacidade</small>"]
  C["device_configuration_changes<br/><small>uma por pedido, com revisão</small>"]
  O["device_configuration_operations<br/><small>uma por comando construído</small>"]
  C -->|1..n| O
  R -->|aponta para a alteração atual| C
```

### Revisões

Cada alteração incrementa `desired_revision`. O `confirmed_revision` só sobe
quando o aparelho confirma. A diferença entre os dois é, literalmente, o que
ainda não chegou lá.

## 3. O ciclo de vida de uma operação

```mermaid
stateDiagram-v2
    [*] --> created: comando construído
    created --> sent: dispositivo ligado
    created --> queued: dispositivo offline
    queued --> sent: voltou a ligar-se
    sent --> waiting: à espera de resposta
    waiting --> acked: o aparelho respondeu
    waiting --> failed: recusou
    created --> superseded: chegou uma alteração nova
    queued --> superseded: chegou uma alteração nova
    waiting --> dropped: sem entrega possível
    acked --> [*]
    failed --> [*]
    dropped --> [*]
    superseded --> [*]
```

O estado da **alteração** é derivado do de todas as suas operações:

| Se alguma operação está… | A alteração fica |
|---|---|
| `failed` ou `dropped` | `failed` |
| `created` ou `queued` | `pending_delivery` |
| `sent` ou `waiting` | `awaiting_ack` |
| todas `acked` | `confirmed` |

Existe um quinto estado, **`confirmation_unavailable`**: todas as operações
foram confirmadas, mas o modo de confirmação é `ack_only`, no qual o dispositivo
acusa a receção sem confirmar a aplicação. A distinção face a `confirmed` evita
declarar uma confirmação que o protocolo não fornece.

O modo `ack_only` aplica-se a um único caso: a frequência de medição da
Vivistar.

### Supersessão

Uma alteração nova à mesma capacidade **substitui** a anterior. As operações da
antiga que ainda não saíram são marcadas `superseded` e nunca chegam a ser
enviadas — não faz sentido entregar uma configuração que já foi substituída.

A verificação acontece no momento de esvaziar a fila, logo depois de o aparelho
se autenticar.

## 4. O que a API devolve

`GET /api/devices/{imei}` traz quatro vistas do mesmo assunto:

| Campo | O que é |
|---|---|
| `capabilities` | O que o **modelo suporta**, com metadados para a interface |
| `configurations` | Os valores genéricos **desejados** |
| `effectiveConfigurations` | Os que o aparelho **confirmou** |
| `configurationSync` | A distância entre os dois, com estados e operações |

A distinção que mais confunde: **`capabilities` não é o que está guardado.** É o
que o modelo sabe fazer e o que a API aceita. Um dispositivo acabado de registar
já traz as capacidades todas, com valores por omissão.

As secções escrevíveis são `health`, `contacts`, `alarms` e `settings_system`.
A secção `telemetry` é **só de leitura** — descreve o que se pode medir:

```json
{
  "capabilities": {
    "telemetry": {
      "heart_rate": { "supported": true, "requestable": true },
      "location":   { "supported": true, "requestable": true }
    }
  }
}
```

As entradas escrevíveis trazem ainda `value`, com o valor público atual, e
`_meta`, com as opções e as etiquetas que uma interface precisa para as
desenhar. **Nenhuma entrada pública expõe identidade de protocolo** — os nomes
nativos só aparecem nas `operations[]` da resposta a um `PATCH`.

### Pedir uma medição

```http
POST /api/devices/{imei}/requests
{ "feature": "heart_rate" }
```

O nome é genérico. O cliente **não** deve depender de identificadores nativos
como `BPXL` ou `dnHeartRate` — o hub escolhe o comando certo para o protocolo
daquele aparelho e, mais tarde, a medição sai normalizada no MQTT.

Que capacidades se podem pedir num dado dispositivo lê-se em
`capabilities.telemetry.{feature}.requestable`.

### Alterar configurações

```http
PATCH /api/devices/{imei}/configurations
{
  "configurations": {
    "alarm_clock": {
      "items": [
        { "time": "08:10", "enabled": true, "type": 2,
          "recurrence": { "kind": "custom", "days": [1, 3, 5] } }
      ]
    },
    "working_mode": { "mode": 8, "intervalSeconds": 60, "gpsEnabled": true }
  }
}
```

A resposta traz as chaves alteradas, cada uma com as `operations[]` que foram
criadas para as entregar — incluindo o `nativeKey` de cada uma, que é onde a
identidade de protocolo aparece, e só ali.

## 5. Descoberta de capacidades

Um modelo novo chega sem se saber o que suporta. Em vez de o adivinhar, o hub
tem um fluxo que **pergunta ao aparelho**:

```text
POST   /api/capability-discovery          cria um rascunho a partir de um dispositivo real
GET    /api/capability-discovery/{id}     consulta o que se descobriu
POST   /api/capability-discovery/{id}/apply   aplica ao modelo
```

O rascunho fica guardado até alguém decidir aplicá-lo. É deliberado: descobrir é
observar, aplicar é uma decisão de administração.

## 6. Sensibilidade do sensor de fralda

O único caso em que uma configuração **não viaja para o aparelho**. O sensor
MONIT não aceita comandos; a sensibilidade é aplicada pelo hub, do lado de cá, ao
interpretar os canais de humidade.

É por isso que o protocolo `monit-mecs-pro-ble` declara suportar catálogo de
configuração apesar de não aceitar downlink. Quem decide se algo viaja é cada
capacidade, não o protocolo inteiro.

O detalhe completo está em [`diaper-sensitivity.md`](diaper-sensitivity.md).

## Implementação

| Ficheiro | Responsabilidade |
|---|---|
| `src/Domain/Capability/CapabilityContract.php` | O contrato dos dois sentidos da tradução |
| `src/Domain/Capability/CapabilityRegistry.php` | Que capacidades têm implementação própria |
| `src/Domain/Capability/CapabilityCatalog.php` | A identidade pública e o suporte por protocolo |
| `src/Command/DeviceConfigurationCatalog.php` | As definições nativas, por fabricante |
| `src/Command/Configuration/Payload/*.php` | Construir o corpo de cada comando |
| `src/Api/Services/DeviceConfigurationUpdateService.php` | O `PATCH`: validar, traduzir, criar operações |
| `src/Api/Repository/DeviceConfigurationLifecycleRepository.php` | As três tabelas e a derivação do estado |
| `src/Api/Services/ConfigurationSyncStatus.php` | Desejado contra reportado |
| [`alarm_clock.md`](alarm_clock.md) | O contrato de uma capacidade, a fundo *(em inglês)* |
