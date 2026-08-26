# Medidor de fraldas — contrato MQTT normalizado

Tudo o que um cliente precisa para consumir os medidores de fraldas MONIT MECS-PRO
a partir da saída MQTT do hub: que tópicos transportam o quê, que partes do payload
estão congeladas, e como levar isto a um browser através de um WebSocket.

---

## 1. De onde vêm os dados

```
MONIT MECS-PRO           gateway MOKO              hub                    cliente
 (anúncio BLE)      →    (retransmite por     →   descodifica,       →   tópicos MQTT
                          MQTT)                    autoriza,              normalizados
                                                   normaliza
```

O sensor é um beacon BLE passivo. Nunca fala com o hub e não aceita comandos — não há
nada para lhe pedir, e por isso também não existe tópico `raw` nem `status` para ele.
O sensor emite um anúncio de 20 bytes; o gateway MOKO que o ouvir retransmite esse
anúncio para o hub, e o hub republica o resultado **com a identidade do sensor e não
a do gateway**.

Uma observação é descartada em silêncio a menos que se verifique tudo isto:

| Barreira | Regra |
|---|---|
| Identidade | o MAC do sensor começa por `eec500` |
| Trama | os dados de fabricante começam por `59 00 02 15` e têm pelo menos 25 bytes |
| Auto-verificação | os últimos 3 bytes do payload de 20 bytes repetem o sufixo do MAC |
| Registo | o sensor está registado como dispositivo do tipo `diaper_sensor` |
| Ligação | a ligação gateway↔sensor está activa, e ambos partilham a mesma `company` e o mesmo `licenseId` |
| Duplicados | a mesma trama de 20 bytes não foi já aceite nos últimos 5 s |

A supressão de duplicados é por sensor e por conteúdo da trama, não por gateway. Se
dois gateways ouvirem o anúncio *idêntico* dentro da mesma janela de 5 s, só o
primeiro produz mensagens — incluindo a sua leitura de `proximity`. Uma trama cujas
leituras mudaram passa imediatamente, e é por isso que a cadência observada é
irregular em vez de um intervalo limpo de 5 s.

A trama original não se perde: continua disponível no tópico `raw` do gateway,
`{prefixo}/{company}/{licenseId}/gateway/{macDoGateway}/raw`.

---

## 2. Tópicos

```text
{prefixo}/{company}/{licenseId}/diaper_sensor/{macDoSensor}/telemetry
{prefixo}/{company}/{licenseId}/diaper_sensor/{macDoSensor}/events
```

| Segmento | Significado | Exemplo real |
|---|---|---|
| `{prefixo}` | **`havicare-hub` em produção, `havicare-hub-dev` em desenvolvimento** | `havicare-hub` |
| `{company}` | identificador do cliente, ou o literal `null` quando não está atribuído | `hitcare` |
| `{licenseId}` | id público da licença em texto, `0` quando não está atribuído (dispositivos só de administração) | `1001` |
| `diaper_sensor` | tipo de dispositivo, fixo | `diaper_sensor` |
| `{macDoSensor}` | MAC do sensor, hexadecimal minúsculo e sem separadores — é o mesmo valor que `device.id` | `eec5000202f9` |
| `{kind}` | `telemetry` ou `events` | `telemetry` |

Exemplo completo de produção:

```text
havicare-hub/hitcare/1001/diaper_sensor/eec5000202f9/telemetry
```

Entrega:

| | QoS | Retido |
|---|---|---|
| `telemetry` | 0 | não |
| `events` | 1 | não |

Nada é retido nestes tópicos, portanto **um subscritor novo não vê nada até à próxima
observação**. Não há estado anterior para ler do MQTT; para isso existe a API REST. Na
prática a espera é inferior a um minuto por capacidade, desde que o sensor esteja ao
alcance de um gateway ligado a ele.

Não existe tópico `diaper_sensor/.../status`. A presença do sensor não é acompanhada —
só os gateways têm estado `online`/`offline`. Se um sensor ficar em silêncio, sabe-se
pela mensagem de `proximity` a passar a `unknown`, ou pela ausência de telemetria.

---

## 3. Envelope

Todas as mensagens dos dois tópicos usam o mesmo envelope. Só `type` e `data` variam.

