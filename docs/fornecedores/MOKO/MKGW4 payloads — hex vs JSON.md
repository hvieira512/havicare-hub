# Tramas do gateway MKGW4 — HEX contra JSON

Conclusões de uma captura ao vivo dos gateways Minew MKGW4 no broker MQTT
`health-hub`.

A ingestão destes gateways do lado do hub está descrita no
[capítulo 05](../../05-gateways-ble.md).

Os dois gateways publicam num tópico com a forma
`havicare-hub/null/0/gw/<mac>/raw` (sufixo `/raw`), mas cada um serializa a sua
trama de maneira diferente:

| Gateway (MAC)      | Formato da trama    | msg_id 3004 (sinal de vida) | msg_id 3070 (varrimento BLE) |
|--------------------|---------------------|------------------------------|------------------------------|
| `c5:e3:90:f3:0b:ce`| Trama HEX binária   | ✓                            | não observado                |
| `d4:8c:49:f7:90:9c`| JSON (UTF-8)        | ✓ (só o sinal de vida)       | ✓                            |

---

## 1. O gateway HEX (`c5e390f30bce`)

### 1.1 Anatomia da trama

Tópico: `havicare-hub/null/0/gw/c5e390f30bce/raw`

A trama é uma sequência de bytes em bruto, codificada em hexadecimal tal como
chega. Disposição observada:

```
ef 30 04 c5 e3 90 f3 0b ce  ...campos do dispositivo...
│  │  │  └─ MAC do gateway, 6 bytes
│  │  └──── msg_id (p. ex. 3004 = estado/sinal de vida, 3089 = GPS/LBS)
│  └─────── byte alto do msg_id
└────────── byte de início da trama
```

### 1.2 msg_id 3004 — estado / sinal de vida

Descodificado pelo script do fabricante
`docs/fornecedores/MOKO/MKGW4-V2.js` (opção "status"). Verificado contra 17
capturas ao vivo (índice de sinal de vida 955 → 971):

- **Tipo de rede / operador**: `FDD LTE`
- **CSQ (sinal)**: ~17–23
- **Bateria**: ~4192–4198 mV
- **IMEI**: `861076082232511`
- **Estrutura dos campos**: campos ao género TLV, `id, len, value` (por exemplo
  `07 07 "FDD LTE"`, `06 0f <IMEI>`).

> **Defeito no script do fabricante**: o campo `operator` descodifica sempre
> vazio porque o `deviceDataArray.slice(deviceDataIndex, paramLength)`
> (MKGW4-V2.js:1538 e :1973) omite o deslocamento `+ deviceDataIndex`, pelo que
> a fatia começa em 0 em vez de começar na posição do campo.

### 1.3 msg_id 3089 — fixação GPS / LBS

- **Tipo de fixação**: LBS (rede móvel, não é GPS por satélite)
- **tacLac** = 48820, **ci (identificador de célula)** = 677940

### 1.4 Usar o descodificador no MQTTX

A causa do "lixo à saída" que se via antes: o MQTTX corre o script sobre a trama
no formato escolhido em **"Received payload decoded by"**. Com o valor por
omissão, **Plaintext**, o MQTTX entrega ao script os bytes em bruto já
estropiados por uma leitura UTF-8, que não há maneira de interpretar como
hexadecimal.

**Correção (é uma definição da interface, não uma alteração de código):** no
MQTTX, pôr o formato da trama recebida em **HEX**. O script passa a receber a
cadeia hexadecimal e o `handlePayload` descodifica bem.

---

## 2. O gateway JSON (`d48c49f7909c`)

Tópico: `havicare-hub/null/0/gw/d48c49f7909c/raw` — a trama é um objeto JSON em
UTF-8.

### 2.1 O envelope

Todas as mensagens têm esta forma:

```json
{ "msg_id": 3004, "device_info": { "mac": "d48c49f7909c" }, "data": ... }
```

`msg_id` observados: **3004** (sinal de vida do gateway, 237×) e **3070**
(varrimento BLE, 3371× na captura).

### 2.2 msg_id 3004 — sinal de vida do gateway

```json
{ "msg_id": 3004,
  "device_info": { "mac": "d48c49f7909c" },
  "data": { "timestamp": 0, "timezone": 0, "net_interface": 1, "wifi_rssi": -54 } }
```

Notas:
- `net_interface` 1 = WiFi; `wifi_rssi` em dBm.
- `timestamp` e `timezone` vieram a 0 em todos os sinais de vida capturados.

### 2.3 msg_id 3070 — varrimento BLE

O `data` é um vetor de até 6 anúncios BLE:

