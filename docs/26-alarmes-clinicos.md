# 26 — Alarmes clínicos

> **Aprovado e construído.**
> O contrato em vigor está no [capítulo 08](08-contrato-mqtt.md#os-tipos) e na
> [normalização](06-normalizacao.md#as-leituras-que-não-contam); este capítulo
> guarda o inventário e as razões.

## Âmbito

O hub é um normalizador. Traduz os aparelhos, publica as leituras e os
acontecimentos, e dá a gravidade (`alarm`, `alert`, `info`) aos acontecimentos
que **o próprio aparelho** levanta. Não guarda lógica de negócio de quem consome
nem ciclo de vida de alertas: abrir, juntar, escalar e fechar alertas é de quem
consome, e o fim de uma condição vê-se no que o hub já publica — a telemetria de
volta ao normal, a fralda limpa, o estado `online`.

Este capítulo diz o que cada aparelho mede e alarma sozinho, o que o hub muda
nas gravidades, e o que acrescenta à telemetria para quem consome poder aplicar
os seus limites sem se enganar.

## 1. A regra

Acordada com o Hugo:

- **O aparelho alarma, o hub classifica.** Um acontecimento que o aparelho
  levanta — uma queda, um SOS, a apneia do radar, a saída da cerca da 4P Touch —
  sai como evento, com a gravidade que o hub lhe dá num sítio só
  (`EventSeverity`).
- **Os limites de quem consome ficam em quem consome.** As leituras já lhe
  chegam todas, e ele fecha os alertas com os mesmos limites com que os abre. Um
  limite de SpO2 ou de temperatura aplicado no hub seria a mesma regra em dois
  sítios. A Havicare já os tem (`apps/api/src/server/alerts.ts`).
- **Uma regra só fica no hub se precisar de dados que só o hub tem.** O
  `proximity_alarm` precisa do RSSI ao vivo de todos os gateways, e por isso
  fica ([capítulo 10](10-configuracao-de-dispositivos.md#alarme-de-proximidade-do-gateway)).
- **Os limites nativos configuram-se como o aparelho os tem.** Onde o aparelho
  alarma sozinho com um limiar que aceita (o radar), esse limiar é configuração
  do aparelho, escrita pela API, e o alarme é dele.

## 2. Inventário

Por protocolo do [`ProtocolRegistry`](../src/Domain/ProtocolRegistry.php), com
as medições clinicamente relevantes que cada um publica, e quem alarma cada uma.
«Quem consome» quer dizer que o aparelho não reporta a violação de nenhum limite,
e o hub publica só a leitura. As evidências citam o código e a documentação do
fabricante em [`fornecedores/`](fornecedores/).

### Relógios

**Wonlex HW20PRO** (`wonlex-json`)

| Medição | Limite nativo | Reporta a violação | Quem alarma |
|---|---|---|---|
| `heart_rate`, `blood_pressure` (PPG), `blood_oxygen`, `temperature` | Sim, mas só vibra o pulso | **Não** | Quem consome |
| `breath_rate` | Não | — | Quem consome |
| queda | `FallWarnSwitch` | **Não**: não há trama de queda | Ninguém |

Evidência: o `deviceConfig` (Wonlex.pdf, p. 33–34: `BPEarlyWarning`,
`HR Overtop Remind`, `bloodOxygenWarn`, `TemperatureExceedRemind`,
`TemperatureBelowRemind`, `HeartRateBelowRemind`); as tramas de subida (p. 16–17)
só trazem o valor (`src/Ingress/Tcp/Wonlex/WonlexEventDecoder.php`). Os avisos de
pulso continuam a ser configurações do aparelho (`heart_rate_high_alert` e as
outras), separadas de tudo o que alarma.

**4P Touch D41, Y6S, D46, D44S** (`four-p-touch`)

| Medição | Limite nativo | Reporta a violação | Quem alarma |
|---|---|---|---|
| `heart_rate` | Bit 22, «Abnormal heart rate alarm», fixo | Sim, no `AL` | `heart_rate_abnormal` nativo (como hoje); o resto, quem consome |
| `blood_pressure` (PPG), `blood_oxygen` | Não: «data rating set on your server» | — | Quem consome |
| `temperature` | Não | Não: `temp=0` e `temp=1` são medições falhadas (secção 5) | Quem consome |
| queda, relógio retirado | Sim, configuráveis | Sim, bits 21 e 20 | Nativo (como hoje) |
| cerca | Bits 18 e 19 | Sim | Nativo: `zone_entry`/`zone_exit` como hoje |

Evidência: 4PTouch.html, «III. Heart Rate and Blood Pressure protocol» e
«VI. Body Temperature Measure»; a tabela de bits do estado. No hub:
`src/Ingress/Tcp/FourPTouch/FourPTouchAdapter.php`, `src/Device/FeatureNormalizer.php`.

**Vivistar VL17, VL16P, L08 Pro** (`vivistar-iw`)

| Medição | Limite nativo | Reporta a violação | Quem alarma |
|---|---|---|---|
| `heart_rate`, `blood_pressure` (PPG), `blood_oxygen`, `temperature` (VL16P, L08 Pro) | Não | — | Quem consome |
| queda | Sim, configurável | Sim, `AP10` estado `06` | Nativo (como hoje) |

Evidência: VIVISTAR.pdf, p. 9–12; `src/Ingress/Tcp/Vivistar/VivistarEventDecoder.php`.

### Radar

**Qinglanst HC2** (`qinglanst-radar`)

| Medição | Limite nativo | Configurável | Reporta a violação | Quem alarma |
|---|---|---|---|---|
| `heart_rate` alta e baixa | Sim, «exceeding the threshold for 1 minute» | `heart_rate_alert` (cloud) | Sim, `hbstatics` | **Nativo** |
| `breath_rate` alta e baixa | Sim, idem | `breath_rate_alert` | Sim, idem | **Nativo** |
| apneia | Fixo | Não | Sim, idem | Nativo fixo |
| sinais vitais fracos | Sim, 10 minutos sem batimento | `weak_vital_signs_alarm` | Sim, idem | **Nativo** |
| queda, sentado no chão | Sim | `fall_alarm`, `sitting_on_floor_alarm` | Sim, `position` | Nativo (como hoje) |

Os limiares do radar ficam como estão: configuração do aparelho, escrita pelo
`PATCH /api/devices/{imei}/configurations` e confirmada pela cloud. O radar alarma
sozinho, e o hub classifica o que ele levanta.

Evidência: Cloud2cloud V2.2.3, §3.3.3 (p. 10–11), §5.3.5 `setBreathHeartParam`
(p. 39–40), §5.3.1 `suddenDeathSwitch` (p. 31). No hub:
`src/Ingress/Mqtt/Qinglanst/PayloadDecoder.php`,
`src/Ingress/Mqtt/Qinglanst/MessageNormalizer.php`. A ficha desaconselha alarmar
de imediato o que vem por minuto, salvo os vitais fracos, e diz da apneia que
«it is not appropriate to trigger an immediate telephone alarm» (p. 11 e 39) —
daí as gravidades da secção 3.

### Pulseiras e aparelhos de medição

| Protocolo | Medições | Limite nativo | Quem alarma |
|---|---|---|---|
| `veepoo-ble` (MF91) | `heart_rate`, `blood_oxygen`, `temperature`, `blood_pressure` (PPG), `breath_rate` | Avisos de pulso, que não chegam ao hub | Quem consome |
| `joytech-bp`, `joytech-bp-ecg` | `blood_pressure`, `heart_rate`, `ecg` | Só a bandeira de batimento irregular, fixa, na telemetria | Quem consome |
| `joytech-et` | `temperature` | Não | Quem consome |
| `joytech-spo2` | `blood_oxygen`, `heart_rate` | Não | Quem consome |
| `lefu-torre` | `weight`, `body_composition`, `heart_rate` | Não | Quem consome |

`moko-w6`, `moko-w6b` e `tuya-cloud` não têm sinais vitais: botão, bateria,
movimento e proximidade. Nem `voerka-ncs`, `monit-mecs-pro-ble`, os gateways e o
`zayata-m228`.

### Onde a leitura da Havicare não bate certo

| Leitura | O que o inventário diz |
|---|---|
| Vivistar não tem sinais vitais | **Tem**: frequência, tensão, SpO2, glicemia e, no VL16P e no L08 Pro, temperatura |
| Radar não manda «de volta ao normal» | **Manda**: o `hbstatics` de cada minuto sai como `vitals_minute_stats`, normal ou não |
| Radar nativo para a apneia | Nativo **fixo**: não se configura |
| — | O tensiómetro JoyTech tem uma deteção nativa fixa: o `irregularHeartbeat` |
| — | A balança também mede frequência cardíaca |

## 3. As gravidades que mudam

Só sobre acontecimentos que o aparelho levanta, ou sobre o ciclo de ligação.

| `type` | Antes | Agora |
|---|---|---|
| `apnea` | `alarm` | **`alert`**, pela nota da ficha do radar |
| `weak_vital_signs` | `alert` | **`alarm`**: é o *sudden death* do fabricante (`suddenDeathSwitch`), dez minutos sem batimento, e o único estado de minuto que a ficha manda alarmar logo |
| `device_state` | `alert` | **`info`**. O desligar e a reposição de fábrica da Wonlex são factos; o que pede alguém é o aparelho calado, e esse é o `device.disconnected` |
| `medication_alarm_change` | `alert` numa dose falhada | **`info`** sempre. A dose falhada vai ser o `dose_missed` |
| `medication_intake` | `alert` com `abnormal` ou `missed` | **`info`** sempre |
| `device.disconnected` | sem `severity` | **`alert`**; **`info`** nos que só se ligam para medir (balança, tensiómetro, termómetro, oxímetro) |
| `device.connected` | sem `severity` | **`info`** |
| `low_battery` | — | `info` em todos — **já em produção** (hub 350cf776) |

A gravidade do `heart_rate_high`/`heart_rate_low` do radar fica como está.

### `dose_missed`

Novo, `alert`. **Um por dose**, quando ela é dada como falhada pela primeira via
que chegar: a mudança do alarme para `missed`, `timed_out` ou
`retrieval_timed_out`, ou a toma com `result: "missed"`. Hoje a mesma dose dá dois
alertas. Sem evento de fim: a toma tardia sai como `medication_intake` (`info`).
A dose é o `slot` com o `doseDate`, o dia local que a mudança do alarme e a toma
também levam; é esse par que impede a segunda via de repetir o alerta.

| `data` | O que é |
|---|---|
| `slot` | O alarme (1–9), o mesmo do plano em `configurations.medication_reminders` |
| `scheduledAt` | A hora da dose, em UTC; falta sem fuso confirmado |
| `doseDate` | O dia da dose no fuso do aparelho (`AAAA-MM-DD`); falta sem fuso confirmado |

```json
{ "type": "dose_missed", "severity": "alert",
  "data": { "slot": 2, "scheduledAt": "2026-10-19T17:00:00Z", "doseDate": "2026-10-19" } }
```

O `slot` e o `doseDate` juntos identificam a dose, e é por eles que quem consome
não junta duas doses diferentes num alerta.

## 4. O desligado

### A espera de 60 s

Um relógio que fecha o socket e volta em segundos publica hoje um
`device.disconnected` e um `device.connected` de cada vez. Com `severity: alert`,
cada oscilação seria um alerta. Num socket fechado, o `device.disconnected`
**espera 60 s** por uma nova ligação antes de sair; se ela chegar, não sai nem
um nem outro.

### O desligado atrás de um gateway

Um aparelho retransmitido — as pulseiras W6 e W6B e o sensor de fralda atrás de um
MOKO, a Veepoo e os aparelhos de medição atrás do gateway da Havicare — dá-se por
desligado quando nenhum gateway o ouve. Quando é o gateway que se cala, dez
pulseiras dão dez `device.disconnected` por uma causa só. O `device.disconnected`
de um aparelho retransmitido passa a levar:

| Campo | Quando | O que é |
|---|---|---|
| `data.gatewayId` | sempre, num aparelho retransmitido | O último gateway que o ouviu |
| `data.cause: "gateway_offline"` | quando esse gateway já está `offline` no momento em que o aparelho se dá por desligado | A queda é do gateway, e não do aparelho |

```json
{ "type": "device.disconnected", "severity": "alert",
  "device": { "id": "d48c49f7909c", "supplier": "MOKO", "model": "W6B" },
  "data": { "gatewayId": "c5e390f30bce", "cause": "gateway_offline" } }
```

Sem `cause`, o gateway estava de pé e o aparelho é que deixou de ser ouvido — saiu
do alcance, ficou sem bateria —, e esse é um alerta por si.

## 5. As leituras que não contam

Quem consome aplica os seus limites a cada leitura. Duas espécies de leitura não
lhe servem, e só o hub as sabe reconhecer: a que não é de agora, e a que não é
uma medição.

### Não é de agora: `fromHistory`

O `fromHistory: true` já existe nos aparelhos de medição para as leituras da
memória ([06](06-normalizacao.md#campo-occurredat)). Passa a valer para **toda**
a telemetria que não é ao vivo:

- lida da memória do aparelho (tensiómetro, termómetro, balança);
- entregue no lote `upBatch` da Wonlex, que traz o que o relógio guardou;
- com a hora do aparelho **mais de 30 minutos antes da chegada**, onde o
  `occurredAt` é a hora do aparelho (os aparelhos de medição, os blocos da
  Veepoo, os alarmes da 4P Touch, a Tuya).

Uma leitura com `fromHistory: true` é verdadeira e entra no histórico de quem
consome, mas um alerta por ela já passou. Sem o campo, a leitura é ao vivo.

### Não é uma medição: os sentinelas saem, o implausível marca-se

- **Um sentinela não sai.** O `temp=0` e o `temp=1` da 4P Touch dizem «medição
  falhada», e um sinal vital a zero de um relógio quer dizer «sem leitura». Não
  são valores, e um consumidor que não conheça o sentinela alarma por eles.
  Deixam de sair como telemetria, como a Veepoo já fazia
  (`src/Ingress/Mqtt/Veepoo/MeasurementNormalizer.php`); ficam no `raw`.
- **Um valor implausível sai com `implausible: true`.** É um número que o aparelho
  mediu, mas que não é de uma pessoa viva: a temperatura corporal abaixo de
  34,0 °C, em qualquer `site` — o pulso e a testa descem com o quarto frio, e a
  hipotermia não se vê com eles.

```json
{ "type": "temperature",
  "data": { "bodyCelsius": 32.6, "implausible": true } }
```

Os dois campos só aparecem a `true`. Para já o `implausible` só se aplica à
temperatura; outra medição entra quando houver um caso visto.

## 6. O estado do dispensador na telemetria

A avaria, o ambiente fora da gama e a chamada do dispensador M228 existem hoje só
como evento, quando acendem, e no `raw`. O heartbeat traz as TAGs a cada vez,
mas nenhuma telemetria leva o estado, e quem consome não sabe quando apagaram.

Passa a sair a telemetria **`dispenser_conditions`** em cada heartbeat que traga as
TAGs, como o `ambient_temperature` e o `ambient_humidity` já saem:

| Campo | TAG | O que é |
|---|---|---|
| `faults` | `0x8121`–`0x8125` | As avarias acesas: `rotation` · `tray_reset` · `pusher` · `cell_door` · `keys`; `[]` sem nenhuma |
| `storageOutOfRange` | `0x8111` | O juízo do aparelho sobre a temperatura e a humidade |
| `helpCallActive` | `0x8112` | Chamada em curso |

```json
{ "type": "dispenser_conditions",
  "data": { "faults": [], "storageOutOfRange": false, "helpCallActive": false } }
```

Um campo cuja TAG não veio no heartbeat não sai. Os eventos `device_fault`,
`storage_environment` e `help_call` ficam como estão: saem uma vez quando acendem.

## 7. Onde se vê o normal

Verificado no código, condição a condição:

| Condição | O que mostra o normal | Fonte |
|---|---|---|
| Sinais vitais dos relógios, Veepoo e aparelhos de medição | A própria leitura em `telemetry`, que quem consome compara com os seus limites | decodificadores de cada protocolo; capítulos 22–25 |
| `heart_rate_*`, `breath_rate_*`, `weak_vital_signs`, `apnea` (radar) | `vitals_minute_stats` com `heartRateStatus`, `breathingStatus`, `vitalSignsStatus` a `normal`, a cada minuto | `src/Ingress/Mqtt/Qinglanst/MessageNormalizer.php` |
| `low_battery` | `battery.lowBattery: false` (4P Touch, Veepoo, dispensador); só o `percent` ou o `voltageMv` nos outros | [06](06-normalizacao.md), «A bateria, campo a campo» |
| `check_required`, `change_required` | `diaper_condition.state: clean` | `src/Ingress/Mqtt/Monit/MonitNormalizer.php` |
| `device.disconnected` | `status` `online` (retido) e `device.connected` | [08](08-contrato-mqtt.md) §5 |
| `zone_exit` da 4P Touch | `zone_entry` | nativo |
| `help_call` do NCS | `reset` com o mesmo `pagerId` | `src/Ingress/Mqtt/Ncs/MessageNormalizer.php` |
| `device_fault`, `storage_environment`, `help_call` do dispensador | `dispenser_conditions` (secção 6) | `src/Ingress/Tcp/Zayata/PillDispenserEventDecoder.php` |
| `dose_missed` | Instante: não há normal | — |

### Vários do mesmo tipo no mesmo dispositivo

O que distingue vários emissores ou várias pessoas no mesmo dispositivo já está
no `data`, e o envelope não ganha campo:

| Eventos | Campo do `data` |
|---|---|
| `help_call` e `reset` do NCS | `pagerId` |
| `fall`, `zone_entry`, `zone_exit` do radar | `personIndex` — fraco: o radar re-identifica as pessoas |
| `proximity_alarm` | `gatewayId` |
| `device_fault` | `fault` |
| `dose_missed`, `medication_alarm_change`, `medication_intake` | `slot` e `doseDate` |

Os repetidos da QoS 1 reconhecem-se por dispositivo + `type` + `occurredAt`, mais
esse campo quando há.

## 8. O hitCare e a compatibilidade

O hitCare lê hoje o NCS, os sensores de fralda, os gateways e as pulseiras
([«Pendente do hitCare»](08-contrato-mqtt.md#pendente-do-hitcare)). **Nenhuma
gravidade nem nenhum `type` que ele lê muda.**

| Mudança | Onde | Para quem já consome |
|---|---|---|
| `device.disconnected` e `device.connected` ganham `severity` | todos | **Coordenar** com o hitCare |
| `device.disconnected` de um retransmitido ganha `data.gatewayId` e `data.cause` | pulseiras e sensores de fralda do hitCare | Os eventos de ciclo de vida não levavam `data`: **coordenar**, junto com a linha anterior |
| O `device.disconnected` espera 60 s num socket fechado | relógios e o que liga por TCP | Muda quando sai, não o que é; não é família do hitCare |
| `apnea`, `weak_vital_signs`, `device_state`, `medication_*` | radar, Wonlex, dispensador | Não são do hitCare |
| `fromHistory`, `implausible`, sentinelas que deixam de sair | sinais vitais | Não são do hitCare |
| `dose_missed`, `dispenser_conditions` | dispensador | Tipos novos |

A Havicare não tem clientes, e muda pela via mais limpa; o que toca o hitCare entra
na tabela «Pendente do hitCare» do contrato no dia em que for construído, com o
comentário `hitcare:` no código.

## 9. Perguntas em aberto

1. **Retirar o evento `low_battery`**, a favor do `battery.lowBattery` na
   telemetria. Antes, confirmar quem o lê: o hitCare não; a dashboard do hub sim
   (`pt-PT.json`, `components/cards/telemetry.js`); falta a Havicare.
2. **A pulseira Tuya demora cerca de 16 h a dar-se por desligada** (dois
   despertares de 8 h falhados, [21](21-pulseira-sos-wifi.md) §3). Problema da
   deteção de ligação, a resolver aí.
3. **Atraso dos blocos da Veepoo.** A SpO2 e a frequência da Veepoo chegam nos
   blocos de cinco minutos que o gateway relê. Os 30 minutos do `fromHistory`
   supõem que a releitura é mais frequente do que isso; falta medir.

## 10. Defeitos encontrados ao fazer a proposta

Correções de código, sem mudança de contrato.

| | Defeito | Estado |
|---|---|---|
| a | O primeiro toque de uma W6B depois de 24 h sem toques perdia-se na linha de base do contador | **Testado e fechado**: não é defeito. Em produção, no `fbd87c59ba8b` sem ninguém a carregar, a pulseira anuncia os três contadores (`bxp-button`, `frame_type` 0, 1 e 2) a cada 4–5 s, e a linha de base não expira enquanto está ao alcance |
| b | Um `AL` da 4P Touch reenviado levava a hora de chegada | **Feito**, em produção: leva a hora da trama |
| c | Uma reentrega do Pulsar voltava a publicar o `help_call` | **Feito**, em produção |
| d | Um gateway atrasado inventava um toque da W6B | **Feito**, em produção |
| e | Um reinício voltava a anunciar as quedas do radar, as avarias do dispensador e a bateria fraca | **Feito**, em produção: o estado vive no Redis |
| g | Mudar o dono de um aparelho não esquecia os alarmes que duram | **Feito**, em produção |