```json
{
  "type": "diaper_condition",
  "data": { "state": "attention" },
  "schemaVersion": 2,
  "occurredAt": "2026-08-21T10:35:10Z",
  "device": {
    "id": "eec5000202f9",
    "supplier": "MONIT",
    "model": "MECS-PRO",
    "commercialName": "MONIT MECS Pro"
  },
  "source": {
    "protocol": "monit-mecs-pro-ble",
    "nativeType": "manufacturer_data",
    "gatewayId": "c5e390f30bce",
    "rssiDbm": -86
  }
}
```

| Campo | Notas |
|---|---|
| `schemaVersion` | `2` na telemetria, `1` nos eventos. Sobe quando um campo muda de significado — ver secção 6 |
| `type` | o nome da capacidade ou do evento. A secção 4 lista os valores |
| `occurredAt` | UTC, RFC 3339, **precisão ao segundo**. É o relógio do hub no momento da publicação, não o do sensor — o beacon não transporta data |
| `device.id` | identidade canónica, igual ao `{macDoSensor}` do tópico |
| `device.commercialName` | omitido quando o modelo não está no catálogo |
| `source.gatewayId` | qual o gateway que ouviu este anúncio. Presente em todas as mensagens |
| `source.rssiDbm` | sinal nesse gateway. Omitido quando o gateway não o reportou |
| `data` | específico da capacidade, ver secção 4 |

A ordem das chaves no JSON não é estável — o `type` e o `data` aparecem primeiro hoje,
mas isso não é uma garantia. Ler sempre por chave.

Duas mensagens da mesma observação partilham o `occurredAt` **e** o
`source.gatewayId`. Esse par é a chave de junção se for preciso correlacionar
capacidades.

---

## 4. O que é publicado

### 4.1 Telemetria — cinco valores de `type`

Os cinco chegam ao **mesmo** tópico `telemetry`. Os wildcards do MQTT não filtram pelo
payload, logo escolher uma capacidade é sempre uma verificação do `type` no cliente.
Isto é o mais importante a retirar deste documento quando só se quer o estado da
fralda.

#### `battery`

```json
{ "percent": 83 }
```

Inteiro. Amostra real: 83, e 82 um minuto depois.

#### `diaper_moisture` — o detalhe da MONIT

Os dez canais capacitivos, em bruto e normalizados.

```json
{
  "channels": [
    {"index": 1,  "baseline": 1,  "value": 2,  "delta": 1},
    {"index": 2,  "baseline": 1,  "value": 3,  "delta": 2},
    {"index": 3,  "baseline": 1,  "value": 3,  "delta": 2},
    {"index": 4,  "baseline": 1,  "value": 4,  "delta": 3},
    {"index": 5,  "baseline": 32, "value": 36, "delta": 4},
    {"index": 6,  "baseline": 1,  "value": 4,  "delta": 3},
    {"index": 7,  "baseline": 23, "value": 26, "delta": 3},
    {"index": 8,  "baseline": 28, "value": 32, "delta": 4},
    {"index": 9,  "baseline": 32, "value": 35, "delta": 3},
    {"index": 10, "baseline": 32, "value": 36, "delta": 4}
  ],
  "affectedChannelCount": 0,
  "maximumDelta": 4,
  "requiredChannelCount": 4,
  "wetDelta": 12
}
```

| Campo | Significado |
|---|---|
| `index` | número do canal a começar em 1, sempre 1…10 neste modelo |
| `baseline` | a referência de seco do canal, tal como o sensor a reporta. Vai de 0 a 63 e varia muito de canal para canal (1 contra 32, acima) — é normal, é uma calibração por eléctrodo e não uma leitura |
| `value` | leitura capacitiva actual em bruto, de 0 a 63 |
| `delta` | `max(value - baseline, 0)`. **É o único número do canal que vale a pena mostrar a alguém** |
| `affectedChannelCount` | quantos canais têm `delta` igual ou superior ao `wetDelta` |
| `maximumDelta` | o maior `delta` dos dez canais |
| `requiredChannelCount` | quantos canais molhados obrigam a muda neste sensor |
| `wetDelta` | o `delta` a partir do qual um canal conta como molhado neste sensor |