```json
{ "msg_id": 3070,
  "device_info": { "mac": "d48c49f7909c" },
  "data": [
    { "timestamp": 1246, "timezone": 0,
      "adv_data": "0201061afff70455...",
      "rsp_data": "06094a41425834",
      "type_code": 10, "type": "other",
      "rssi": -82, "connectable": 0, "mac": "080020000a04" },
    ...
  ]}
```

Forma de cada anúncio:

| Campo          | O que é                                                                     |
|----------------|-----------------------------------------------------------------------------|
| `timestamp`    | Tempo de vida em segundos — a menos que o gateway tenha relógio sincronizado, e então é epoch em ms |
| `timezone`     | Desvio em segundos face ao UTC (0 na captura)                               |
| `adv_data`     | Hexadecimal das estruturas AD do anúncio (só presente no `type_code` 10)    |
| `rsp_data`     | Hexadecimal das estruturas AD da resposta ao varrimento (só no `type_code` 10) |
| `type_code`    | Identificador de tipo, já decidido pelo gateway (ver 2.4)                   |
| `type`         | O nome desse tipo, legível                                                   |
| `rssi`         | dBm                                                                          |
| `connectable`  | 0 / 1                                                                        |
| `mac`          | MAC do dispositivo captado (12 caracteres hexadecimais, sem separador)      |

### 2.4 Tipos que o gateway já descodifica (sem bytes em bruto)

Nestes tipos é o próprio gateway que descodifica, e o `adv_data` e o `rsp_data`
vêm **ausentes**:

| type_code | type             | Notas |
|-----------|------------------|-------|
| 0         | `ibeacon`        | uuid / major / minor / rssi_1m |
| 1         | `eddystone-uid`  | |
| 2         | `eddystone-url`  | |
| 3         | `eddystone-tlm`  | batt_vol / temperature / adv_count / runtime |
| 5         | `bxp-acc`        | |
| 8         | `bxp-tag`        | |
| 9         | `pir`            | door_status / sensitivity / detection_status / batt_vol / adv_name (p. ex. `MkP167F...`) |

Tudo o resto cai no `type_code` 10, `"other"`, e expõe as estruturas AD em
bruto.

### 2.5 Distribuição dos tipos na captura

| type_code | type            | contagem |
|-----------|-----------------|---------:|
| 10        | other           | 2595     |
| 0         | ibeacon         | 97       |
| 3         | eddystone-tlm   | 20       |
| 1         | eddystone-uid   | 16       |
| 9         | pir             | 10       |
| 5         | bxp-acc         | 3        |
| 2         | eddystone-url   | 2        |
| 8         | bxp-tag         | 1        |

### 2.6 Exemplos de descodificação AD em bruto (type_code 10)

Aparelhos vistos no varrimento (MAC distintos: 309):

- **Jabra**: `rsp_data 06094a41425834` → nome `JABX4`, fabricante `0x04F7`.
- **Sony WH-1000XM4/XM5**: UUID de serviço `0xfe03` mais fabricante `0x012d`.
- **Apple**: fabricante `0x004c`, subtipos `0x10 / 0x0f / 0x05`.
- **MKGW4-F1F7**: outro MKGW4 a anunciar o próprio nome e a versão de firmware
  `V2.0.3` nos dados de serviço `0xaa11`.
- **GR551-a7be**: serviço `0xfbc0`.
- **E2 Pro 2007**: fabricante `0x424e`.

---

## 3. Sensor de fralda MONIT MECS PRO (`ee:c5:00:02:02:f9`)

Aparece 16× nas capturas do 3070 como aparelho não conectável
(`connectable: 0`), com RSSI entre −83 e −97 dBm.

Exemplo de uma entrada:

```json
{ "timestamp": 1017, "timezone": 0,
  "adv_data": "0201041aff5900021535c80410418015dc8200410418415dc8200202f9c3",
  "rsp_data": "0f094d4f4e4954204d4543532050524f",
  "type_code": 10, "type": "other", "rssi": -83, "connectable": 0,
  "mac": "eec5000202f9" }
```

### 3.1 `rsp_data` — o nome do aparelho

`0f 09 4d 4f 4e 49 54 20 4d 45 43 53 20 50 52 4f`
→ tipo AD `0x09` (Complete Local Name), comprimento 15 → **`MONIT MECS PRO`**.

### 3.2 `adv_data` — dados do fabricante

