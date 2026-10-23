# 26 — Alarmes clínicos

> **Proposta de desenho, por aprovar.** Nada neste capítulo está implementado. O
> contrato em vigor é o do [capítulo 08](08-contrato-mqtt.md#os-tipos); o que aqui
> se propõe só passa para lá depois de aprovado e construído.

## Âmbito

O hub passa a ser o **único** sítio que decide se um acontecimento é `alarm`,
`alert` ou `info`. A Havicare deixa de classificar: guarda a `severity` como vem e
aplica quatro regras, iguais para qualquer `type`:

| Chega | A Havicare |
|---|---|
| `alarm` | Abre sempre um alerta novo |
| `alert` | Abre um alerta novo, a menos que já haja um aberto do mesmo `type` no dispositivo |
| `info` | Só histórico |
| um evento com `data.clears` | Fecha o alerta aberto desse `type` no dispositivo |

Para isso, cada medição clínica tem de chegar ao contrato com um limiar decidido
num sítio só, e cada condição que pode acabar tem de ter um evento que a feche. O
capítulo faz o inventário do que cada aparelho mede e do que já alarma sozinho, e
propõe as configurações, os eventos, a persistência e o fecho.

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
| `blood_sugar`, `ecg`, `hrv`, `ppg`, `rr_interval`, `sleep`, `activity` | Não | — | — | Sem limiar (secção 8) |
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
| cerca | Bits 18 e 19 | **Não**: o protocolo não tem comando de cerca | Sim | Regra do hub (`safe_zones`, secção 7) |
| relógio retirado | Sim | Sim: `remove_watch_alarm` | Sim, bit 20 | Nativo (como hoje) |
| sem movimento | Bit 4, estado e não alarme | Não | Só como estado | Fora deste capítulo (secção 8) |

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
| `blood_sugar` | Não | — | — | Sem limiar (secção 8) |
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
  alguém na cama, e um minuto normal traz o estado `00`. O fecho de uma condição
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
| `blood_sugar`, `blood_lipids`, `uric_acid`, `stress`, `hrv`, `ecg`, `rr_interval`, `sleep`, `sleep_quality`, `sleep_apnea`, `cardiac_load`, `met`, `activity`, `steps`, `body_composition` | Não | — | — | Sem limiar (secção 8) |

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
| `joytech-bp`, `joytech-bp-ecg` | Tensiómetros HC-6292B e HC-6679B | `blood_pressure`, `heart_rate`, `ecg` (6679B) | Não. Só a bandeira de batimento irregular, fixa | Regra do hub para a tensão e a frequência; o `irregularHeartbeat` fica na telemetria (secção 8) |
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
| Radar não manda «de volta ao normal» | **Manda**: o `hbstatics` de cada minuto traz o estado normal. O fecho deriva-se dele |
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

O `source` dos eventos que o hub fabrica é o da leitura que os disparou, com
`evaluatedBy: "hub"`; os nativos levam `evaluatedBy: "device"`. A Havicare não
precisa dele; serve a quem audita de onde veio um alarme.

**Os vitais fracos sobem a `alarm`** porque é o alarme a que o fabricante chama
*sudden death* (`suddenDeathSwitch`): dez minutos sem batimento com alguém na
cama de monitorização, e o único estado de minuto que a ficha manda alarmar logo.
**A apneia desce a `alert`** pela nota da ficha. Se a Havicare quiser alarmar
apneias repetidas, o caminho é uma contagem por hora no hub, e não o episódio.

### Escalar

Uma condição aberta como `alert` que passa ao nível de `alarm` publica o mesmo
`type` outra vez, com `severity: alarm` — a Havicare abre um alerta novo, que é o
que um alarme pede. Descer de `alarm` para `alert` não publica nada; a condição
continua aberta até ao fecho.

### Os outros que mudam

| `type` | Hoje | Proposta |
|---|---|---|
| `device_state` | `alert` | **`info`**. O desligar e a reposição de fábrica da Wonlex são factos; o que pede alguém é o aparelho calado, e esse é o `device.disconnected` que vem logo a seguir. Alertar nos dois era duplicar |
| `medication_alarm_change` | `alert` numa dose falhada | **`info`** sempre. A dose falhada passa a ser o `dose_missed` |
| `medication_intake` | `alert` com `abnormal` ou `missed` | **`info`** sempre. O `abnormal` — a toma depois de a dose ter sido dada como falhada ([19](19-dispensador-de-comprimidos.md)) — fecha o `dose_missed` |
| `dose_missed` | — | **Novo**, `alert`: `slot`, `scheduledAt`, `doseDate`. Um por dose, quando ela é dada como falhada pela primeira via que chegar: a mudança do alarme para `missed`, `timed_out` ou `retrieval_timed_out`, ou a toma com `result: "missed"`. Hoje a mesma dose dá dois alertas |
| `device.disconnected` | sem `severity` | **`alert`** nos aparelhos contínuos; **`info`** nos que só se ligam para medir (balança, tensiómetro, termómetro, oxímetro), que se desligam no fim de cada medição |
| `device.connected` | sem `severity` | **`info`**, e fecha o `device.disconnected` |

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
- **Fecha** com uma leitura dentro do intervalo de alerta por pelo menos a
  margem — numa frequência alta com limite 110 e margem 5, a 105 ou menos. Entre
  105 e 110 a condição continua aberta e não sai nada. A margem é o que impede uma
  frequência a oscilar sobre o limite de abrir e fechar a cada leitura.
- **Sem leitura não é normal.** Um aparelho que deixa de medir, ou que se desliga,
  não fecha a condição. Fica aberta até chegar uma leitura que a feche.
- **Só contam as leituras novas.** Uma leitura com `fromHistory: true`, ou com o
  `occurredAt` mais de 30 minutos atrás à chegada, não abre nem fecha nada — é
  a memória do aparelho a ser descarregada, e uma condição aberta por ela já
  passou.
- **Uma leitura falhada não conta**: a temperatura abaixo do `minValidCelsius`, os
  zeros que alguns aparelhos mandam por «sem leitura».

O estado das condições abertas fica no Redis, e não em memória como o
`ActiveDetections` do radar: um reinício do hub não pode voltar a abrir um
alarme que a Havicare já tem — com `alarm`, abria-o outra vez — nem perder o fecho
de uma que estava aberta.

## 7. O fecho

### Um campo: `data.clears`

Todo o evento que fecha uma condição leva `data.clears` com o `type` que fecha.
A Havicare não precisa de conhecer pares: casa pelo campo.

Quando o mesmo dispositivo pode ter várias condições do mesmo `type` abertas ao
mesmo tempo, os dois eventos levam também **`data.conditionKey`**, e a Havicare
casa pelo `type` e pela chave. Sem chave, é uma condição por `type` e dispositivo.
São três casos: as doses (`{doseDate}/{slot}`), as avarias do dispensador (o
`fault`) e as chamadas do NCS (o `pagerId`, porque um NCS serve vários
botões).

### Tipos próprios para fechar, e não o mesmo `type`

A Havicare propôs que um fecho do mesmo tipo leve `clears` com o seu próprio
`type`. **Proponho o contrário: o fecho tem sempre um `type` seu.** Quem filtra pelo
`type` sem conhecer o `clears` — o hitCare, um painel, um consumidor antigo —
leria um `heart_rate_high` que fecha como um que abre, e alarmava no fim da
condição. Um tipo novo é ignorado por quem não o conhece, que é a falha segura.

Os fechos novos chamam-se `{type}_cleared`, com `severity: info`, a leitura que
fechou e o `clears`:

```json
{ "type": "heart_rate_high_cleared", "severity": "info",
  "data": { "bpm": 96, "clears": "heart_rate_high" } }
```

Um nome por condição, e não um `heart_rate_normal` por família: uma frequência que
salta de 130 para 45 entre duas leituras fecha o `heart_rate_high` e abre o
`heart_rate_low`, e chamar «normal» a 45 seria mentir.

Os pares que já existem ficam com os nomes que têm e ganham o campo.

### Cada condição e o seu fecho

| Abre | Fecha | Como o hub sabe |
|---|---|---|
| `heart_rate_high` · `heart_rate_low` (radar) | `heart_rate_high_cleared` · `heart_rate_low_cleared` | O `hbstatics` seguinte com a frequência no estado normal (`00`). O indefinido (`11`) não abre nem fecha |
| `breath_rate_high` · `breath_rate_low` (radar) | `breath_rate_high_cleared` · `breath_rate_low_cleared` | Idem, com a respiração. Uma apneia não fecha uma hipopneia |
| `weak_vital_signs` | `weak_vital_signs_cleared` | Os vitais no estado normal |
| as condições da regra do hub | `{type}_cleared` | A leitura dentro do intervalo, com a margem (secção 6) |
| `zone_exit` com `zone: geofence` | `zone_entry` com `zone: geofence` e `clears: "zone_exit"` | `safe_zones` |
| `device.disconnected` | `device.connected` com `data.clears: "device.disconnected"` | O ciclo de vida de hoje |
| `check_required` | `change_required` com `clears: "check_required"`, ou `check_required_cleared` | A fralda passa a muda, ou a limpa |
| `change_required` | `change_required_cleared` | O `diaper_condition` volta a `clean` |
| `low_battery` | `low_battery_cleared` | A bandeira apaga (`LowBatteryTransitions`), onde o aparelho a apaga; no MKGW4, que só manda o alarme, não há fecho |
| `device_fault` | `device_fault_cleared`, com o `fault` como chave | O dispensador dá a avaria por apagada |
| `storage_environment` | `storage_environment_cleared` | Idem |
| `dose_missed` | `medication_intake` com `result: "abnormal"`, `clears: "dose_missed"` e a chave da dose | A toma tardia do mesmo `slot` e `doseDate` |
| `help_call` (NCS) | `reset` com `clears: "help_call"` e o `pagerId` como chave | O `key` 0, 1 ou 2 da Voerka |

O `change_required` que vem de `attention` fecha o `check_required` no mesmo
evento: uma fralda, um alerta. O `data.clears` convive com a abertura — o
`change_required` é `alarm` e abre o seu, e ao mesmo tempo fecha o anterior.

**O que não tem fecho** é o que acontece num instante: `help_call` fora do NCS,
`fall`, `proximity_alarm`, `apnea`, `heart_rate_abnormal`, `device_removed`. Quem o
fecha é quem foi ver. O `device_removed` da 4P Touch podia fechar-se pelo bit 3
do estado («watch state»), mas a ficha não diz o que o bit quer dizer em cada
valor, e fica por confirmar no aparelho.

### Os pontos da Havicare

| | Proposta da Havicare | Aqui |
|---|---|---|
| a | `device.disconnected` com `alert`, fechado pelo `device.connected` | **Adotado**, com `info` nos aparelhos que se desligam a cada medição |
| b | Um campo, `data.clears`, em todo o fecho | **Adotado**, com a `conditionKey` onde há várias do mesmo tipo. **Recusado** o fecho com o mesmo `type` da abertura |
| c | `device_state` da Wonlex a `info` | **Adotado** |
| d | `change_required` fecha o `check_required` | **Adotado** |
| — | `dose_missed` por dose, fechado pela toma tardia | **Adotado**, com o `abnormal` — e não o `late` — como fecho: no M228 o `late` é a toma depois do aviso e antes de a dose falhar |
| — | Cercas como configuração do hub, com `zone_exit` e `zone_entry` | **Adotado** (`safe_zones`) |

## 8. O que não leva limiar

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

## 9. O hitCare

O hitCare lê hoje o NCS, os sensores de fralda, os gateways e as pulseiras
([«Pendente do hitCare»](08-contrato-mqtt.md#pendente-do-hitcare)). **Nenhuma
gravidade nem nenhum `type` que ele lê muda.** O que toca nessas famílias é
acrescento, e mesmo assim vai coordenado:

| O quê | Família |
|---|---|
| `reset` ganha `data.clears` e `data.conditionKey` | NCS |
| `change_required` ganha `data.clears`; tipos novos `check_required_cleared` e `change_required_cleared` | sensor de fralda |
| `device.disconnected` ganha `severity`; `device.connected` ganha `severity` e `data`, que os eventos de ciclo de vida não tinham | todas |
| tipo novo `low_battery_cleared` | pulseiras, gateways |

O contrato da fralda já admite campos novos no `data` sem aviso
([17](17-sensor-de-fralda.md#4-o-contrato-congelado)); tipos novos e o `data` num
evento de ciclo de vida não estão cobertos por essa garantia.

## 10. Perguntas em aberto

1. **Desligar com alerta e as ligações que oscilam.** Um relógio que fecha o
   socket e volta em segundos abre e fecha um alerta na Havicare de cada vez.
   Proposta: num socket fechado, o `device.disconnected` espera 60 s por uma nova
   ligação antes de sair. Muda o que o evento quer dizer hoje.
2. **Os avisos de pulso acompanham os limites?** Com `heart_rate_limits` escrito,
   o hub podia escrever também o aviso da Wonlex e da Veepoo com os mesmos valores,
   para o pulso vibrar quando a Havicare alerta. Mais comandos, e um aviso que
   deixa de ser configurável à parte.
3. **A resposta ao `oxygen` da 4P Touch.** O servidor diz ao relógio se a SpO2 é
   normal. Responder `0` abaixo do limite de alerta põe o relógio a mostrá-lo.
4. **A apneia a `alert` e os vitais fracos a `alarm`**, contra o que está hoje.
   E, para a apneia, se se quer uma contagem por hora no hub.
5. **Os valores por omissão** da tensão (alerta a 160/100, sem diastólica baixa) e
   da respiração (9 e 24, sem nível de alarme), que mudam os da Havicare.
6. **A confirmação também no alarme?** Hoje proposta igual para os dois níveis.
   Uma SpO2 de 85 num relógio espera pela segunda leitura, que pode vir daqui a uma
   hora. A alternativa é o hub pedir ele próprio uma medição de confirmação — um
   comportamento automático, que só entra com o teu acordo.
7. **O radar fora da cama.** Uma frequência alta aberta e a pessoa que se levanta:
   o `hbstatics` para e a condição fica aberta até à noite seguinte. Proposta:
   fica, porque sem leitura não é normal. A alternativa é fechar com um motivo
   (`reason: "no_reading"`).
8. **Os `heart_rate_alert` e `breath_rate_alert` do radar saem.** Quem os escreve
   hoje (as licenças CVP, emeis e CBS na radarconsole) passa a escrever os
   `*_limits`. Confirmar que nenhum cliente além da Havicare os usa.
9. **A `conditionKey`** é um acrescento à regra da Havicare (casar pelo `type` e
   pela chave). Sem ela, uma toma tardia da dose das 8:00 fechava a dose falhada
   das 20:00.
10. **O `irregularHeartbeat`** do tensiómetro: fica na telemetria, ou passa a
    evento `alert`?
11. **Atraso dos blocos da Veepoo.** A SpO2 e a frequência da Veepoo chegam nos
    blocos de cinco minutos que o gateway relê. Os 30 minutos de idade máxima
    supõem que a releitura é mais frequente do que isso; falta medir.