Os dois últimos são configuráveis **por sensor** e viajam com a leitura pela mesma
razão que o `alertIndex` viaja com o nível: quem mostra "3 de 4 canais afetados"
precisa do 4, e por serem por sensor já não o pode escrever fixo. Ler sempre do
payload. Os valores por omissão, 4 e 12, correspondem ao perfil intermédio dos três
que existem, e são o comportamento de qualquer sensor a que ninguém tenha mexido.

Esta mensagem é o detalhe do fornecedor e não o contrato genérico. Outra marca de
medidor de fraldas terá outra contagem de canais, ou uma leitura única e nenhum canal.
Construir interface sobre ela só onde se queira dizer especificamente "a vista de
canais do MONIT MECS-PRO".

#### `diaper_moisture_level` — o nível genérico

```json
{ "index": 29, "alertIndex": 40 }
```

| Campo | Significado |
|---|---|
| `index` | inteiro de 0 a 100, quanta humidade o sensor está a ver |
| `alertIndex` | o valor a partir do qual o estado passa a `change_required`. Hoje é 40, mas **ler do payload e nunca escrever 40 em hardcode** |

**O `index` não é uma percentagem física e não pode ser apresentado como tal.** O
sensor mede capacitância contra uma linha de base de seco; não há calibração para
volume, nem referência de fralda saturada, nem absorvência por marca. O que o número
expressa é a distância entre o seco e o limiar de muda, comparável entre leituras do
mesmo sensor. Rotular como "nível" ou desenhar um indicador, nunca "43% cheia".

O número é monótono com o estado: nunca contradiz o `diaper_condition`. A derivação
está na secção 5.

É esta a capacidade sobre a qual construir qualquer coisa que tenha de servir mais do
que um fornecedor: todos os medidores de fraldas têm um "quão húmido, 0-100", só a
MONIT tem dez canais.

#### `diaper_condition` — o estado

```json
{ "state": "attention" }
```

Exactamente três valores, e este conjunto está congelado:

| `state` | Significado |
|---|---|
| `clean` | seca |
| `attention` | húmida em algum ponto, ainda não é muda |
| `change_required` | precisa de ser mudada |

#### `proximity` — sinal para um gateway

Publicado por observação aceite, para cada par (sensor, gateway).

```json
{
  "gatewayId": "c5e390f30bce",
  "state": "measured",
  "rssiDbm": -83,
  "rssiMaxDbm": -83,
  "rssiMedianDbm": -83,
  "rssiMinDbm": -83,
  "samples": 1,
  "windowSeconds": 5
}
```

| Campo | Significado |
|---|---|
| `gatewayId` | o gateway a que esta leitura se refere. Também aparece em `source.gatewayId` |
| `state` | `measured`, ou `unknown` quando o par ficou em silêncio |
| `rssiDbm` | a leitura em bruto desta observação |
| `rssiMaxDbm` / `rssiMedianDbm` / `rssiMinDbm` | estatísticas sobre uma janela deslizante, no máximo 10 amostras em `windowSeconds` |
| `samples` | sobre quantas leituras as estatísticas foram calculadas. Muitas vezes é 1 — não tratar as estatísticas como suaves |
| `windowSeconds` | comprimento da janela, 5 s hoje |

Quando um par fica em silêncio durante 30 s, o hub emite, uma única vez:

```json
{ "gatewayId": "c5e390f30bce", "state": "unknown", "samples": 0 }
```

**`unknown` não é `far`.** Fora de alcance, bateria esgotada, gateway offline e um
filtro demasiado restritivo são indistinguíveis entre si e de não estar lá ninguém.
Nesta forma os restantes campos de RSSI não existem.

Porque são três estatísticas e não uma: passar a andar depressa por um gateway são só
uma ou duas leituras, e uma mediana nunca se mexe para isso — usar `rssiMaxDbm` para
apanhar uma passagem. O ruído é assimétrico, porque corpos e paredes atenuam e quase
nada amplifica — usar `rssiMedianDbm` para julgar presença sustentada.

O hub reporta o sinal; não tem opinião sobre perigo. Os limiares, a histerese, o tempo
de permanência, que gateway conta como porta e o que o alarme faz são todos do cliente.

O `proximity` é a mais recente das cinco e **ainda não está declarada no catálogo de
capacidades do hub** — aparece no MQTT mas não na lista de capacidades de telemetria
que a API REST devolve. Tratar a sua presença como provisória; a forma é estável.