```
02 01 04                     → AD Flags = 0x04 (sem BR/EDR, só LE)
1a ff 59 00 02 15 36 08 04 10 41 80 15 dc 82 00 41 04 18 41 5d c8 20 02 02 f9 c3
└──┬──┘ └──┬──┘ └──────────────────────────────────────────────────────────────┘
 comp.26  fabricante       trama Raw20 do MECSPro (20 bytes) + byte de potência
          0x0059 (Nordic)
```

O identificador de fabricante `59 00` é o **0x0059 (Nordic Semiconductor)** — o
valor por omissão do SDK nRF52. Os 20 bytes a seguir ao `02 15` são a trama
"Raw20", seguidos de um byte de potência de emissão (`c3` = −61 dBm). Os três
últimos bytes da trama repetem a cauda do MAC do aparelho (`02 02 f9`).

### 3.3 Disposição dos bits da Raw20 (segundo o «Monit - BLE decode.docx»)

Os 20 bytes lêem-se como **um fluxo contínuo de 160 bits, do mais significativo
para o menos**:

| Deslocamento | Bits | Campo                   | Notas |
|--------------|------|-------------------------|-------|
| 0–2          | 3    | Tipo de pacote          | 0–7 (tipo 1 na captura) |
| 3–9          | 7    | Bateria                 | 0–127, usado tal e qual como percentagem |
| 10           | 1    | Tipo de alarme          | 0–1 |
| 11–12        | 2    | Força de emissão        | 0–3 |
| 13–15        | 3    | Estado do acontecimento | 0–7 |
| 16–75        | 60   | Linhas de base          | 10 × 6 bits |
| 76–135       | 60   | Leituras do sensor      | 10 × 6 bits |
| 136–159      | 24   | Cauda do MAC            | os últimos 3 bytes do MAC |

Os valores de 6 bits atravessam fronteiras de byte — têm de ser lidos bit a bit,
e não byte a byte.

**Como os dois primeiros bytes se repartem:**

- Byte 0: bits 7–5 tipo de pacote; bits 4–0 os bits 6–2 da bateria
- Byte 1: bits 7–6 os bits 1–0 da bateria; bit 5 tipo de alarme; bits 4–3 força
  de emissão; bits 2–0 estado do acontecimento

### 3.4 A captura descodificada (os dois pacotes observados)

```
packetType = 1   bateria = 88 (pacote A) / 87 (pacote B)   alarme = 0
txStrength = 1   eventStatus = 0

linha de base = 01 01 01 01 32 01 23 28 32 32
leitura       = 01 01 01 01 33 01 23 28 32 32
normalizado   = 00 00 00 00 01 00 00 00 00 00     (max(leitura − base, 0))
```

A cauda do MAC `02 02 f9` dá o MAC completo **`EE:C5:00:02:02:F9`** (o prefixo
`EE:C5:00` é fixo), que bate certo com o MAC do varrimento.

A única diferença entre os dois pacotes estava nos bits da bateria (88 → 87).
**Não** houve mudança de estado do sensor entre eles.

### 3.5 Como se interpreta o sensor

- `Normalizado[i] = max(Leitura[i] − Base[i], 0)`.
- Definições por omissão da aplicação: contagem de canais exigida **4**, limiar
  do sensor **12**.
- Estado 2 (limpa): os dez normalizados abaixo de 4.
- Estado 1: a contagem de canais com valor ≥ limiar atinge a contagem exigida.
- Estado 0: tudo o resto.

Com os normalizados todos entre 0 e 1, os dois pacotes capturados classificam
como **estado 2 = limpa/seca**. O pacote não traz valor de humidade nenhum: o
"suja" é derivado dos canais que se afastam da sua linha de base. O
`PollutionRange` (2–10) e o `PollutionValue` (5–25) são configuráveis, e as
predefinições são: mais alertas (3/7), normal (4/12), menos alertas (7/15).

---

## 4. Os dados em que isto assenta

- `/tmp/mk_hex.log` — captura hexadecimal do `c5e390f30bce` (83× msg 3004, 1×
  msg 3089).
- `/tmp/mk_json.log` — captura JSON do `d48c49f7909c` (3608 mensagens: 237×
  3004, 3371× 3070).
- `/tmp/mk_ble_decode.js` — descodificador das estruturas AD, com relatório de
  aparelhos distintos.
- `/tmp/mecspro.js` — descodificador bit a bit da Raw20 do MECSPro.
- Descodificador do fabricante: `docs/fornecedores/MOKO/MKGW4-V2.js` (tem o
  defeito da fatia do `operator`, ver 1.2).

> Os quatro primeiros ficheiros viveram em `/tmp` durante a análise e já não
> existem. Ficam registados para se saber sobre que material é que estas
> conclusões foram tiradas.
