# 26 — Alarmes clínicos

> **Proposta de desenho, por aprovar.** Nada neste capítulo está implementado. O
> contrato em vigor é o do [capítulo 08](08-contrato-mqtt.md#os-tipos); o que aqui
> se propõe só passa para lá depois de aprovado e construído.

## Âmbito

O hub passa a ser o **único** sítio que decide se um acontecimento é `alarm`,
`alert` ou `info`. Continua a ser um normalizador: publica **factos** — que a
SpO2 está baixa, que acabou de estar —, cada um com a sua gravidade. Agrupar,
escalar e fechar alertas é lógica de quem consome, e outro cliente pode querer
outra; o que a Havicare faz está na secção 7, como escolha dela.

O que o contrato garante é que cada medição clínica chega com um limiar decidido
num sítio só, e que o fim de uma condição é publicado como facto, num tipo que
diz o que acabou. O capítulo faz o inventário do que cada aparelho mede e do que
já alarma sozinho, e propõe as configurações, os eventos e a persistência — sem
campos novos no envelope.

## 1. A regra

Acordada com o Hugo:

- **Nativo** quando o aparelho tem limites próprios, **configuráveis**, e
  **reporta a violação ao hub** numa mensagem sua. O firmware pode usar histórico
  ou tendência que o hub não vê, e não se refaz o trabalho dele.
- **Regra do hub** em todos os outros casos. O hub fabrica o alarme a partir da
  telemetria, com uma configuração por dispositivo aplicada por ele — como o
  `proximity_alert` do gateway ([capítulo 10](10-configuracao-de-dispositivos.md#alarme-de-proximidade-do-gateway)):
  guardada como as outras, escrita pelo `PATCH /api/devices/{imei}/configurations`,
  e que nunca viaja para o aparelho.

Daqui saem duas garantias:

- **Um sítio para escrever os limites.** A Havicare escreve sempre no hub, com as
  mesmas chaves, seja o aparelho nativo ou não. Só o hub sabe qual é o caso, e num
  aparelho nativo traduz os limites para a configuração dele.
- **Sem duplicados.** A regra do hub nunca corre sobre uma métrica que o aparelho
  já alarma nativamente.

Uma deteção nativa **fixa** — sem limite que se configure — não entra na regra
como nativa, mas também não se apaga: fica como tipo próprio quando o hub não a
consegue reproduzir a partir da telemetria (a apneia do radar, o alarme de
frequência anormal da 4P Touch).

## 2. Inventário

Por protocolo do [`ProtocolRegistry`](../src/Domain/ProtocolRegistry.php), com
as medições clinicamente relevantes que cada um publica. «Configurável» quer
dizer configurável através do catálogo do hub, salvo nota. As evidências citam o
código e a documentação do fabricante em [`fornecedores/`](fornecedores/): os PDF
pela secção e pela página, o HTML da 4P Touch pela secção.

### Relógios

**Wonlex HW20PRO** (`wonlex-json`)

| Medição | Limite nativo | Configurável | Reporta a violação | Decisão |
|---|---|---|---|---|
| `heart_rate` | Sim: `HROvertopRemind`, `HeartRateBelowRemind` | Sim: `heart_rate_high_alert`, `heart_rate_low_alert` | **Não** | Regra do hub |
| `blood_pressure` (PPG) | Sim: `BPEarlyWarning` | Sim: `blood_pressure_alert` | **Não** | Regra do hub, desligada por omissão (secção 3) |
| `blood_oxygen` | Sim: `bloodOxygenWarn` | Sim: `blood_oxygen_alert` | **Não** | Regra do hub |
| `temperature` | Sim: `TemperatureExceedRemind`, `TemperatureBelowRemind` | Sim: `temperature_high_alert`, `temperature_low_alert` | **Não** | Regra do hub |
| `breath_rate` | Não | — | — | Regra do hub |
| `blood_sugar`, `ecg`, `hrv`, `ppg`, `rr_interval`, `sleep`, `activity` | Não | — | — | Sem limiar (secção 9) |
| queda | `FallWarnSwitch` | Sim: `fall_detection` | **Não** | Indisponível: não há trama nem telemetria de onde a tirar |

Evidência: as definições estão no `deviceConfig` (Wonlex.pdf, p. 33–34: `BPEarlyWarning`,
`HR Overtop Remind`, `bloodOxygenWarn`, `TemperatureExceedRemind`,
`TemperatureBelowRemind`, `HeartRateBelowRemind`) e no catálogo do hub
(`src/Command/Configuration/Definition/WonlexConfigurationDefinitions.php:45-53`,
`src/Domain/Capability/CapabilityCatalog.php:343-348`). As tramas de subida das
medições (p. 16–17: `upHeartRate`, `upBP`, `upBO`, `upBodyTemperature`, `upBS`,
`upBF`, `upUA`, `upBatch`) só trazem o valor. Nenhuma trama de subida do documento
diz que um limite foi passado: os avisos só vibram o pulso. O descodificador
confirma-o (`src/Device/Decoder/WonlexEventDecoder.php:19-42`). O `bloodOxygenWarn`
diz `reminderValue: 90 // Reminder value (minutes)` (p. 34) — a unidade está errada
na ficha, o valor é uma percentagem.

**4P Touch D41, Y6S, D46, D44S** (`four-p-touch`)

| Medição | Limite nativo | Configurável | Reporta a violação | Decisão |
|---|---|---|---|---|
| `heart_rate` | Bit 22 do estado, «Abnormal heart rate alarm», sem valor nem sentido | **Não**: nenhum comando o liga nem o regula | Sim, no `AL` | Regra do hub para `heart_rate_high`/`heart_rate_low`; o `heart_rate_abnormal` fica como tipo próprio |
| `blood_pressure` (PPG) | Não: a ficha diz que a escala é «set on your server» | — | — | Regra do hub, desligada por omissão |
| `blood_oxygen` | Não: «SPO2 data rating set on your server» | — | O servidor responde ao `oxygen` com `1` normal, `0` anormal, `2` erro | Regra do hub |
| `temperature` | A ficha dá ≥ 37,20 °C como alta, sem comando | **Não** | Não: `temp=0` e `temp=1` são sentinelas de medição falhada | Regra do hub |
| queda | Sim | Sim: `fall_detection`, `fall_sensitivity` | Sim, bit 21 | **Nativo** (como hoje) |
| cerca | Bits 18 e 19 | **Não**: o protocolo não tem comando de cerca | Sim | Regra do hub (`safe_zones`, secção 4) |
| relógio retirado | Sim | Sim: `remove_watch_alarm` | Sim, bit 20 | Nativo (como hoje) |
| sem movimento | Bit 4, estado e não alarme | Não | Só como estado | Fora deste capítulo (secção 9) |

Evidência: 4PTouch.html, «III. Heart Rate and Blood Pressure protocol», ponto 3
(`bphrt`) e ponto 4 (`oxygen`, com a nota «HR data rating set on your server»,
«BP data rating set on your server», «SPO2 data rating set on your server»);
«VI. Body Temperature Measure» (`btemp2`: «When temp=0, low temperature abnormal
(measure abnormal) / When temp=1, high temperature abnormal», «≥37.20℃ is high»);
a tabela de bits do estado na trama de posição e de alarme (bit 4 «Device no
moving state», 18–19 cerca, 20 retirado, 21 queda, 22 frequência anormal). No hub:
`src/Protocol/Adapter/FourPTouchAdapter.php:323-330`,
`src/Device/FeatureNormalizer.php:373-376`; a resposta ao `oxygen` é sempre `1`
(`src/Ingress/Tcp/Supplier/FourPTouch/FourPTouchTcpProtocol.php:72`). O
`heart_rate_abnormal` nunca foi visto em produção ([08](08-contrato-mqtt.md#os-tipos)).

**Vivistar VL17, VL16P, L08 Pro** (`vivistar-iw`)

| Medição | Limite nativo | Configurável | Reporta a violação | Decisão |
|---|---|---|---|---|
| `heart_rate` | Não | — | — | Regra do hub |
| `blood_pressure` (PPG) | Não | — | — | Regra do hub, desligada por omissão |
| `blood_oxygen` | Não | — | — | Regra do hub |
| `temperature` (VL16P, L08 Pro) | Não | — | — | Regra do hub |
| `blood_sugar` | Não | — | — | Sem limiar (secção 9) |
| queda | Sim | Sim: `fall_detection`, `fall_sensitivity` | Sim, `AP10` estado `06` | **Nativo** (como hoje) |

Evidência: VIVISTAR.pdf, p. 11–12 (`AP49` frequência, `APHT` frequência e tensão,
`APHP` frequência, tensão, SpO2 e glicemia, `AP50` temperatura); o `AP10` (p. 9–10)
só tem SOS, bateria fraca, queda e uso. No hub:
`src/Device/Decoder/VivistarEventDecoder.php:20-35`. A temperatura por modelo vem
do `model-capabilities.xlsx` (o VL17 não a tem).

### Radar

**Qinglanst HC2** (`qinglanst-radar`)

| Medição | Limite nativo | Configurável | Reporta a violação | Decisão |
|---|---|---|---|---|
| `heart_rate` alta e baixa | Sim, um limiar alto e um baixo, «exceeding the threshold for 1 minute» | Sim: `heart_rate_alert` (cloud, `threshold`) | Sim: estado do minuto no `hbstatics` | **Nativo** |
| `breath_rate` alta e baixa | Sim, idem | Sim: `breath_rate_alert` | Sim, idem | **Nativo** |
| apneia | Fixo: «no breathing signal … for about 20-30 seconds within 1 minute» | **Não** | Sim, idem | Tipo próprio, nativo fixo |
| sinais vitais fracos | Sim: «no heartbeat signal … for 10 consecutive minutes» | Interruptor e duração: `weak_vital_signs_alarm` | Sim, idem | **Nativo** |
| queda, sentado no chão | Sim | Sim: `fall_alarm`, `sitting_on_floor_alarm` | Sim, `position` | **Nativo** (como hoje) |
| saída da cama, permanência, inatividade longa | Sim | Pela cloud | Só pelo *push* HTTP (evento 9), que o hub não recebe | Fora deste capítulo |

Evidência: Cloud2cloud V2.2.3, §3.3.3 (p. 10–11): o byte 13 do `hbstatics` é o
estado do último minuto — respiração `00` normal, `01` hipopneia, `10`
hiperpneia, `11` apneia; frequência `00` normal, `01` baixa, `10` alta, `11`
indefinida; vitais `00` normal, `11` fracos. §5.3.5 `setBreathHeartParam` (p. 39–40):
as condições, os quatro limiares (omissão 24, 90, 8, 50), o `suddenDuration` e
a nota sobre a apneia. §5.3.1 (p. 31): `suddenDeathSwitch`. §2.3 (p. 6–7): os
eventos HTTP. No hub: `src/Ingress/Mqtt/Qinglanst/PayloadDecoder.php:43-62,177-193`,
`src/Ingress/Mqtt/Qinglanst/MessageNormalizer.php:26-39,216-239`,
`src/Command/Configuration/Definition/QinglanstConfigurationDefinitions.php:21-29`.

Duas coisas do fabricante que pesam nesta proposta:

- **O radar diz quando volta ao normal.** O `hbstatics` sai a cada minuto com
  alguém na cama, e um minuto normal traz o estado `00`. O fim de uma condição
  nativa tira-se daí (secção 7).
- **A ficha desaconselha alarmar de imediato o que vem por minuto**, salvo os
  sinais vitais fracos (p. 11), e diz da apneia que menos de cinco por hora é
  normal e que «it is not appropriate to trigger an immediate telephone alarm»
  (p. 39). Hoje o hub publica a apneia como `alarm` e os vitais fracos como `alert`
  — o contrário do que o fabricante recomenda.

Os limiares só valem «when the user enters the monitoring bed and lies down in
single-person mode» (p. 39). Fora da cama de monitorização o radar mede, mas não
alarma — e o hub também não, para não duplicar.

### Pulseiras

**Veepoo MF91** (`veepoo-ble`, fornecedor Wonlex)

| Medição | Limite nativo | Configurável | Reporta a violação | Decisão |
|---|---|---|---|---|
| `heart_rate` | Sim: alarme de frequência, máximo e mínimo | Sim: `heart_rate_alert` | **Não** | Regra do hub |
| `blood_oxygen` | Só o despertar por hipoxia, sem limiar | Interruptor: `blood_oxygen_alert` | **Não** | Regra do hub |
| `temperature` | O firmware anuncia «过高过低提醒» (lembrete de alta e baixa) | Não pela interface do SDK | **Não** | Regra do hub |
| `blood_pressure` (PPG) | Não | — | — | Regra do hub, desligada por omissão |
| `breath_rate` | Não | — | — | Regra do hub |
| `blood_sugar`, `blood_lipids`, `uric_acid`, `stress`, `hrv`, `ecg`, `rr_interval`, `sleep`, `sleep_quality`, `sleep_apnea`, `cardiac_load`, `met`, `activity`, `steps`, `body_composition` | Não | — | — | Sem limiar (secção 9) |

Evidência: SDK uni-app oficial (`HBandSDK/uni-appSDK`,
`docs/VeepooUniAppSDK使用文档.md`), §9.16.2 «心率报警功能 (type=20)»: só ler, ligar
e desligar, com `maxHeartRate` e `minHeartRate`; §9.9.3: `lowOxygen` é um
interruptor («低氧唤醒»); §9.1.1: `bodyTemperatureAlarmType`. A tabela de
callbacks do §10 («type 回调对照总表（权威）») não tem nenhum tipo que o aparelho
mande sozinho a dizer que um limite foi passado — o único «设备主动上报» é o PTT
(2000). No hub: `src/Command/Configuration/Definition/VeepooConfigurationDefinitions.php:47-51`.

**MOKO W6 e W6B** (`moko-w6`, `moko-w6b`) e **PST CF103W** (`tuya-cloud`): sem
sinais vitais. Botão, bateria, movimento e proximidade.

### Aparelhos de medição

| Protocolo | Aparelho | Medições | Limite nativo | Decisão |
|---|---|---|---|---|
| `joytech-bp`, `joytech-bp-ecg` | Tensiómetros HC-6292B e HC-6679B | `blood_pressure`, `heart_rate`, `ecg` (6679B) | Não. Só a bandeira de batimento irregular, fixa | Regra do hub para a tensão e a frequência; o `irregularHeartbeat` fica na telemetria (secção 9) |
| `joytech-et` | Termómetro HC-3018B | `temperature` (ouvido, testa) | Não | Regra do hub |
| `joytech-spo2` | Oxímetro HC-101 | `blood_oxygen`, `heart_rate` | Não | Regra do hub |
| `lefu-torre` | Balanças CF632 e CF621 | `weight`, `body_composition`, `heart_rate` (elétrodos) | Não | Regra do hub para a frequência; o peso sem limiar |

Evidência: `BP bluetooth protocol.xlsx`, trama `0x83`: byte 5 «IHB (0x00: no IHB
0xFF: IHB)», sem campo de classificação; `oximeter-protocol.xlsx`, trama `0x88`: SpO2,
pulso, PI, barra, onda; `IR thermometer-protocol.xlsx`, trama `0x03`: sítio, valor
e hora; [capítulo 22](22-balanca.md) para a balança. Nenhum destes aparelhos
aceita limites nem os tem.

### Sem sinais vitais

`voerka-ncs` (chamadas e reposições), `monit-mecs-pro-ble` (fralda), `moko-mkgw3`,
`moko-mkgw4` e `havicare-ble-gateway` (gateways), `zayata-m228` (dispensador: a
temperatura e a humidade são do ar onde a caixa está, e não de uma pessoa). O
Microlife WatchBP tem deteção de fibrilhação auricular, mas não entra no hub
([README](fornecedores/Microlife/README.md)).

### Onde a leitura da Havicare não bate certo

| Leitura | O que o inventário diz |
|---|---|
| Vivistar não tem sinais vitais | **Tem**: frequência, tensão, SpO2, glicemia e, no VL16P e no L08 Pro, temperatura. Sem limites nativos: regra do hub |
| Radar não manda «de volta ao normal» | **Manda**: o `hbstatics` de cada minuto traz o estado normal. O fim deriva-se dele |
| Radar nativo para a apneia | Nativo **fixo**: não se configura. Fica como tipo próprio, e o fabricante desaconselha alarme imediato |
| Wonlex, Veepoo, 4P Touch, aparelhos de medição | Confirma-se: regra do hub. A 4P Touch tem ainda a cerca, que também passa a regra do hub |
| — | O tensiómetro JoyTech tem uma deteção nativa fixa que a leitura não menciona: o batimento irregular |
| — | A balança também mede frequência cardíaca |

## 3. Que métricas levam limites

Cinco famílias, com os valores por omissão. Os da Havicare ficam onde o texto
não diz o contrário.

| Família | `alert` | `alarm` | Onde |
|---|---|---|---|
| Frequência cardíaca | < 50 ou > 110 bpm | < 40 ou > 130 bpm | todos os que a medem |
| SpO2 | < 94 % | < 90 % | todos os que a medem |
| Tensão arterial | sistólica < 90, ou ≥ 160 / ≥ 100 | ≥ 180 / ≥ 120 | tensiómetros; nos relógios e na pulseira existe, mas desligada |
| Temperatura | ≥ 38,0 °C | ≥ 39,0 °C | todos os que a medem; abaixo de 34,0 °C a leitura não conta |
| Frequência respiratória | < 9 ou > 24 /min | — | radar (nativo), Wonlex, Veepoo |

Onde mudo os valores da Havicare, e porquê:

- **Tensão `alert` a ≥ 160/100 e não a ≥ 140/90.** Nos idosos a hipertensão de
  grau 1 é a regra e não a exceção, e é crónica: um alerta a cada medição acima de
  140 enche a Havicare de alertas sobre um diagnóstico que já se conhece. O grau 2
  justifica olhar; a crise (≥ 180/120) é o alarme, como a Havicare já tinha.
- **Sem alerta pela diastólica < 60.** A diastólica baixa isolada é comum com as
  artérias rígidas da idade. A hipotensão que importa vê-se na sistólica < 90.
- **A tensão de pulso desligada por omissão.** A tensão dos relógios e da
  pulseira é estimada pela onda de pulso (PPG), sem braçadeira. Serve de
  tendência, não de diagnóstico; quem a quiser liga-a por dispositivo.
- **Sem limite baixo de temperatura por omissão.** As leituras de pulso e de testa
  descem com o quarto frio, e a hipotermia não se vê com elas. O campo existe
  para quem o quiser.
- **O piso de 34 °C vale para todas as leituras**, e não só as de pulso: uma testa
  a 33 °C é uma medição falhada, como o `temp=0` da 4P Touch, que hoje sai como
  `bodyCelsius: 0`.
- **A respiração só com `alert`**, nos limites de 3 pontos do NEWS2 (≤ 8 e ≥ 25).
  A respiração que falta é a apneia; uma média de minuto alta não pede alguém já.

A SpO2 por omissão serve a população geral. Uma pessoa com DPOC vive entre 88 e
92 %, e para ela os limites mudam-se no dispositivo — é para isso que são por
dispositivo.

No radar, os 20 e 160 bpm fixos do `EventSeverity` saem: a gravidade passa a
vir dos limites configurados (secção 4).

## 4. A configuração

### Uma chave por família

```text
heart_rate_limits   blood_oxygen_limits   blood_pressure_limits
temperature_limits  breath_rate_limits
```

Uma chave por família, e não um objeto `vital_limits`, por três razões:

- **Cada aparelho declara só as que mede.** O termómetro tem `temperature_limits`
  e nada mais; a balança só `heart_rate_limits`. Num objeto único, o esquema
  mudava por aparelho dentro da mesma chave.
- **O ciclo de configuração é por chave.** Cada uma tem o seu `changeId`, a sua
  revisão e o seu `device.configuration.*`. No radar, uma escrita que fica em fila
  porque ele está desligado não pode prender a dos limites de SpO2 de outro
  aparelho — nem a da respiração do mesmo radar.
- **O catálogo já é por chave**, e o `requestable`, o `isConfigurable` e a matriz
  por modelo leem-se assim.

Nenhuma colide com um evento: o catálogo diz pela chave se ela é acontecimento
(`CapabilityCatalog::isEventType`), e um nome não pode ser configuração num
aparelho e evento noutro. Os `*_limits` não são nome de evento em lado nenhum.

### A forma

Os quatro limites são opcionais; um `null` desliga esse lado. A ordem é
`alarmLow ≤ alertLow < alertHigh ≤ alarmHigh`, e uma escrita que a parta responde
`422`.

| Chave | Campos | Limites | Por omissão |
|---|---|---|---|
| `heart_rate_limits` | `enabled`, `alarmLowBpm`, `alertLowBpm`, `alertHighBpm`, `alarmHighBpm`, `confirmReadings`, `clearMarginBpm` | 20–250 bpm; margem 0–20 | `true`, 40, 50, 110, 130, conforme o aparelho (secção 6), 5 |
| `blood_oxygen_limits` | `enabled`, `alarmBelowPercent`, `alertBelowPercent`, `confirmReadings`, `clearMarginPercent` | 70–99 %; margem 0–5 | `true`, 90, 94, —, 1 |
| `blood_pressure_limits` | `enabled`, `alertLowSystolicMmHg`, `alertHighSystolicMmHg`, `alarmHighSystolicMmHg`, `alertHighDiastolicMmHg`, `alarmHighDiastolicMmHg`, `confirmReadings`, `clearMarginMmHg` | 50–250 mmHg; margem 0–20 | `true` nos tensiómetros e `false` nos relógios e na pulseira, 90, 160, 180, 100, 120, —, 5 |
| `temperature_limits` | `enabled`, `alertLowCelsius`, `alertHighCelsius`, `alarmHighCelsius`, `minValidCelsius`, `confirmReadings`, `clearMarginCelsius` | 30,0–43,0 °C; margem 0–1,0 | `true`, `null`, 38,0, 39,0, 34,0, —, 0,3 |
| `breath_rate_limits` | `enabled`, `alarmLowPerMinute`, `alertLowPerMinute`, `alertHighPerMinute`, `alarmHighPerMinute`, `confirmReadings`, `clearMarginPerMinute` | 0–60 /min; margem 0–5 | `true`, `null`, 9, 24, `null`, —, 2 |

```http
PATCH /api/devices/861265061009822/configurations
{
  "configurations": {
    "blood_oxygen_limits": { "enabled": true, "alarmBelowPercent": 88, "alertBelowPercent": 92 },
    "heart_rate_limits": { "alertHighBpm": 100 }
  }
}
```

Uma chave sem valor guardado devolve os valores por omissão, como as outras
configurações que o hub aplica. Um campo que falta no `PATCH` fica com o que
estava.

### Aplicada pelo hub, ou traduzida para o aparelho

| Aparelho | Papel no catálogo | O que acontece à escrita |
|---|---|---|
| Relógios, Veepoo, tensiómetros, termómetro, oxímetro, balança | `hubSetting` | Fica guardada e confirmada logo, como o `proximity_alert`. Não sai downlink |
| Radar | `setting` | `heart_rate_limits` e `breath_rate_limits` viajam: o `alertLow*` e o `alertHigh*` são o limiar baixo e alto da cloud (`threshold`). Os limites de alarme ficam no hub, que dá a gravidade pela média que o radar reporta. A confirmação é a da cloud, como hoje |

Os `heart_rate_alert` e `breath_rate_alert` do radar **saem** do catálogo: os
`*_limits` substituem-nos com a forma de dois níveis. O `confirmReadings` e o
`clearMargin*` não se aplicam ao radar, que decide a persistência com o minuto
dele — o catálogo do radar não os declara. No radar, `enabled: false` não escreve
nada na cloud: o hub deixa de publicar os eventos dessa família.

Isto obriga a que o «viaja ou não» seja decidido pela capacidade **e** pelo
protocolo, e não só pela capacidade (`HubAppliedCapability`), como hoje.

### O contrato da escrita

**Valores por omissão.** Os da tabela acima, e são os que o `GET
/api/devices/{imei}` devolve em `capabilities.alarms.{key}.value` enquanto nada
foi guardado, com os limites e as unidades no `_meta`, como as outras
configurações.

**Validação.** Uma escrita recusada responde `400 invalid_config`, com a chave da
configuração e o motivo campo a campo no `fields`
([capítulo 09](09-api.md#erros)):

```json
{
  "error": {
    "code": "invalid_config",
    "message": "Invalid configuration for heart_rate_limits",
    "fields": {
      "heart_rate_limits.alertHighBpm": ["Must be greater than alertLowBpm."],
      "heart_rate_limits.clearMarginBpm": ["This value should be between 0 and 20."]
    }
  }
}
```

As regras: cada número dentro dos limites da tabela; a ordem
`alarmLow ≤ alertLow < alertHigh ≤ alarmHigh` entre os que não são `null`; a
margem menor do que a distância entre o limite de alerta e o lado oposto; e, no
radar, os campos que ele não declara recusados em vez de ignorados.

**A confirmação.** É o ciclo de configuração que já existe
([capítulo 10](10-configuracao-de-dispositivos.md), secção 3),
sem estados novos:

| Aparelho | Estado da alteração | `device.configuration.*` |
|---|---|---|
| Regra do hub | `confirmed` logo | `confirmed`, com o `effective` e a `desiredRevision` |
| Radar | `pending_delivery` → `awaiting_ack` → `confirmed` ou `failed`, como a de qualquer configuração da cloud | `confirmed` ou `failed` |

**O que vale enquanto está pendente.** A revisão **efetiva**, inteira: até o radar
confirmar, o hub dá a gravidade pelos limites de alarme da revisão anterior, e
não pelos novos — uma mesma condição nunca é avaliada com metade de uma revisão e
metade de outra. Uma alteração que falha deixa a revisão anterior em vigor. Na
regra do hub a revisão nova vale a partir da leitura seguinte (a configuração lê-se
com cache de 5 s, como a do `proximity_alert`).

**Mudar os limites com uma condição ativa.** O hub reavalia-a contra a última
leitura com a revisão nova. Se ela já cabe no intervalo, com a margem, publica o
`{type}_cleared`; se não cabe, nada muda. Uma mudança de limites **nunca publica**
uma condição nova nem uma gravidade maior: isso pede uma leitura, e não uma
escrita na API. Desligar (`enabled: false`) publica o fim das ativas.

**Os avisos de pulso ficam como estão.** O `heart_rate_high_alert` e os outros da
Wonlex, e o `heart_rate_alert` e o `blood_oxygen_alert` da Veepoo, só vibram o
pulso de quem usa o aparelho. Não são alarmes e não chegam ao hub; continuam a ser
configurações do aparelho, separadas dos limites.

### A cerca: `safe_zones`

Configuração do hub nos relógios (os que publicam `location` com coordenadas).

| Campo | Valores | Por omissão |
|---|---|---|
| `enabled` | `true` · `false` | `false` |
| `zones` | até 5: `id`, `name`, `latitude`, `longitude`, `radiusMeters` (50–5000) | `[]` |
| `confirmReadings` | 1–5 | 2 |

Estar dentro de qualquer zona é estar em segurança. O hub conta uma posição como
**fora** quando, em todas as zonas, a distância menos o `accuracyMeters` passa o
raio, e como **dentro** quando, numa delas, a distância mais o `accuracyMeters`
cabe no raio. Uma posição por antena, com 1 km de incerteza, não prova nada e não
conta.

Os bits de cerca da 4P Touch deixam de ser publicados: a cerca deles é posta
pela aplicação do fabricante, que nada na cadeia da Havicare configura, e
publicá-los ao lado do `safe_zones` era o duplicado que a regra proíbe.

## 5. Os eventos

### As condições de sinais vitais

| `type` | `data` | `severity` | Quem o publica |
|---|---|---|---|
| `heart_rate_high` · `heart_rate_low` | `bpm`, `limitBpm` | `alert` ou `alarm`, pelo limite passado | radar (nativo); hub: relógios, Veepoo, tensiómetros, oxímetro, balança |
| `breath_rate_high` · `breath_rate_low` | `breathsPerMinute`, `limitPerMinute` | idem | radar (nativo); hub: Wonlex, Veepoo |
| `blood_oxygen_low` | `spo2Percent`, `limitPercent` | idem | hub: relógios, Veepoo, oxímetro |
| `blood_pressure_high` · `blood_pressure_low` | `systolicMmHg`, `diastolicMmHg`, `limitSystolicMmHg`, `limitDiastolicMmHg` (o lado que passou) | idem | hub: tensiómetros; relógios e Veepoo se ligado |
| `temperature_high` · `temperature_low` | `bodyCelsius`, `limitCelsius`, `site` quando há | idem | hub: relógios, Veepoo, termómetro |
| `weak_vital_signs` | — | **`alarm`** (era `alert`) | radar |
| `apnea` | — | **`alert`** (era `alarm`) | radar |
| `heart_rate_abnormal` | — | `alert` (sem mudança) | 4P Touch |

O `limit*` é o limite configurado que a leitura passou. Quem consome lê-o do
evento e nunca precisa de saber os limites — é a mesma regra do `alertIndex` da
fralda.

O `source` dos eventos que o hub fabrica é o da leitura que os disparou.

**Os vitais fracos sobem a `alarm`** porque é o alarme a que o fabricante chama
*sudden death* (`suddenDeathSwitch`): dez minutos sem batimento com alguém na
cama de monitorização, e o único estado de minuto que a ficha manda alarmar logo.
**A apneia desce a `alert`** pela nota da ficha. Se a Havicare quiser alarmar
apneias repetidas, o caminho é uma contagem por hora no hub, e não o episódio.

### Os outros que mudam

| `type` | Hoje | Proposta |
|---|---|---|
| `device_state` | `alert` | **`info`**. O desligar e a reposição de fábrica da Wonlex são factos; o que pede alguém é o aparelho calado, e esse é o `device.disconnected` que vem logo a seguir. Alertar nos dois era duplicar |
| `medication_alarm_change` | `alert` numa dose falhada | **`info`** sempre. A dose falhada passa a ser o `dose_missed` |
| `medication_intake` | `alert` com `abnormal` ou `missed` | **`info`** sempre. O `abnormal` é a toma depois de a dose ter sido dada como falhada ([19](19-dispensador-de-comprimidos.md)) |
| `dose_missed` | — | **Novo**, `alert`: `slot`, `scheduledAt`, `doseDate`. Um por dose, quando ela é dada como falhada pela primeira via que chegar: a mudança do alarme para `missed`, `timed_out` ou `retrieval_timed_out`, ou a toma com `result: "missed"`. Hoje a mesma dose dá dois alertas |
| `device.disconnected` | sem `severity` | **`alert`** nos aparelhos contínuos; **`info`** nos que só se ligam para medir (balança, tensiómetro, termómetro, oxímetro), que se desligam no fim de cada medição |
| `device.connected` | sem `severity` | **`info`**, e diz que acabou o `device.disconnected` |

## 6. Persistência e histerese

O radar já decide sozinho: um minuto inteiro acima do limite (§5.3.5). O hub não
lhe junta nada.

Na regra do hub:

- **Abre** com `confirmReadings` leituras seguidas fora do intervalo de alerta.
  Uma leitura dentro recomeça a contagem. A gravidade é a do nível que **todas**
  as leituras da sequência passaram.
- **Por omissão, 1 nos aparelhos de medição** (tensiómetro, termómetro,
  oxímetro, balança): cada medição é feita de propósito, e o próprio aparelho já
  estabilizou o valor. **2 nos relógios e na Veepoo**, cujo sensor ótico dá falsos
  baixos com o pulso mexido.
- **Acaba** com uma leitura dentro do intervalo de alerta por pelo menos a
  margem — numa frequência alta com limite 110 e margem 5, a 105 ou menos. Entre
  105 e 110 a condição continua aberta e não sai nada. A margem é o que impede uma
  frequência a oscilar sobre o limite de abrir e acabar a cada leitura.
- **Sem leitura não é normal.** Um aparelho que deixa de medir, ou que se desliga,
  não acaba a condição. Fica aberta até chegar uma leitura que a acabe.
- **Só contam as leituras novas.** Uma leitura com `fromHistory: true`, ou com o
  `occurredAt` mais de 30 minutos atrás à chegada, não abre nem acaba nada — é
  a memória do aparelho a ser descarregada, e uma condição aberta por ela já
  passou.
- **Uma leitura falhada não conta**: a temperatura abaixo do `minValidCelsius`, os
  zeros que alguns aparelhos mandam por «sem leitura».

- **Uma gravidade maior é um facto novo.** Com a SpO2 baixa já publicada como
  `alert`, as leituras que confirmam o nível de alarme publicam o mesmo `type`
  outra vez, com `severity: alarm`. Ficar no mesmo nível, ou descer, não publica
  nada.

O que o hub guarda para isto — um bit por condição — está na secção 7.

### Repetir a medição

A especificação clínica do Ricardo diz outra coisa: abaixo do limite, pedir uma
medição de repetição, e se ao fim de 5 minutos persistir, criar o alerta. Nos
aparelhos que aceitam pedidos de medição (os relógios e a Veepoo) isso substitui
o `confirmReadings`: a confirmação deixa de esperar pela leitura periódica
seguinte, que pode vir daqui a uma hora.

É comportamento automático do hub — um comando que ninguém pediu — e por isso
**fica por decidir pelo Hugo** (pergunta 6). Se for aprovado, proponho:

- **Nada se publica enquanto se confirma.** Uma leitura por confirmar ainda não
  é um facto.
- **Publica com a leitura de repetição fora do intervalo**, com o `occurredAt` da
  primeira, para o tempo não se perder.
- **Sem resposta em 5 minutos, publica na mesma.** Uma SpO2 de 85 num relógio que
  não responde ao pedido não pode ficar calada: na dúvida, falha para o lado do
  alarme.
- **A repetição dentro do intervalo** descarta a primeira sem publicar nada.

## 7. O fim de uma condição

O hub é um normalizador: publica factos, cada um com a sua gravidade. O que se
faz com eles — juntar repetições, escalar, fechar — é lógica de quem consome, e
outro cliente pode querer outra. O contrato não tem ciclo de vida de alertas nem
campos novos no envelope: tem tipos que dizem que uma coisa acabou, e uma tabela
que diz qual.

### Que evento acaba qual

**A regra:** o hub publica `{type}_cleared` sempre que uma condição que reportou
deixa de ser verdade, seja porque for, e mais nada. Não decide que um evento acaba
outro: isso é ciclo de vida de alertas, de quem consome. Os fins novos têm tipo próprio,
e quem filtra pelo `type` sem os conhecer — o hitCare, um painel — ignora-os, que
é a falha segura. Não levam `severity`: um fim não é grave nem deixa de ser.

**Os pares** existem só onde o próprio aparelho reporta o fim, numa mensagem sua.
Guardam o nome e a gravidade que têm, e o contrato diz o que acabam:

| Este evento | Acaba | Com o mesmo campo do `data` |
|---|---|---|
| `device.connected` | `device.disconnected` | — |
| `zone_entry` com `zone: geofence` | `zone_exit` com `zone: geofence` | — |
| `reset` do NCS | `help_call` | `pagerId` |

Num `{type}_cleared` vai o mesmo campo do `data` que distingue o `{type}`, quando
há (o `fault` do `device_fault`, por exemplo).

O resto do envelope fica como hoje: `type`, `occurredAt`, `device`, `data` e a
`severity`.

**Os repetidos da QoS 1** reconhece-os quem consome pela chave dispositivo +
`type` + `occurredAt`, mais o campo do `data` que distingue quando há
(`personIndex`, `areaId`, `pagerId`…). O limite, numa linha: dois factos reais do
mesmo `type` no mesmo segundo e no mesmo dispositivo juntam-se num — o
`occurredAt` é ao segundo, e uma rajada de toques da W6B por um gateway que envia
em lote dá isso, com a contagem no `presses`.

### Um exemplo

Um relógio 4P Touch, `blood_oxygen_limits` por omissão (alerta abaixo de 94,
alarme abaixo de 90, `confirmReadings` 2), leituras de 15 em 15 minutos: 92, 91,
88, 87, 89, 93, 96.

O 91 confirma o 92 — SpO2 baixa:

```json
{
  "type": "blood_oxygen_low",
  "severity": "alert",
  "occurredAt": "2026-10-23T02:15:00Z",
  "device": { "id": "861265061009822", "supplier": "4P Touch", "model": "D41" },
  "data": { "spo2Percent": 91, "limitPercent": 94 },
  "source": { "protocol": "four-p-touch", "nativeType": "oxygen" }
}
```

O 87 confirma o 88 no nível de alarme — o mesmo facto, mais grave, num evento
novo do mesmo `type`:

```json
{
  "type": "blood_oxygen_low",
  "severity": "alarm",
  "occurredAt": "2026-10-23T02:45:00Z",
  "device": { "id": "861265061009822", "supplier": "4P Touch", "model": "D41" },
  "data": { "spo2Percent": 87, "limitPercent": 90 },
  "source": { "protocol": "four-p-touch", "nativeType": "oxygen" }
}
```

O 89 e o 93 não publicam nada: continua baixa, e descer de nível não é um facto
novo. O 96 está no intervalo com a margem (≥ 95):

```json
{
  "type": "blood_oxygen_low_cleared",
  "occurredAt": "2026-10-23T03:45:00Z",
  "device": { "id": "861265061009822", "supplier": "4P Touch", "model": "D41" },
  "data": { "spo2Percent": 96 },
  "source": { "protocol": "four-p-touch", "nativeType": "oxygen" }
}
```

O `occurredAt` é o da leitura que decidiu: a que confirmou, ou a que voltou ao
intervalo.

### O que o hub guarda

Um bit por (dispositivo, `type`, e o campo do `data` que distingue, quando há): ativo ou não. Serve para o hub só
publicar um `_cleared` depois de ter publicado o que ele acaba, e para não
repetir o mesmo nível enquanto dura. É o mesmo género de estado que já tem a
transição da fralda e a da bateria fraca, e fica no Redis — onde a correção
**e** da secção 11 o põe também para o radar, o dispensador e a bateria.

O bit muda, e publica-se o fim, também quando deixa de haver quem vigie:

- **Uma família de limites desligada** (`enabled: false`) publica o `_cleared` do
  que estava ativo, e **limites mudados** publicam-no se a última leitura já cabe
  no intervalo novo. Uma escrita na API nunca publica um evento que não seja fim.
- **Um dispositivo reassociado** publica o `_cleared` do que estava ativo no
  tópico do dono antigo, e esquece o bit: o dono novo não herda nada, e se a
  pessoa continuar fora do intervalo o facto sai outra vez, pelas leituras dele.
- **Um dispositivo apagado** publica os fins antes de desaparecer.

### Duas condições seguidas

Uma frequência que salta de 130 para 45 entre duas leituras publica
`heart_rate_high_cleared` e `heart_rate_low`, com o mesmo `occurredAt`. A fralda que passa
de húmida a suja publica `check_required_cleared` — húmida deixou de ser verdade —
e `change_required`. Se isso é um alerta ou dois, decide quem consome.

### Fins perdidos

O `events` é QoS 1, e um `_cleared` pode perder-se — um consumidor em baixo, uma
sessão que caiu. Aceita-se por agora: o alerta fica aberto até alguém o fechar,
que é o lado seguro. Se isso doer, acrescenta-se depois uma fotografia de «o que
está ativo agora» por dispositivo, a partir do mesmo bit.

### O que a Havicare faz com isto

Escolha dela, e não contrato: qualquer evento quer dizer que aconteceu alguma
coisa, e abre um alerta ou junta-se ao aberto do mesmo dispositivo e `type`; um
evento que acaba outro, pela tabela «Que evento acaba qual», fecha sozinho o
alerta aberto desse outro, com o mesmo campo do `data` quando há. A subida de alerta para alarme chega-lhe como
mais um evento do mesmo `type`, e é uma repetição com gravidade maior. Outro
consumidor pode fazer outra coisa com os mesmos factos.

### Vários do mesmo tipo no mesmo dispositivo

Um dispositivo do hub representa às vezes vários emissores ou várias pessoas: o
W812 do NCS serve vários pagers, o radar vê várias pessoas, a mesma pulseira
passa por várias portas com alarme de proximidade, o dispensador tem nove doses e
cinco avarias possíveis. O envelope não ganha campo para isso: o que os distingue
já está no `data` de hoje, e fica onde está.

| Eventos | Campo do `data` |
|---|---|
| `help_call` e `reset` do NCS | `pagerId` |
| `fall`, `zone_entry`, `zone_exit` do radar | `personIndex` — fraco: o radar re-identifica as pessoas |
| `proximity_alarm` | `gatewayId` |
| `device_fault` e o seu `_cleared` | `fault` |
| `dose_missed`, `medication_alarm_change`, `medication_intake` | `slot` e `doseDate` |

Quem consome usa-os para não juntar o quarto 15 à chamada do quarto 12, e para
casar um fim com o que ele acaba: um `reset` acaba o `help_call` do mesmo
`pagerId`, um `device_fault_cleared` o `device_fault` do mesmo `fault`.

### O desligado atrás de um gateway

Um aparelho retransmitido — as pulseiras W6 e W6B e o sensor de fralda atrás de um
MOKO, a Veepoo e os aparelhos de medição atrás do gateway da Havicare — dá-se por
desligado quando nenhum gateway o ouve. Quando é o gateway que se cala, dez
pulseiras dão dez `device.disconnected` vinte minutos depois, por uma causa só.

O `device.disconnected` de um aparelho retransmitido passa a levar:

| Campo | Quando | O que é |
|---|---|---|
| `data.gatewayId` | sempre, num aparelho retransmitido | O último gateway que o ouviu. Ouvido por vários, o último de todos |
| `data.cause: "gateway_offline"` | quando esse gateway já está `offline` no momento em que o aparelho se dá por desligado | A queda é do gateway, e não do aparelho |

```json
{ "type": "device.disconnected", "severity": "alert",
  "device": { "id": "d48c49f7909c", "supplier": "MOKO", "model": "W6B" },
  "data": { "gatewayId": "c5e390f30bce", "cause": "gateway_offline" } }
```

Com isto, quem consome agrupa uma falha de gateway sem conhecer a topologia:
os `device.disconnected` com o mesmo `gatewayId` e `cause: "gateway_offline"`
pertencem ao `device.disconnected` desse gateway. Sem `cause`, o gateway estava
de pé e o aparelho é que deixou de ser ouvido — saiu do alcance, ficou sem bateria
—, e esse é um alerta por si.


### Cada condição e o seu fim

| Condição | O fim | Como o hub sabe |
|---|---|---|
| `heart_rate_high` · `heart_rate_low` (radar) | `heart_rate_high_cleared` · `heart_rate_low_cleared` | O `hbstatics` seguinte com a frequência no estado normal (`00`). O indefinido (`11`) não muda nada |
| `breath_rate_high` · `breath_rate_low` (radar) | `breath_rate_high_cleared` · `breath_rate_low_cleared` | Idem, com a respiração. Uma apneia não acaba uma hipopneia |
| `weak_vital_signs` | `weak_vital_signs_cleared` | Os vitais no estado normal |
| as condições da regra do hub | `{type}_cleared` | A leitura dentro do intervalo, com a margem (secção 6) |
| `zone_exit` com `zone: geofence` | `zone_entry` com `zone: geofence` | `safe_zones` |
| `device.disconnected` | `device.connected` | O ciclo de vida de hoje |
| `check_required` | `check_required_cleared` | A fralda deixa de estar húmida: fica limpa, ou passa a muda |
| `change_required` | `change_required_cleared` | O `diaper_condition` volta a `clean` |
| `low_battery` | `low_battery_cleared` | A bandeira apaga (`LowBatteryTransitions`); no MKGW4, que só manda o alarme, não há fim |
| `device_fault` | `device_fault_cleared`, com o mesmo `data.fault` | O dispensador dá a avaria por apagada |
| `storage_environment` | `storage_environment_cleared` | Idem |
| `help_call` (NCS) | `reset`, com o mesmo `pagerId` | O `key` 0, 1 ou 2 da Voerka |

**Não têm fim** os factos de um instante: `help_call` fora do NCS, `fall`,
`proximity_alarm`, `apnea`, `heart_rate_abnormal`, `device_removed`, os
`zone_entry`/`zone_exit` de divisão e de área, `device_state`, os `medication_*`
e o `dose_missed` — uma dose falhada continua a ser um facto, e a toma tardia
sai como `medication_intake` (`info`), mais nada. O que ela faz ao alerta é de
quem consome.
O `device_removed` da 4P Touch podia acabar pelo bit 3 do estado («watch
state»), mas a ficha não diz o que o bit quer dizer em cada valor.

## 8. Os pontos da Havicare

Da primeira revisão:

| | Proposta da Havicare | Aqui |
|---|---|---|
| a | `device.disconnected` com `alert`, acabado pelo `device.connected` | **Adotado**, com `info` nos aparelhos que se desligam a cada medição |
| b | Um campo, `clears`, em todo o fim | **Retirado** por decisão do Hugo: o nome `{type}_cleared` e a tabela dos pares dizem o mesmo. **Recusado** o fim com o mesmo `type` da abertura |
| c | `device_state` da Wonlex a `info` | **Adotado** |
| d | `change_required` acaba o `check_required` | **Retirado** por decisão do Hugo: o hub publica `check_required_cleared` e `change_required`, e juntá-los é de quem consome |
| — | `dose_missed` por dose, acabado pela toma tardia | **Adotado** o `dose_missed` por dose; **retirado** o fim: a toma tardia é um `medication_intake` (`info`), e o que faz ao alerta é de quem consome |
| — | Cercas como configuração do hub, com `zone_exit` e `zone_entry` | **Adotado** (`safe_zones`) |

Da segunda, depois da decisão do Hugo de manter o hub como normalizador:

| | Pedido | Aqui |
|---|---|---|
| 0 | O fim é um facto, e não uma ordem | **Adotado** |
| 1, 2, 5, 7, 10 | Identidade da condição, fase, ressincronização, prova, versão | **Retirados**: são ciclo de vida de alertas, lógica de quem consome |
| 3 | Instantes marcados | Não é preciso: um instante é o que nunca terá `_cleared` (tabela da secção 7) |
| 4 | Motivo do fim | **Retirado**; o hub publica o fim também quando os limites mudam, se desligam, ou o dispositivo muda de dono |
| 6 | Duplicados | Sem campo novo: quem consome usa dispositivo + `type` + `occurredAt` (secção 7) |
| 8 | Contrato da configuração | **Adotado** (secção 4) |
| 9 | Repetir a medição | Desenhado, **por decidir** pelo Hugo (pergunta 6) |
| — | O `gatewayId`/`cause` do desligado | **Adotado** |

## 9. O que não leva limiar

Um limiar só faz sentido quando uma leitura isolada pede alguém. Estas não:

| Medição | Porquê |
|---|---|
| `weight`, `body_composition` | O que importa é a variação ao longo de dias — a perda de peso, a retenção de líquidos —, e não o valor de uma pesagem |
| `hrv`, `rr_interval`, `stress`, `cardiac_load`, `met` | Tendência por pessoa, sem valor de referência que sirva a toda a gente; o `cardiac_load` nem tem unidade documentada |
| `sleep`, `sleep_quality`, `sleep_state` | Juízo sobre a noite, e não um estado agudo |
| `activity`, `steps` | Tendência; a inatividade longa é uma condição própria (abaixo) |
| `blood_sugar` (relógios, Veepoo) | Estimativa sem picada. Um alarme de hipoglicemia por cima dela dava falsa segurança e falsos alarmes |
| `blood_lipids`, `uric_acid` | Estimativas da pulseira, sem uso agudo |
| `ecg`, `ppg` | Sem interpretação: a da Veepoo é conteúdo pago, que sem contrato vem a zeros ([06](06-normalizacao.md#o-ecg-da-pulseira)) |
| `sleep_apnea` (Veepoo) | Contagens do bloco, também conteúdo pago |
| `irregularHeartbeat` (tensiómetro) | Uma deteção fixa do aparelho, que importa repetida e não numa medição. Fica na telemetria; um evento só se a Havicare o pedir |

Ficam de fora, por não serem sinais vitais, mas com o mesmo desenho à espera: a
**inatividade** (o bit 4 da 4P Touch e o evento 9 do radar, que só chega pelo
*push* HTTP) e a **saída da cama** do radar.


## 10. O hitCare e a compatibilidade

O hitCare lê hoje o NCS, os sensores de fralda, os gateways e as pulseiras
([«Pendente do hitCare»](08-contrato-mqtt.md#pendente-do-hitcare)). **Nenhuma
gravidade nem nenhum `type` que ele lê muda**, e nada do que ele lê hoje desaparece.
Cada mudança, e o que pede a quem já consome:

| Mudança | Onde | Para quem já consome |
|---|---|---|
| `device.disconnected` de um aparelho retransmitido ganha `data.gatewayId` e, com o gateway em baixo, `data.cause` | pulseiras e sensores de fralda do hitCare | Os eventos de ciclo de vida não levavam `data`: **coordenar**, junto com a linha seguinte |
| `device.disconnected` e `device.connected` ganham `severity` | todas, incluindo NCS, gateways e pulseiras do hitCare | Os eventos de ciclo de vida não a levavam: **coordenar** com o hitCare |
| Tipos novos `{type}_cleared` | todas; no hitCare, `check_required_cleared`, `change_required_cleared`, `low_battery_cleared` | Um consumidor que rejeite tipos desconhecidos parte: **coordenar**. O contrato congelado da fralda garante os `type` existentes, não a ausência de novos ([17](17-sensor-de-fralda.md#4-o-contrato-congelado)) |
| A fralda que passa de húmida a suja publica `check_required_cleared` junto com o `change_required` | fralda | Um evento a mais com o mesmo `occurredAt`, de um tipo novo |
| Gravidades que mudam (`apnea`, `weak_vital_signs`, `device_state`, `medication_*`, a frequência do radar) | radar, Wonlex, dispensador | Nenhuma destas famílias é do hitCare |
| Os bits de cerca da 4P Touch deixam de sair | relógios 4P Touch | Não é do hitCare |
| `heart_rate_alert` e `breath_rate_alert` do radar dão lugar a `*_limits` | API do radar | Quebra quem os escreve (pergunta 8) |

A Havicare não tem clientes, e muda pela via mais limpa; o que toca o hitCare entra
na tabela «Pendente do hitCare» do contrato no dia em que for construído, com o
comentário `hitcare:` no código.

## 11. Perguntas em aberto

1. **Desligar com alerta e as ligações que oscilam.** Um relógio que fecha o
   socket e volta em segundos publica um `device.disconnected` e um `device.connected` de cada vez. Proposta:
   num socket fechado, o `device.disconnected` espera 60 s por uma nova ligação
   antes de sair. Muda o que o evento quer dizer hoje.
2. **Os avisos de pulso acompanham os limites?** Com `heart_rate_limits` escrito,
   o hub podia escrever também o aviso da Wonlex e da Veepoo com os mesmos valores,
   para o pulso vibrar quando há alerta. Mais comandos, e um aviso que deixa de
   ser configurável à parte.
3. **A resposta ao `oxygen` da 4P Touch.** O servidor diz ao relógio se a SpO2 é
   normal. Responder `0` abaixo do limite de alerta põe o relógio a mostrá-lo.
4. **A apneia a `alert` e os vitais fracos a `alarm`**, contra o que está hoje.
   E, para a apneia, se se quer uma contagem por hora no hub.
5. **Os valores por omissão** da tensão (alerta a 160/100, sem diastólica baixa) e
   da respiração (9 e 24, sem nível de alarme), que mudam os da Havicare.
6. **Repetir a medição** (secção 6). Recomendo que sim nos aparelhos que aceitam
   pedidos, sem publicar nada enquanto se confirma, e publicando na mesma ao fim
   de 5 minutos sem resposta — mas é o hub a mandar comandos sozinho, e isso é
   decisão tua. Sem ela, fica o `confirmReadings`, e uma SpO2 de 85 num relógio
   espera pela leitura periódica seguinte.
7. **O radar fora da cama.** Uma frequência alta aberta e a pessoa que se levanta:
   o `hbstatics` para e o fim só sai na noite seguinte. Proposta: fica assim,
   porque sem leitura não é normal. A alternativa é o hub publicar o fim por
   falta de leitura.
8. **Os `heart_rate_alert` e `breath_rate_alert` do radar saem.** Quem os escreve
   hoje (as licenças CVP, emeis e CBS na radarconsole) passa a escrever os
   `*_limits`. Confirmar que nenhum cliente além da Havicare os usa.
9. **O `irregularHeartbeat`** do tensiómetro: fica na telemetria, ou passa a
   evento `alert`?
10. **Atraso dos blocos da Veepoo.** A SpO2 e a frequência da Veepoo chegam nos
    blocos de cinco minutos que o gateway relê. Os 30 minutos de idade máxima
    supõem que a releitura é mais frequente do que isso; falta medir.

Duas posições da Havicare, por decidir — não estão adotadas neste desenho:

11. **Todas as regras do hub desligadas por omissão** (`enabled: false`), ligadas
    pela Havicare dispositivo a dispositivo, pelo plano de monitorização do
    cliente. A favor: nada alarma sem alguém ter decidido vigiar aquela pessoa.
    Contra: um dispositivo registado sem plano mede SpO2 de 85 e fica calado, e o
    hitCare, que não tem plano nenhum, nunca receberia um alarme clínico. A minha
    leitura: ligadas por omissão nos aparelhos de medição, que alguém usou de
    propósito; a decisão dos relógios e da pulseira é tua.
12. **A deteção como módulo à parte no hub**, que só lê dados normalizados e não
    sabe de clientes, planos nem horários; as **medições em falta** (uma leitura
    agendada que não chegou) ficam na Havicare, que é quem conhece o horário do
    plano. A minha leitura: concordo com as duas metades. O módulo decide sobre
    leituras e limites; quem sabe do dono é quem publica, e é aí que fica a
    mudança de dono da secção 7 — a deteção não precisa de saber de quem é o
    dispositivo para que o fim saia no tópico do dono antigo.

Uma pergunta de produto, que o código não decide:

13. **Quem recebe um SOS de um dispositivo sem dono.** Um aparelho por associar
    publica em `null/0` ([08](08-contrato-mqtt.md), secção 1), onde nenhum cliente
    subscreve: o botão de uma pulseira registada mas ainda sem licença carrega-se
    e ninguém sabe. Pode ficar assim, ir para a dashboard, ou para um destino da
    Havicare.

### Defeitos à parte

Encontrados ao fazer a matriz de cenários. São correções de código, **sem mudança
de contrato**, e cada uma espera o teu sim antes de se fazer; as que dependem do
aparelho começam por um teste nele.

| | Defeito | Onde | Correção proposta |
|---|---|---|---|
| a | O primeiro toque de uma W6B nova, ou o primeiro depois de 24 h sem toques, é tomado pela linha de base do contador e **não sai**, se a pulseira só anunciar a trama de botão depois de um toque | `src/Ingress/Mqtt/Gateway/RedisObservationStateStore.php:12,52-62`; `src/Ingress/Mqtt/Moko/BraceletNormalizer.php:101-105` | Primeiro um teste no aparelho: se a trama de botão é anunciada sempre, o defeito não existe. Se não, guardar o contador sem prazo, e publicar o primeiro toque visto |
| b | Um `AL` da 4P Touch reenviado — sem resposta, ou depois de o relógio religar — leva a hora de chegada, e um SOS antigo parece novo | `src/Device/TelemetryEnvelope.php:40`; 4PTouch.html, Part I §4 | Usar a hora da trama, dentro de um limite de sanidade face ao relógio do hub |
| c | Uma reentrega do Pulsar depois de uma falha a meio volta a publicar o `help_call` | `src/Ingress/Tuya/TuyaBridge.php:85-104` | Registar no Redis os `messageId` tratados, com prazo acima das duas horas de retenção da Tuya |
| d | Um gateway atrasado que reporta um contador mais baixo inventa um toque da W6B, e o `GET`/`SET` do contador não é atómico | `src/Ingress/Mqtt/Moko/BraceletNormalizer.php:110-111`; `src/Ingress/Mqtt/Gateway/RedisObservationStateStore.php:52-62` | Só aceitar a subida, ou uma descida que seja reinício; a transição numa operação atómica |
| e | O estado do radar, do dispensador e da bateria fraca vive em memória, e um reinício volta a anunciar o que estiver ativo | `src/Ingress/Mqtt/Qinglanst/ActiveDetections.php:15`; `src/Ingress/Tcp/Supplier/Zayata/PillDispenserTcpProtocol.php:83-104`; `src/Device/LowBatteryTransitions.php:13` | Passar o estado para o Redis — que é também onde fica o bit da secção 7 |
| g | Reassociar um dispositivo mantém o estado ativo em memória: uma queda em curso não volta a sair para o dono novo | `src/Api/Services/DeviceService.php:512` só limpa o `status` retido | Esquecer o estado do dispositivo na reassociação |
| h | O «wearing notice» da Vivistar (código `04`) é publicado como `device_removed`, mas pode querer dizer «posto» | `src/Device/FeatureNormalizer.php:371`; VIVISTAR.pdf p. 10 | Teste no aparelho, e corrigir o mapeamento se for o contrário |