### 4.2 Eventos — um valor de `type`

#### `change_required`

```json
{
  "schemaVersion": 1,
  "type": "change_required",
  "occurredAt": "2026-08-21T10:35:10Z",
  "device": {
    "id": "eec5000202f9",
    "supplier": "MONIT",
    "model": "MECS-PRO",
    "commercialName": "MONIT MECS Pro"
  },
  "data": { "previousState": "attention" },
  "source": {
    "protocol": "monit-mecs-pro-ble",
    "gatewayId": "c5e390f30bce"
  }
}
```

(Construído — o sensor real ficou em `attention` durante toda a janela de captura.)

Notar que o envelope do evento é `schemaVersion: 1` e que o seu `source` transporta
apenas `protocol` e `gatewayId` — sem `nativeType` e sem `rssiDbm`.

Semântica:

- Publicado **na transição para** `change_required`, uma vez. Não se repete enquanto o
  estado se mantiver, por muitas observações que cheguem.
- `previousState` é `clean`, `attention`, ou `null`.
- **`null` significa que o hub não tinha estado anterior para este sensor** — uma
  primeira observação de sempre, ou a primeira depois de o hub ter perdido o estado
  guardado. É uma transição como outra qualquer e tem de levantar o alarme: um sensor
  cuja primeiríssima leitura já pede muda é exactamente o caso que não se pode engolir.
- Sair de `change_required` não produz evento. Para limpar um alarme, seguir a
  telemetria `diaper_condition`.

Voltar de `clean` ou `attention` a `change_required` dispara outra vez.

---

## 5. Como o estado e o nível são derivados

O cliente não precisa de reimplementar nada disto — está documentado para que os
números sejam explicáveis, não para serem copiados.

Três limiares governam tudo, e dois deles vêm na própria leitura:

| Limiar | De onde vem | No preset Normal | Papel |
|---|---|---|---|
| canal molhado | `wetDelta` | 12 | um canal com este `delta` conta como molhado |
| máximo de seco | derivado: `intdiv(wetDelta, 4) + 1` | 4 | abaixo disto em *todos* os canais a fralda está seca |
| canais para muda | `requiredChannelCount` | 4 | tantos canais molhados obrigam a muda |

São política do hub, configuráveis por sensor, e viajam com a leitura precisamente para
não serem copiados — ver a secção 6. A coluna do meio é só o preset Normal, com o qual
corre um sensor a que ninguém mexeu.

Estado:

```
maximumDelta < intdiv(wetDelta, 4) + 1        → clean
affectedChannelCount >= requiredChannelCount  → change_required
caso contrário                                → attention
```

A assimetria é deliberada e vale a pena compreender: um canal com `delta` entre o máximo
de seco e o `wetDelta` menos um — 4 e 11 no preset Normal — não é "afectado", mas é
suficiente para deixar de ser `clean`. A amostra real acima tem
`maximumDelta: 4` e `affectedChannelCount: 0`, e é por isso que o estado é `attention`
e não `clean`.

Nível (`diaper_moisture_level.index`):

1. A saturação é a média, sobre os dez canais, de `min(delta / wetDelta, 1)` — na prática
   "quantos dos dez canais valem por um canal molhado", em fracção.
2. Essa saturação é colocada dentro da banda que pertence ao estado já decidido acima,
   e é isso que garante que o número e o badge nunca se contradizem no ecrã.

| Estado | Banda |
|---|---|
| `clean` | 0–25 |
| `attention` | 25–39 |
| `change_required` | 40–100 |

Em `clean` a aritmética cai dentro da banda para qualquer `wetDelta` configurado, e é
exactamente para isso que serve a divisão por 4 no máximo de seco:

- `clean` — todos os deltas ≤ `intdiv(wetDelta, 4)`, cada termo ≤ 0,25, média ≤ 0,25 → ≤ 25
- `change_required` — pelo menos `requiredChannelCount` canais a 1,0, média ≥
  `requiredChannelCount / 10`; no preset Normal isso aterra nos 40, e com um
  `requiredChannelCount` menor é o piso da banda que o sobe aos 40

