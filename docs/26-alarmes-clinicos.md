# 26 — Alarmes clínicos

> **Proposta de desenho, por aprovar.** Nada neste capítulo está implementado. O
> contrato em vigor é o do [capítulo 08](08-contrato-mqtt.md#os-tipos); o que aqui
> se propõe só passa para lá depois de aprovado e construído.

## Âmbito

O hub passa a ser o **único** sítio que decide se um acontecimento é `alarm`,
`alert` ou `info`. O hub publica **factos**: que uma condição começou, que ficou
mais grave, que acabou. O que quem consome faz com eles é decisão de quem consome.

A Havicare, que é o primeiro consumidor, já disse o que fará — guarda a
`severity` como vem e, sem classificar nada:

| Chega | A Havicare |
|---|---|
| `alarm` | Abre sempre um alerta novo |
| `alert` | Abre um alerta novo, a menos que já haja um aberto da mesma condição |
| `info` | Só histórico |
| o fim de uma condição | Fecha o alerta que ligou a essa condição |

Isto é da Havicare, e não do contrato: outro consumidor pode escalar de outra
maneira, ou não fechar nada sozinho. O que o contrato garante é que cada medição
clínica chega com um limiar decidido num sítio só, que cada condição tem uma
identidade do princípio ao fim, e que o fim dela é publicado como facto. O
capítulo faz o inventário do que cada aparelho mede e do que já alarma sozinho, e
propõe as configurações, os eventos, a persistência e o ciclo de vida.

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

**Mudar os limites com uma condição aberta.** O hub reavalia as condições abertas
contra a última leitura com a revisão nova. Se ela já cabe no intervalo, com a
margem, a condição acaba com `reason: "limits_changed"`; se não cabe, continua.
Uma mudança de limites **nunca abre nem escala** uma condição: isso pede uma
leitura, e não uma escrita na API. Desligar (`enabled: false`) acaba as abertas
com `reason: "limits_disabled"`.

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

O `source` dos eventos que o hub fabrica é o da leitura que os disparou. Quem
decidiu — o hub ou o aparelho — vai na prova da condição (secção 7).

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
| `medication_intake` | `alert` com `abnormal` ou `missed` | **`info`** sempre. O `abnormal` — a toma depois de a dose ter sido dada como falhada ([19](19-dispensador-de-comprimidos.md)) — acaba o `dose_missed` |
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

Onde vive o estado das condições está na secção 7: não fica em memória como o
`ActiveDetections` do radar, porque um reinício do hub não pode voltar a abrir uma
condição que já estava aberta nem perder o fim de uma.

### Repetir a medição

A especificação clínica do Ricardo diz outra coisa: abaixo do limite, pedir uma
medição de repetição, e se ao fim de 5 minutos persistir, criar o alerta. Nos
aparelhos que aceitam pedidos de medição (os relógios e a Veepoo) isso substitui
o `confirmReadings`: a confirmação deixa de esperar pela leitura periódica
seguinte, que pode vir daqui a uma hora.

É comportamento automático do hub — um comando que ninguém pediu — e por isso
**fica por decidir pelo Hugo** (pergunta 6). Se for aprovado, proponho:

- **Um estado interno, `confirming`, que não se publica.** Uma leitura por
  confirmar ainda não é uma condição. Publicá-la obrigava quem consome a desenhar
  um estado que se resolve sozinho em minutos, e o consumidor que ignorasse o
  estado alarmava com uma leitura só — o contrário do que a confirmação pretende.
- **Abre com a leitura de repetição fora do intervalo**, com o `occurredAt` da
  primeira, para o tempo não se perder, e as duas leituras na prova, com
  `confirmation: "repeated"`.
- **Sem resposta em 5 minutos, abre na mesma**, com `confirmation:
  "unavailable"`. Uma SpO2 de 85 num relógio que não responde ao pedido não pode
  ficar calada: na dúvida, falha para o lado do alarme.
- **A repetição dentro do intervalo** descarta a primeira sem publicar nada.

## 7. O ciclo de vida de uma condição

Uma **condição** é um estado da pessoa ou do aparelho que começa e acaba — a SpO2
baixa, a fralda por mudar, o relógio calado. Um **instante** é um acontecimento
que não acaba porque nunca durou — a queda, o botão de ajuda. Os dois saem no
canal `events`; só a condição leva o objeto `condition`.

### Um exemplo completo

Um relógio 4P Touch, com `blood_oxygen_limits` por omissão (alerta abaixo de 94,
alarme abaixo de 90, `confirmReadings` 2), mede de 15 em 15 minutos durante a
noite. Leituras: 96, 92, 91, 88, 87, 89, 93, 96.

**1. Abre** — a segunda leitura abaixo de 94 confirma a primeira:

```json
{
  "eventId": "0f9d6c1e-6a52-4d1b-9a0e-2b7d3c1f4a10",
  "type": "blood_oxygen_low",
  "severity": "alert",
  "occurredAt": "2026-10-23T02:15:00Z",
  "device": { "id": "861265061009822", "supplier": "4P Touch", "model": "D41" },
  "condition": {
    "version": 1,
    "id": "5b1e2a7c-3d4f-4c8a-9e61-7f0b2c9d8e31",
    "type": "blood_oxygen_low",
    "phase": "opened",
    "sequence": 41,
    "openedAt": "2026-10-23T02:15:00Z",
    "detectedAt": "2026-10-23T02:30:04Z",
    "evidence": {
      "evaluatedBy": "hub",
      "readings": [
        { "occurredAt": "2026-10-23T02:15:00Z", "spo2Percent": 92 },
        { "occurredAt": "2026-10-23T02:30:00Z", "spo2Percent": 91 }
      ],
      "readingCount": 2,
      "limit": { "field": "alertBelowPercent", "value": 94 },
      "limits": {
        "key": "blood_oxygen_limits",
        "revision": 3,
        "values": { "enabled": true, "alarmBelowPercent": 90, "alertBelowPercent": 94, "confirmReadings": 2, "clearMarginPercent": 1 }
      }
    }
  },
  "data": { "spo2Percent": 91, "limitPercent": 94 },
  "source": { "protocol": "four-p-touch", "nativeType": "oxygen" }
}
```

**2. Escala** — 88 e 87 confirmam o nível de alarme. Mesmo `condition.id`:

```json
{
  "eventId": "7c3a9e20-1b4d-4f6e-8a2c-5d9b0e1f3a42",
  "type": "blood_oxygen_low",
  "severity": "alarm",
  "occurredAt": "2026-10-23T02:45:00Z",
  "device": { "id": "861265061009822", "supplier": "4P Touch", "model": "D41" },
  "condition": {
    "version": 1,
    "id": "5b1e2a7c-3d4f-4c8a-9e61-7f0b2c9d8e31",
    "type": "blood_oxygen_low",
    "phase": "escalated",
    "sequence": 42,
    "openedAt": "2026-10-23T02:15:00Z",
    "detectedAt": "2026-10-23T03:00:03Z",
    "evidence": {
      "evaluatedBy": "hub",
      "readings": [
        { "occurredAt": "2026-10-23T02:45:00Z", "spo2Percent": 88 },
        { "occurredAt": "2026-10-23T03:00:00Z", "spo2Percent": 87 }
      ],
      "readingCount": 2,
      "limit": { "field": "alarmBelowPercent", "value": 90 },
      "limits": { "key": "blood_oxygen_limits", "revision": 3, "values": { "…": "…" } }
    }
  },
  "data": { "spo2Percent": 87, "limitPercent": 90 },
  "source": { "protocol": "four-p-touch", "nativeType": "oxygen" }
}
```

O 89 que vem a seguir volta ao nível de alerta, e **não sai nada** (ver «Descer
de nível»). O 93 também não: continua abaixo do limite de alerta.

**3. Acaba** — 96 está no intervalo com a margem (≥ 95):

```json
{
  "eventId": "e2b47d91-0c3f-4a8e-b615-9f2d7a0c4e58",
  "type": "blood_oxygen_low_cleared",
  "severity": "info",
  "occurredAt": "2026-10-23T03:45:00Z",
  "device": { "id": "861265061009822", "supplier": "4P Touch", "model": "D41" },
  "condition": {
    "version": 1,
    "id": "5b1e2a7c-3d4f-4c8a-9e61-7f0b2c9d8e31",
    "type": "blood_oxygen_low",
    "phase": "cleared",
    "sequence": 43,
    "openedAt": "2026-10-23T02:15:00Z",
    "detectedAt": "2026-10-23T03:45:04Z",
    "clearedAt": "2026-10-23T03:45:00Z",
    "reason": "normal_reading",
    "peakSeverity": "alarm",
    "evidence": {
      "evaluatedBy": "hub",
      "readings": [ { "occurredAt": "2026-10-23T03:45:00Z", "spo2Percent": 96 } ],
      "readingCount": 1,
      "limit": { "field": "alertBelowPercent", "value": 94, "clearMargin": 1 },
      "limits": { "key": "blood_oxygen_limits", "revision": 3, "values": { "…": "…" } }
    }
  },
  "data": { "spo2Percent": 96 },
  "source": { "protocol": "four-p-touch", "nativeType": "oxygen" }
}
```

E o canal `conditions` do dispositivo, retido, passou de uma condição aberta a
nenhuma (ver «Ressincronizar»).

### Identidade: `condition.id`

Cada condição recebe um UUID quando abre, e todos os eventos dela — a abertura,
cada escalada, o fim — levam o mesmo. É por ele que quem consome liga o seu
alerta a exatamente uma condição: um alerta aberto e depois escalado a alarme
são duas linhas na Havicare e **uma** condição, e um único fim diz que as duas
acabaram.

O `conditionKey`, onde existe, fica no `data` como informação: o `slot` e o dia da
dose, o `fault` do dispensador, o `pagerId` do NCS. O `condition.id` torna-o
dispensável para casar o fim com a abertura.

**Onde vive.** A condição é um registo do hub:

- **No MySQL, a tabela `device_conditions`**, que é o registo: `id`, dispositivo,
  `type`, `conditionKey`, empresa e licença de quando abriu, `openedAt`,
  `detectedAt`, `peakSeverity`, `clearedAt`, `reason`, e a prova de cada fase. É
  o que a API devolve e o que fica para auditoria; o histórico do Redis é
  limitado ([14](14-persistencia.md), secção 3) e
  não serve de arquivo.
- **No Redis, as abertas** (`hub:conditions:{deviceKey}`, com o prefixo da
  instância), que é o que a ingestão consulta a cada leitura sem ir à base de
  dados. Reconstrói-se da tabela ao arrancar.

Um reinício do hub não abre de novo o que já estava aberto, nem perde o fim: a
condição continua com o mesmo `id`.

### A fase: `condition.phase`

| `phase` | Quer dizer | `severity` | `type` do envelope |
|---|---|---|---|
| `opened` | A condição começou | a do nível confirmado | o da condição |
| `escalated` | Passou ao nível de alarme | `alarm` | o da condição |
| `cleared` | Acabou | `info` | `{type}_cleared`, ou o par que já existe |

Quem consome lê a fase, e nunca a tira do nome do `type`. Os `{type}_cleared`
ficam por quem filtra pelo `type` sem conhecer o `condition` — o hitCare, um
painel, um consumidor antigo: com o mesmo `type` na abertura e no fim, esse
consumidor leria o fim de uma frequência alta como mais uma frequência alta. Um
tipo novo é ignorado por quem não o conhece, que é a falha segura.

O `data.clears` da versão anterior desta proposta **sai**: o `condition.type` diz
o mesmo, e dois campos a dizer a mesma coisa acabam por discordar.

**Descer de nível não publica nada.** Uma condição escalada a alarme que volta ao
nível de alerta continua aberta, e o `peakSeverity` continua `alarm`. Três razões:

- **O alarme já chamou alguém.** Uma fase `deescalated` convidava quem consome a
  baixar ou fechar o alarme antes de alguém ter ido ver, e um alarme fechado sem
  ninguém ter visto a pessoa é o pior desfecho possível.
- **Os limites de alarme não têm margem.** Uma SpO2 a oscilar entre 89 e 90
  escalava e desescalava a cada leitura — o ruído que a margem existe para evitar
  no fim da condição.
- **O que se quer saber está no fim.** O `peakSeverity` e as leituras dizem quão
  grave foi; o nível do momento lê-se na telemetria.

### Instantes e condições

Um evento **sem** objeto `condition` é um instante: não terá fim publicado, e
quem o fecha é quem foi ver. A ausência é a regra única, e não uma fase
`instant` — uma fase só para dizer «não há fases» convidava a procurar por um
`id` a que nunca vai chegar nada.

| Condições | Instantes |
|---|---|
| `heart_rate_high` · `heart_rate_low` | `help_call` (fora do NCS) |
| `breath_rate_high` · `breath_rate_low` | `fall` |
| `blood_oxygen_low` | `proximity_alarm` |
| `blood_pressure_high` · `blood_pressure_low` | `apnea` |
| `temperature_high` · `temperature_low` | `heart_rate_abnormal` |
| `weak_vital_signs` | `device_removed` |
| `zone_exit` com `zone: geofence` | `zone_entry` e `zone_exit` de divisão ou área |
| `check_required` · `change_required` | `low_battery` do MKGW4, que só manda o alarme |
| `low_battery` (onde o aparelho apaga a bandeira) | `device_state` |
| `device_fault` · `storage_environment` | `medication_alarm_change` |
| `dose_missed` | `medication_intake` (salvo o `abnormal`, que é o fim de um `dose_missed`) |
| `help_call` do NCS | `reset` (salvo quando é o fim de um `help_call`) |
| `device.disconnected` | os outros `device.*` |

O mesmo `type` pode ser condição num aparelho e instante noutro — o `help_call`
do NCS acaba com o `reset`, o do relógio não acaba nunca. É exatamente por isso
que quem consome lê o `condition`, e não o `type`.

### O fim e o motivo: `condition.reason`

Todo o fim leva `reason`:

| `reason` | Quando | O que acontece à condição |
|---|---|---|
| `normal_reading` | Uma leitura de volta ao intervalo, com a margem (regra do hub), ou o estado normal que o aparelho reporta (o minuto normal do radar, a fralda limpa, a posição dentro da cerca) | Acaba |
| `device_resolved` | O próprio aparelho dá a condição por acabada: a bandeira de bateria apaga, a avaria ou o ambiente do dispensador apagam, o `reset` do NCS | Acaba |
| `dose_taken` | A toma tardia (`abnormal`) da mesma dose | Acaba |
| `device_reconnected` | O fim do `device.disconnected` | Acaba |
| `superseded` | Outra condição tomou o lugar desta: a frequência que salta de alta para baixa entre duas leituras, a fralda que passa de `check_required` a `change_required`. Leva `supersededBy` com o `id` da nova | Acaba; a nova abre no mesmo instante |
| `limits_changed` | Os limites mudaram e a última leitura já cabe no intervalo novo | Acaba |
| `limits_disabled` | A família foi desligada (`enabled: false`) | Acaba |
| `device_unassigned` | O dispositivo saiu do dono — removida a empresa e a licença, ou mudado para outro cliente ou outra licença | Acaba **no tópico do dono antigo** |
| `device_deleted` | O dispositivo foi apagado | Acaba, antes de o dispositivo desaparecer |

**A mudança de dono.** As condições são do dono que as tinha. Ao reassociar, o
hub publica o fim de cada condição aberta no tópico do dono antigo, com
`device_unassigned`, e só depois apaga o retido do `conditions` dele com a
mensagem de comprimento zero, como faz ao `status`. O fim não diz para onde o
dispositivo foi: isso é do cliente novo. O dono novo começa sem nenhuma condição
aberta, e se a pessoa continuar fora do intervalo a condição abre de novo, com
`id` novo, pelas leituras que chegarem já com ele — o `confirmReadings` conta do
princípio.

**Sem leitura não há fim.** Um aparelho calado não acaba uma condição: falta
de leitura não é leitura normal. Não existe um motivo `no_reading`; se o Hugo
quiser o radar fora da cama a acabar a condição (pergunta 7), é esse o motivo que
entra.

### Datas e prova

| Campo | O que é |
|---|---|
| `occurredAt` (envelope) | Na abertura, a primeira leitura fora do intervalo; na escalada, a primeira no nível de alarme; no fim, a leitura que a acabou, ou o instante do que a acabou (a reassociação, a escrita dos limites) |
| `condition.openedAt` | O `occurredAt` da abertura, repetido em todas as fases |
| `condition.detectedAt` | Quando o hub decidiu esta fase. A diferença para o `occurredAt` é o tempo da confirmação |
| `condition.clearedAt` | Só no fim: igual ao `occurredAt` dele |
| `condition.evidence.evaluatedBy` | `hub` ou `device` — quem aplicou o limite |
| `condition.evidence.readings` | As leituras que decidiram esta fase, cada uma com o seu `occurredAt` e o valor com o nome da telemetria; no radar, a média do minuto |
| `condition.evidence.readingCount` | Quantas foram |
| `condition.evidence.limit` | O campo do limite que se passou e o valor dele; no fim, também a margem |
| `condition.evidence.limits` | A chave, a revisão **efetiva** e os valores todos da configuração em vigor quando esta fase foi decidida |
| `condition.evidence.confirmation` | Só com a repetição de medição: `repeated` ou `unavailable` |

A revisão e os valores vão inteiros, e não só a revisão: uma auditoria — e o MDR,
se o hub entrar no âmbito dele — precisa de saber com que regra se decidiu, sem
depender de a configuração antiga ainda estar guardada.

Nas condições que não vêm de leituras (`device.disconnected`, `dose_missed`,
`device_fault`), a `evidence` leva só o `evaluatedBy` e o que o aparelho mandou.

### Duplicados e ordem

**Cada publicação leva `eventId`**, um UUID, no envelope de todos os eventos do
canal `events`, e não só nos das condições. A entrega QoS 1 repete a mesma
publicação com o mesmo `eventId`: é a chave de de-duplicação. Um evento com um
`eventId` já visto ignora-se.

**O `condition.sequence`** conta as fases de todas as condições do dispositivo,
sempre a subir. É o que ordena um evento contra outro e contra a fotografia do
canal `conditions`.

**Fora de ordem.** O MQTT não garante a ordem depois de uma reentrega, e quem
consome trata a condição como uma máquina de estados que só anda para a frente:

- **Um fim que chega antes da abertura** regista a condição como acabada, com o
  `id`. A abertura que chega depois encontra-a acabada e não abre nada.
- **Uma escalada que chega antes da abertura** abre a condição já no nível de
  alarme; a abertura que chega depois só preenche o que faltava.
- **Uma fase com `sequence` menor do que a última que se aplicou à mesma
  condição** não muda o estado.

### Ressincronizar

Um consumidor que esteve em baixo perdeu fases. Dois caminhos, com papéis
diferentes:

**O canal `conditions`, retido, por dispositivo** — o estado de agora:

```text
havicare-hub/hitcare/1001/watch/861265061009822/conditions
```

```json
{
  "updatedAt": "2026-10-23T02:30:04Z",
  "sequence": 41,
  "device": { "id": "861265061009822", "supplier": "4P Touch", "model": "D41" },
  "open": [
    {
      "id": "5b1e2a7c-3d4f-4c8a-9e61-7f0b2c9d8e31",
      "type": "blood_oxygen_low",
      "severity": "alert",
      "openedAt": "2026-10-23T02:15:00Z",
      "detectedAt": "2026-10-23T02:30:04Z",
      "data": { "spo2Percent": 91, "limitPercent": 94 }
    }
  ]
}
```

QoS 1 e retido, como o `status`, e republicado a cada fase de qualquer condição do
dispositivo. Sem condições abertas é `open: []`, e não a mensagem vazia — essa
fica para a mudança de dono, como no `status`. O `severity` é o mais alto a que a
condição chegou. Quem subscreve recebe a fotografia de todos os dispositivos mal
liga, sem pedir nada.

**A API** — o que aconteceu no intervalo:

```http
GET /api/devices/{imei}/conditions?state=open
GET /api/devices/{imei}/conditions?state=all&since=2026-10-22T00:00:00Z
GET /api/conditions?state=open
GET /api/conditions/{id}
```

A mesma forma dos objetos do `open`, mais o `clearedAt`, o `reason`, o
`peakSeverity` e a prova de cada fase. O `/api/conditions` traz as das licenças
que o token vê, para uma reconciliação inteira de uma vez. O MQTT não tem
histórico, e é por aqui que um consumidor sabe **como** acabou uma condição de
que só viu a abertura.

**Reconciliar depois de uma falha:**

1. Ao voltar, ler o `conditions` retido de cada dispositivo (chega sozinho com a
   subscrição), ou o `GET /api/conditions?state=open`.
2. Uma condição aberta na fotografia e desconhecida do consumidor abre-se, com o
   `openedAt` e a gravidade dela.
3. Uma condição aberta no consumidor e ausente da fotografia acabou entretanto:
   o `GET /api/conditions/{id}` diz quando e porquê.
4. Os eventos que chegarem durante a reconciliação aplicam-se pela regra de
   ordem: uma fase com `sequence` menor ou igual à da fotografia já está nela.

Um canal novo muda a lista de canais que o contrato garante. Quem subscreve com
`#` passa a receber mais um; ver a secção 10.

### A versão: `condition.version`

O objeto `condition` leva `version`, hoje `1`. Sobe só quando uma mudança parte
quem o lê — um campo que muda de sentido, uma fase que desaparece; acrescentar um
campo ou um `reason` novo não a sobe, e quem consome ignora o que não conhece.

O hub já teve um `schemaVersion` no envelope e tirou-o
([08](08-contrato-mqtt.md#sobre-o-schemaversion)): era escrito e nunca lido, e
seguia o produtor e não o canal. Este é diferente em duas coisas, que são as
condições para valer a pena: vale só para o objeto `condition`, com um dono só,
e a Havicare compromete-se a lê-lo. Se ninguém o ler, tem o mesmo destino.

### Cada condição e o seu fim

| Condição | O fim | `reason` | Como o hub sabe |
|---|---|---|---|
| `heart_rate_high` · `heart_rate_low` (radar) | `heart_rate_high_cleared` · `heart_rate_low_cleared` | `normal_reading` | O `hbstatics` seguinte com a frequência no estado normal (`00`). O indefinido (`11`) não abre nem acaba |
| `breath_rate_high` · `breath_rate_low` (radar) | `breath_rate_high_cleared` · `breath_rate_low_cleared` | `normal_reading` | Idem, com a respiração. Uma apneia não acaba uma hipopneia |
| `weak_vital_signs` | `weak_vital_signs_cleared` | `normal_reading` | Os vitais no estado normal |
| as condições da regra do hub | `{type}_cleared` | `normal_reading`, `superseded` | A leitura dentro do intervalo, com a margem (secção 6) |
| `zone_exit` com `zone: geofence` | `zone_entry` com `zone: geofence` | `normal_reading` | `safe_zones` |
| `device.disconnected` | `device.connected` | `device_reconnected` | O ciclo de vida de hoje |
| `check_required` | `change_required` (o `cleared` desta e a abertura da outra são dois eventos), ou `check_required_cleared` | `superseded`, `normal_reading` | A fralda passa a muda, ou fica limpa |
| `change_required` | `change_required_cleared` | `normal_reading` | O `diaper_condition` volta a `clean` |
| `low_battery` | `low_battery_cleared` | `device_resolved` | A bandeira apaga (`LowBatteryTransitions`) |
| `device_fault` | `device_fault_cleared` | `device_resolved` | O dispensador dá a avaria por apagada |
| `storage_environment` | `storage_environment_cleared` | `device_resolved` | Idem |
| `dose_missed` | `medication_intake` com `result: "abnormal"` | `dose_taken` | A toma tardia do mesmo `slot` e `doseDate` |
| `help_call` (NCS) | `reset` | `device_resolved` | O `key` 0, 1 ou 2 da Voerka, com o mesmo `pagerId` |

A todas se juntam os motivos que não dependem do aparelho: `limits_changed` e
`limits_disabled` nas de sinais vitais, `device_unassigned` e `device_deleted` em
todas.

**A fralda: um alerta, e não dois.** Quando a fralda passa de `attention` a
`change_required`, saem dois eventos com o mesmo `occurredAt`: o fim do
`check_required` com `reason: "superseded"` e `supersededBy`, e a abertura do
`change_required`. São dois porque são duas condições, cada uma com o seu `id`;
o `supersededBy` diz a quem consome que a segunda continua a primeira.

O `device_removed` da 4P Touch podia acabar pelo bit 3 do estado («watch state»),
mas a ficha não diz o que o bit quer dizer em cada valor, e fica instante até se
confirmar no aparelho.

## 8. Os pontos da Havicare

Da primeira revisão:

| | Proposta da Havicare | Aqui |
|---|---|---|
| a | `device.disconnected` com `alert`, acabado pelo `device.connected` | **Adotado**, com `info` nos aparelhos que se desligam a cada medição |
| b | Um campo, `data.clears`, em todo o fim | **Substituído** pelo objeto `condition`, que diz o mesmo com o `type`, o `id` e a fase. **Recusado** o fim com o mesmo `type` da abertura |
| c | `device_state` da Wonlex a `info` | **Adotado** |
| d | `change_required` acaba o `check_required` | **Adotado**, como `superseded` |
| — | `dose_missed` por dose, acabado pela toma tardia | **Adotado**, com o `abnormal` — e não o `late` — como fim: no M228 o `late` é a toma depois do aviso e antes de a dose falhar |
| — | Cercas como configuração do hub, com `zone_exit` e `zone_entry` | **Adotado** (`safe_zones`) |

Da segunda:

| | Pedido | Aqui |
|---|---|---|
| 0 | O fim é um facto, e não uma ordem | **Adotado** em todo o capítulo |
| 1 | `conditionId` estável | **Adotado** como `condition.id`, guardado na `device_conditions` e no Redis |
| 2 | Fase explícita | **Adotado** (`opened`, `escalated`, `cleared`); descer de nível **não publica nada** |
| 3 | Instantes marcados | **Adotado** pela ausência do `condition`, e não por uma fase `instant` |
| 4 | Motivo em todo o fim | **Adotado**, com a mudança de dono a acabar no tópico do dono antigo |
| 5 | Ressincronização | **As duas**: o canal retido `conditions` para o estado de agora, a API para o histórico |
| 6 | Duplicados e ordem | `eventId` em todos os eventos, `condition.sequence` por dispositivo |
| 7 | Datas e prova | **Adotado**, com a configuração inteira em vigor e não só a revisão |
| 8 | Contrato da configuração | **Adotado**, sem estados novos; vale a revisão efetiva inteira |
| 9 | Repetir a medição | Desenhado, **por decidir** pelo Hugo (pergunta 6) |
| 10 | Versão | **Adotado** só no objeto `condition`, pelas razões da secção 7 |

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
| `eventId` em todos os eventos | todas as famílias | Acrescento ao envelope; ignorável |
| Objeto `condition` nos eventos que são condições | todas | Acrescento ao envelope; ignorável |
| `device.disconnected` e `device.connected` ganham `severity` e `condition` | todas, incluindo NCS, gateways e pulseiras do hitCare | Os eventos de ciclo de vida não levavam nada disto: **coordenar** com o hitCare |
| Tipos novos `{type}_cleared` | todas; no hitCare, `check_required_cleared`, `change_required_cleared`, `low_battery_cleared` | Um consumidor que rejeite tipos desconhecidos parte: **coordenar**. O contrato congelado da fralda garante os `type` existentes, não a ausência de novos ([17](17-sensor-de-fralda.md#4-o-contrato-congelado)) |
| O `change_required` vindo de `attention` vem acompanhado do fim do `check_required` | fralda | Um evento a mais com o mesmo `occurredAt`, de um tipo novo |
| O `reset` do NCS que acaba um `help_call` leva `condition` | NCS | Acrescento; o `type` e o `data` ficam iguais |
| Canal novo `conditions`, retido | todas | Quem subscreve com `#` num dispositivo passa a receber mais um canal, e um payload que não conhece: **coordenar** |
| Gravidades que mudam (`apnea`, `weak_vital_signs`, `device_state`, `medication_*`, a frequência do radar) | radar, Wonlex, dispensador | Nenhuma destas famílias é do hitCare |
| Os bits de cerca da 4P Touch deixam de sair | relógios 4P Touch | Não é do hitCare |
| `heart_rate_alert` e `breath_rate_alert` do radar dão lugar a `*_limits` | API do radar | Quebra quem os escreve (pergunta 8) |

A Havicare não tem clientes, e muda pela via mais limpa; o que toca o hitCare entra
na tabela «Pendente do hitCare» do contrato no dia em que for construído, com o
comentário `hitcare:` no código.

## 11. Perguntas em aberto

1. **Desligar com alerta e as ligações que oscilam.** Um relógio que fecha o
   socket e volta em segundos abre e acaba uma condição de cada vez. Proposta:
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
   pedidos, com o estado `confirming` por publicar e a abertura na mesma ao fim de
   5 minutos sem resposta — mas é o hub a mandar comandos sozinho, e isso é
   decisão tua. Sem ela, fica o `confirmReadings`, e uma SpO2 de 85 num relógio
   espera pela leitura periódica seguinte.
7. **O radar fora da cama.** Uma frequência alta aberta e a pessoa que se levanta:
   o `hbstatics` para e a condição fica aberta até à noite seguinte. Proposta:
   fica, porque sem leitura não é normal. A alternativa é acabar com
   `reason: "no_reading"`.
8. **Os `heart_rate_alert` e `breath_rate_alert` do radar saem.** Quem os escreve
   hoje (as licenças CVP, emeis e CBS na radarconsole) passa a escrever os
   `*_limits`. Confirmar que nenhum cliente além da Havicare os usa.
9. **O `irregularHeartbeat`** do tensiómetro: fica na telemetria, ou passa a
   evento `alert`?
10. **Atraso dos blocos da Veepoo.** A SpO2 e a frequência da Veepoo chegam nos
    blocos de cinco minutos que o gateway relê. Os 30 minutos de idade máxima
    supõem que a releitura é mais frequente do que isso; falta medir.
11. **O MDR.** A prova leva a configuração inteira para servir uma auditoria. Se o
    hub entrar no âmbito do MDR como parte de um dispositivo médico, a tabela
    `device_conditions` passa a registo com retenção definida, e isso é uma
    decisão que não é técnica.

Duas posições da Havicare, por decidir — não estão adotadas neste desenho:

12. **Todas as regras do hub desligadas por omissão** (`enabled: false`), ligadas
    pela Havicare dispositivo a dispositivo, pelo plano de monitorização do
    cliente. A favor: nada alarma sem alguém ter decidido vigiar aquela pessoa.
    Contra: um dispositivo registado sem plano mede SpO2 de 85 e fica calado, e o
    hitCare, que não tem plano nenhum, nunca receberia um alarme clínico. A minha
    leitura: ligadas por omissão nos aparelhos de medição, que alguém usou de
    propósito; a decisão dos relógios e da pulseira é tua.
13. **A deteção como módulo à parte no hub**, que só lê dados normalizados e não
    sabe de clientes, planos nem horários; as **medições em falta** (uma leitura
    agendada que não chegou) ficam na Havicare, que é quem conhece o horário do
    plano. A minha leitura: concordo com as duas metades. O módulo decide sobre
    leituras e limites; quem sabe do dono é quem publica, e é aí que fica a
    mudança de dono da secção 7 — a deteção não precisa de saber de quem é o
    dispositivo para acabar uma condição com `device_unassigned`.