O `attention` é a única banda reescalada. Ali a saturação vai de quase 0 até
`(wetDelta - 1) / wetDelta` (todos os canais um ponto abaixo de molhado), portanto cortar
em 39 empilhava metade do dia no mesmo valor — e uma fralda em atenção passa lá a maior
parte do tempo, que é justamente quando o número tem de se mexer. Reescalada, dez canais
a rondar o delta 6 dão 32 em vez de bater no tecto.

O custo, dito com clareza: longe do preset Normal o índice comprime-se nos extremos e
várias leituras distintas encostam ao 25 ou ao 40. Perde-se resolução, não correcção — os
dois invariantes que o ecrã vê mantêm-se em qualquer configuração alcançável.

O `attention` acaba em 39 e não em 40 de propósito: o 40 é a marca de alerta no ecrã e
pertence só ao `change_required`.

As bandas existem porque o estado depende de duas estatísticas independentes — o
máximo (há algum sítio molhado?) e a contagem (quão espalhado está?) — e nenhum número
único é monótono com as duas.

---

## 6. O contrato congelado

### Seguro para construir por cima

- A estrutura dos tópicos da secção 2 e os dois tipos, `telemetry` e `events`.
- Os nomes e os significados dos campos do envelope da secção 3.
- Os valores de `type`: `battery`, `diaper_moisture`, `diaper_moisture_level`,
  `diaper_condition`, `proximity`, e o evento `change_required`.
- O `requiredChannelCount` e o `wetDelta` acompanharem sempre a leitura dos canais, e
  serem os limiares realmente aplicados a essa leitura.
- O `diaper_condition.data.state` é exactamente um de `clean`, `attention`,
  `change_required`.
- O `diaper_moisture_level.data.index` é um inteiro de 0 a 100, e o `alertIndex` viaja
  sempre com ele.
- O `change_required` dispara na transição para o estado, com `previousState` possivelmente
  `null`.
- Podem ser **acrescentados** campos novos a qualquer objecto `data` sem aviso. Os
  campos existentes não mudam de significado sem o `schemaVersion` subir. Ler com
  tolerância: ignorar as chaves desconhecidas em vez de falhar.

### Não depender de

- **Os valores dos limiares.** São política do hub, configuráveis por sensor, e podem
  ser afinados à medida que os dados reais se acumulam. Ler o `alertIndex`, o
  `requiredChannelCount` e o `wetDelta` do payload; nunca escrever 40, 4 ou 12.
- **Os limiares serem iguais em todos os sensores.** Dois sensores do mesmo modelo
  podem estar em perfis de sensibilidade diferentes, porque a necessidade varia de
  pessoa para pessoa. Uma leitura idêntica pode dar estados diferentes em dois
  sensores, e isso não é um defeito.
- **Os limiares serem estáveis no tempo.** Podem mudar a qualquer momento por acção
  de quem cuida. Quando mudam, a avaliação seguinte é feita de novo com as regras
  novas, e um `change_required` que passe a aplicar-se gera o seu evento como
  qualquer outro.
- **Dez canais.** O `diaper_moisture` é a forma da MONIT. Um segundo fornecedor terá
  outra, e o `diaper_moisture_level` é o campo que existe para os dois.
- **A cadência e a ordem de chegada.** Ver abaixo.
- **As capacidades chegarem juntas.** Ver abaixo.
- **O `index` como percentagem física de enchimento.** Não é (secção 4.1).
- **O `proximity` estar catalogado.** Está no MQTT mas ainda não na lista de
  capacidades da REST.
- **Precisão abaixo do segundo.** O `occurredAt` tem precisão ao segundo e é o relógio
  de publicação do hub.

### Cadência, medida

Cada capacidade tem a sua própria supressão. O hub calcula uma impressão digital do
`data` por (sensor, capacidade, gateway) e suprime um payload idêntico durante 60 s;
dados que mudaram publicam imediatamente. O `proximity` ignora essa supressão por
completo, porque o seu RSSI mexe-se em cada observação mesmo quando as leituras não
mudam.

Ao longo dos 148 segundos de captura em produção, os intervalos entre mensagens
consecutivas de cada tipo:

| `type` | Contagem | Intervalos (s) |
|---|---|---|
| `proximity` | 18 | 3–23, tipicamente 5–10 |
| `battery` | 4 | 20, 61, 67 |
| `diaper_moisture` | 4 | 11, 63, 16 |
| `diaper_moisture_level` | 2 | 61 |
| `diaper_condition` | 2 | 61 |

Duas consequências para quem consome:

1. **Nunca assumir que duas capacidades chegam juntas.** O `diaper_moisture_level` é um
   inteiro grosseiro que muitas vezes não se mexe entre leituras, portanto chega *menos*
   vezes que o `diaper_moisture`. Guardar o último valor de cada `type` em separado.
2. **A supressão é por gateway.** Com dois gateways ao alcance recebem-se duas cópias da
   mesma leitura, diferindo apenas no `source.gatewayId` e no `rssiDbm`. É intencional —
   cada observação é uma medição distinta. Se não interessar qual o gateway que ouviu,
   deduplicar por `(device.id, type, occurredAt)` ou simplesmente guardar a mais recente.

---

## 7. Consumir do browser através de um WebSocket

### 7.1 Primeiro o broker precisa de um listener WebSocket

A 2026-08-21 o broker em `88.99.104.197` expõe **apenas MQTT em TCP 1883**. Os portos
9001, 8083, 8080, 8084, 8883, 8884 e 443 estão todos fechados, portanto ainda não há
nada a que um browser se possa ligar. Um browser não abre um socket TCP em bruto, logo
isto é uma alteração do lado do broker e não algo que o cliente possa contornar.

O Mosquitto fala MQTT sobre WebSockets nativamente — não é preciso processo intermédio
nem código de relay. Acrescentar à configuração do broker:

```conf
listener 9001
protocol websockets
allow_anonymous false
password_file /etc/mosquitto/passwd
acl_file /etc/mosquitto/acl
```

Criar um utilizador só de leitura em vez de reutilizar o `health-hub`, que pode
publicar:

```sh
mosquitto_passwd -b /etc/mosquitto/passwd viewer '<password>'
```

e, no ficheiro de ACL, dar apenas o que a página precisa:

```conf
user viewer
topic read havicare-hub/+/+/diaper_sensor/+/telemetry
topic read havicare-hub/+/+/diaper_sensor/+/events
```

Depois reiniciar o Mosquitto.

Duas coisas a resolver antes de isto ser alcançável fora da rede local:

- **As credenciais no browser são legíveis por quem abrir a página.** Tudo o que a ACL
  permitir, os visitantes da página podem fazer. Manter a ACL tão estreita como acima, e
  restringi-la a um cliente se a página só servir um.
- **`ws://` é texto simples**, password incluída. Terminar TLS à frente — um nginx a
  encaminhar `wss://` para o 9001 é o menor trabalho, e permite manter o 9001 ligado só
  ao localhost. Só depois disso apontar o cliente para `wss://`.

### 7.2 Subscrever e filtrar

Os wildcards escolhem a fatia grossa, o campo `type` escolhe a capacidade. Ambos são
necessários, porque as cinco capacidades partilham um único tópico.

| Objectivo | Subscrever | Depois filtrar |
|---|---|---|
| estado da fralda de todos os sensores | `havicare-hub/+/+/diaper_sensor/+/telemetry` | `type === "diaper_condition"` |
| tudo de um cliente | `havicare-hub/hitcare/1001/diaper_sensor/+/telemetry` | — |
| um sensor específico | `havicare-hub/+/+/diaper_sensor/eec5000202f9/telemetry` | — |
| só os alarmes de muda | `havicare-hub/+/+/diaper_sensor/+/events` | — |
| o nível, para um indicador | `havicare-hub/+/+/diaper_sensor/+/telemetry` | `type === "diaper_moisture_level"` |
| todos os tipos de dispositivo ao mesmo tempo | `havicare-hub/+/+/+/+/telemetry` | pelo `type`, ou pelo 4.º segmento do tópico |

O `+` corresponde a exactamente um segmento; o `#` corresponde ao resto do tópico e só
é válido no fim. O `diaper_sensor/#` também traz os `events`, o que normalmente é o que
se quer para diagnóstico e raramente o que se quer em código.

### 7.3 Uma página que mostra só o estado da fralda

```html
<script src="/vendor/mqtt.min.js"></script>
<script>
const TOPIC = "havicare-hub/+/+/diaper_sensor/+/telemetry";

const client = mqtt.connect("wss://mqtt.example.com/mqtt", {
    username: "viewer",
    password: "<password>",
    clean: true,
    reconnectPeriod: 5000,
});

client.on("connect", () => client.subscribe(TOPIC, {qos: 0}));

client.on("message", (topic, payload) => {
    const message = JSON.parse(payload.toString());

    // Todas as capacidades partilham este tópico: o filtro fino é sempre no `type`.
    if (message.type !== "diaper_condition") {
        return;
    }

    const [, company, licenseId, , sensorMac] = topic.split("/");
    render({
        company,
        licenseId,
        sensorMac,                       // === message.device.id
        state: message.data.state,       // clean | attention | change_required
        at: message.occurredAt,
        gateway: message.source.gatewayId,
    });
});
</script>
```

Notas que poupam uma tarde:

- Nada é retido, portanto a página fica vazia até à próxima observação — menos de um
  minuto por capacidade com um sensor ao alcance. Semear o estado inicial a partir da
  API REST se um primeiro render vazio não for aceitável.
- Guardar o último valor por `type` num mapa indexado por `device.id`, e não um objecto
  por dispositivo. As capacidades chegam em separado (secção 6).
- `clean: true` significa perder mensagens enquanto se está desligado. É a escolha certa
  para uma vista ao vivo: em tópicos QoS 0 não retidos não há nada para repetir, e uma
  sessão persistente só encheria a fila de leituras velhas.
- Com vários gateways ao alcance, a mesma leitura aparece duas vezes, a diferir no
  `source.gatewayId`. Para mostrar um estado, ficar com a última basta.

### 7.4 Se o broker não puder ser alterado

Se expor o broker não for opção, retransmitir do lado do servidor. Tem mais peças do
que a secção 7.1 e só vale a pena quando o browser não pode guardar credenciais do
broker: o relay autentica o utilizador com a sessão da aplicação, e a password do broker
nunca sai do servidor.

```js
// node relay.js  —  npm i mqtt ws
const mqtt = require("mqtt");
const {WebSocketServer} = require("ws");

const broker = mqtt.connect("mqtt://88.99.104.197:1883", {
    username: "health-hub",
    password: process.env.MQTT_PASSWORD,
});
broker.on("connect", () => broker.subscribe("havicare-hub/+/+/diaper_sensor/+/telemetry"));

const server = new WebSocketServer({port: 8090});
broker.on("message", (topic, payload) => {
    const message = JSON.parse(payload.toString());
    if (message.type !== "diaper_condition") {
        return;
    }
    const frame = JSON.stringify({topic, message});
    server.clients.forEach((socket) => socket.readyState === 1 && socket.send(frame));
});
```

Do lado do browser passa a ser um `new WebSocket(...)` simples, sem biblioteca de MQTT e
sem credenciais. Falta acrescentar autenticação própria no pedido de upgrade — o exemplo
acima não tem nenhuma.

---

## 8. Verificar a partir da linha de comandos

A password do broker não está escrita aqui de propósito; pedir à equipa e exportá-la
como `MQTT_PASSWORD` antes de correr o que segue.

Tudo, o mais cru possível:

```sh
mosquitto_sub -h 88.99.104.197 -p 1883 -u health-hub -P "$MQTT_PASSWORD" \
  -t 'havicare-hub/+/+/diaper_sensor/#' -v
```

Só o estado da fralda, uma linha por leitura:

```sh
mosquitto_sub -h 88.99.104.197 -p 1883 -u health-hub -P "$MQTT_PASSWORD" \
  -t 'havicare-hub/+/+/diaper_sensor/+/telemetry' \
  | jq -rc 'select(.type == "diaper_condition")
            | [.occurredAt, .device.id, .data.state] | @tsv'
```

Que capacidades estão realmente a chegar, e com que frequência:

```sh
mosquitto_sub -h 88.99.104.197 -p 1883 -u health-hub -P "$MQTT_PASSWORD" \
  -t 'havicare-hub/+/+/diaper_sensor/+/telemetry' -W 120 \
  | jq -r .type | sort | uniq -c
```

Para o hub de desenvolvimento, o mesmo broker com o prefixo `havicare-hub-dev`.
