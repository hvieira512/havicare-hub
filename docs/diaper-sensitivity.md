# Sensibilidade do medidor de fraldas — parametrização por sensor

**Estado:** implementado.

A app da MONIT expõe dois parâmetros que decidem quando uma fralda conta como suja,
e três presets sobre eles. O hub tem hoje esses dois valores em hardcode, e por
coincidência exacta são o preset "Normal":

```
MonitNormalizer.php
  CHANNEL_WET_DELTA        = 12   ← Pollution Value
  CHANGE_AFFECTED_CHANNELS = 4    ← Pollution Range
  CLEAN_MAX_DELTA          = 4    ← nosso, não deles
```

O objectivo é tornar esses dois valores configuráveis por sensor. **Não há downlink**:
o sensor é um beacon BLE não-conectável e nada é enviado para ele. O que muda é a
regra com que o hub deriva o estado da fralda a partir da mesma leitura física.

---

## 1. Os parâmetros

| Parâmetro | Significado | Gama válida | Graduação da app deles |
|---|---|---|---|
| `pollutionRange` | quantos canais têm de estar molhados para exigir muda | 2–10 | 2–3 sensível, 4–5 normal, 6–10 insensível |
| `pollutionValue` | o delta por canal que conta como molhado | 5–25 | 5–8 sensível, 9–16 normal, 17–25 insensível |

Presets da app:

| Preset | `pollutionRange` | `pollutionValue` |
|---|---|---|
| `more_alerts` | 3 | 7 |
| `normal` | 4 | 12 |
| `fewer_alerts` | 7 | 15 |

Ausência de configuração significa `normal`. Isso é deliberado e evita uma migração
de backfill: os sensores já registados continuam a comportar-se exactamente como hoje.

---

## 2. A lógica

### 2.1 O terceiro limiar é nosso e passa a derivado

A app deles expõe dois valores e tem dois estados. O hub tem três — `clean`,
`attention`, `change_required` — e o limiar que separa `clean` de `attention`
(`CLEAN_MAX_DELTA = 4`) é invenção nossa. Mantê-lo absoluto enquanto o
`pollutionValue` varia produz absurdos: com `pollutionValue = 25`, um delta de 4 já
tirava a fralda de `clean` quando falta 21 para ela contar como molhada.

Passa a derivar:

```
cleanMaxDelta = intdiv(pollutionValue, 4) + 1
```

Reproduz o valor actual exactamente (`intdiv(12, 4) + 1 = 4`), e o `+1` não é
cosmético — é o que mantém verdadeira a prova aritmética da secção 2.3.

### 2.2 A condição

Inalterada na forma, só com os limiares agora parametrizados:

```
affected  = quantos canais têm delta >= pollutionValue
condition = max(deltas) < cleanMaxDelta        -> 'clean'
            affected >= pollutionRange         -> 'change_required'
            caso contrário                     -> 'attention'
```

### 2.3 As bandas do índice não mudam — e a prova continua a valer

O `MOISTURE_INDEX_BANDS` mantém-se literal em `[0,25] / [25,39] / [40,100]`. A razão
de existir é garantir que o número e o badge nunca se contradizem no ecrã, e essa
garantia sobrevive a qualquer configuração por duas vias distintas.

A primeira é aritmética, e é para isso que serve a fórmula do `cleanMaxDelta`:

```
clean  ⟹ todos os deltas <= cleanMaxDelta - 1 = intdiv(pollutionValue, 4)
                                             <= pollutionValue / 4
       ⟹ cada termo = min(delta / pollutionValue, 1) <= 0.25
       ⟹ média <= 0.25
       ⟹ índice <= 25                                        ✔ dentro da banda
```

A segunda é o `clamp` que já existe no fim do `buildMoistureIndex`
(`max($floor, min($ceiling, $index))`). Para `change_required` a aritmética dá
`média >= pollutionRange / 10`, o que com `pollutionRange = 4` aterra nos 40 e com
`pollutionRange = 3` daria 30 — e o `clamp` sobe-o aos 40. O invariante que interessa
mantém-se por construção, para qualquer configuração:

```
condition == 'clean'            ⟺ índice <= 25
condition == 'change_required'  ⟺ índice >= alertIndex
```

**O custo, dito com clareza:** em configurações longe do Normal o índice comprime-se
nos extremos — várias leituras distintas encostam ao 25 ou ao 40. Perde-se resolução,
não correcção. O `alertIndex` continua a viajar no payload, portanto quem desenha a
marca de alerta não precisa de saber nada disto.

### 2.4 O nome do perfil é derivado, não guardado

Guardar perfil *e* valores permite que discordem — perfil `normal` com valores 3/7.
Guardam-se só os dois inteiros; o nome calcula-se:

```
(3,7) -> 'more_alerts'   (4,12) -> 'normal'   (7,15) -> 'fewer_alerts'
qualquer outro par -> 'custom'
```

Uma fonte de verdade, impossível de ficar inconsistente, e o "Custom" que pediste sai
de graça em vez de ser um quarto estado a manter.

---

## 3. Onde vive o estado

### 3.1 Tabela

```sql
CREATE TABLE IF NOT EXISTS diaper_sensor_settings (
    imei VARCHAR(64) NOT NULL PRIMARY KEY,
    pollution_range TINYINT UNSIGNED NOT NULL,
    pollution_value TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_diaper_sensor_settings_device
        FOREIGN KEY (imei) REFERENCES whitelist(imei) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Tabela própria e não colunas no `whitelist`, porque dois inteiros de fralda não têm
significado num relógio, num radar ou num gateway — e porque o `whitelist` é um mapa
carregado em memória no arranque, o que faria uma alteração pela API não chegar ao
worker de ingestão até reiniciar o processo.

### 3.2 Porque MySQL é o que torna isto seguro a restarts

O `bin/server-hub.php` já depende de MySQL no caminho quente: a linha 79 injecta
`$services->dataAccess->gatewayDeviceLinks`, consultado a cada observação no
`Bridge::linkedDevice()`. O padrão é interface em `src/Domain/` + repositório PDO com
cache TTL em memória. Ao reiniciar, a cache nasce vazia e reenche na primeira
observação. Nada se perde.

| estado | onde vive | sobrevive ao restart |
|---|---|---|
| sensibilidade | MySQL | ✔ fonte de verdade |
| cache do repositório | memória, TTL 5 s | irrelevante, reenche |
| condição anterior da fralda | Redis, processo separado | ✔ |
| dedupe de tramas | Redis | ✔ |

### 3.3 Interface e repositório

```
src/Domain/DiaperSensitivityLookup.php
    interface: for(string $sensorKey): array{pollutionRange: int, pollutionValue: int}
    devolve o preset normal quando não há linha

src/Api/Repository/DiaperSensitivityRepository.php
    implements DiaperSensitivityLookup
    __construct(PDO $pdo, int $cacheTtlSeconds = 5)
    for() / upsert() / delete(), cache invalidada no upsert e no delete
```

Cópia deliberada da forma do `GatewayDeviceLinkRepository`, incluindo o TTL de 5
segundos: é quanto tempo uma alteração leva a ser aplicada pela ingestão, sem restart.

---

## 4. A alteração de configuração e o alarme activo

Mudar a sensibilidade muda a condição derivada para a mesma leitura física. Uma fralda
com 3 canais afectados está em `attention` no Normal e torna-se `change_required` no
`more_alerts`. O evento tem de sair — e no sentido inverso, um alarme activo que deixa
de o ser não pode ficar preso.

A solução não precisa de plumbing novo: **a configuração entra no valor guardado da
condição**.

```
antes:  chave hub:moko:condition:{sensorKey}  valor "change_required"
depois: chave hub:moko:condition:{sensorKey}  valor "change_required@4-12"
```

Mudar a configuração muda o valor, logo há transição, e o `transitionCondition()` já
trata `previous` nulo como transição — o `e041f31` existe precisamente para que um
sensor visto pela primeira vez já em `change_required` levante o alarme.

**Isto foi corrigido durante a implementação, e o teste é que o apanhou.** A primeira
versão punha a configuração na *chave*, e assim ela só era fresca a primeira vez que
cada par (sensor, configuração) aparecia. Passar de `normal` para `fewer_alerts` e
voltar a `normal` reencontrava a chave antiga com `change_required` lá dentro, não via
transição, e engolia o alarme numa fralda suja — pior do que o problema que a feature
vinha resolver. No valor, os quatro cenários (apertar, aliviar, voltar, repetir) saem
todos certos.

Consequência para o contrato: o `previousState` publicado tem de ser despido do sufixo
antes de sair, porque continua a ser um dos três estados ou nulo. Há um teste só para
isso.

---

## 5. Contrato MQTT

Dois campos novos em `diaper_moisture.data`, pela mesma razão que o `alertIndex` já
viaja no `diaper_moisture_level`: quem mostra "3 de 4 canais afectados" precisa do 4,
e com os limiares agora mutáveis por sensor não pode escrevê-lo em hardcode.

```
diaper_moisture.data.requiredChannelCount   = pollutionRange
diaper_moisture.data.wetDelta               = pollutionValue
```

O contrato congelado em `docs/diaper-sensor-mqtt-contract.md` §6 já autoriza
acrescentar campos. Nada é removido nem muda de significado. A sensibilidade **não**
vai em todas as mensagens: `battery`, `diaper_condition` e `proximity` ficam intactos.

---

## 6. API

Sub-recurso por dispositivo, exactamente como os `links`, e **fora** do ciclo de vida
de `device_configurations`:

```
GET    /api/devices/{imei}/diaper-sensitivity
PUT    /api/devices/{imei}/diaper-sensitivity     {"pollutionRange": 3, "pollutionValue": 7}
DELETE /api/devices/{imei}/diaper-sensitivity     volta ao preset normal
```

O `GET` devolve os dois valores, o nome do perfil derivado, e os presets disponíveis
com as gamas válidas, para o cliente construir o selector sem duplicar tabelas.

**Não é declarada como capacidade.** O catálogo de capacidades configuráveis é hoje
inteiramente `watch`, e `isConfigurable: true` encaminha para o
`DeviceConfigurationUpdateService`, que chama `DeviceCommandCatalog::buildDownlink()`
sem condição. Uma configuração que nunca sai do hub teria de inventar um comando falso
e um terceiro `confirmation_mode`. Os `links` também não são capacidade, pela mesma
razão, e são o precedente certo.

---

## 7. Ficheiros

**Novos**

```
src/Domain/DiaperSensitivityLookup.php
src/Api/Repository/DiaperSensitivityRepository.php
src/Infrastructure/Persistence/Migration/Version…DiaperSensorSettings.php
```

**Alterados**

```
src/Ingress/Mqtt/Moko/MonitNormalizer.php     limiares passam a parâmetros
src/Ingress/Mqtt/Moko/Bridge.php              lookup injectado, lido no handleMonitObservation
src/Ingress/Mqtt/Moko/RedisObservationStateStore.php   chave da condição
bin/server-hub.php                            injecção, ao lado do gatewayDeviceLinks
src/Api/Routes/DeviceRoutes.php               três rotas
src/Api/Controllers/DeviceController.php      três acções
src/Api/OpenApi/…                             esquema
src/Infrastructure/Persistence/DatabaseMigrationPlan.php
database/schema.sql
src/Dashboard/dashboard/…                     selector de perfil
docs/diaper-sensor-mqtt-contract.md           os dois campos novos
```

A assinatura muda para `MonitNormalizer::normalize($decoded, $device, $gatewayId,
$sensitivity)`, com o parâmetro **obrigatório** e sem valor por omissão, para que o
PHPStan encontre qualquer chamador não ligado em vez de silenciosamente cair no Normal.

---

## 8. Testes

### `tests/Unit/Ingress/Mqtt/Moko/MonitSensitivityTest.php` (novo)

- **`normalPresetReproducesCurrentThresholds`** — o preset `normal` produz
  `cleanMaxDelta = 4`, idêntico à constante actual. É a garantia de compatibilidade.
- **`derivedCleanThreshold`** — `intdiv(v,4)+1` para `v` = 5, 7, 12, 15, 25 →
  2, 2, 4, 4, 7.
- **`sameReadingChangesConditionAcrossPresets`** — um vector com 3 canais a 13:
  `more_alerts` → `change_required`; `normal` → `attention`; `fewer_alerts` →
  `attention`. Orientado a tabela, um caso por preset.
- **`bandInvariantsHoldForEverySetting`** — o teste que importa. Varre
  `pollutionRange` 2–10 × `pollutionValue` 5–25 × um conjunto de vectores de canais
  (todos a zero; todos a 63; um canal quente; exactamente `range-1` quentes;
  exactamente `range` quentes; e o vector real capturado `[1,3,5,7,8,7,7,7,7,8]`), e
  afirma para cada combinação:
  - `clean` ⟹ índice ≤ 25
  - `change_required` ⟹ índice ≥ `alertIndex`
  - `attention` ⟹ 25 ≤ índice ≤ 39
  - índice sempre em 0–100

  São 1134 combinações, baratas, e é o que garante que o número e o badge nunca se
  contradizem em nenhuma configuração alcançável.
- **`profileNameFromValues`** — os três presets dão os seus nomes; (5,9) dá `custom`.
- **`validationRejectsOutOfRange`** — `range` 1 e 11 rejeitados, `value` 4 e 26
  rejeitados.

### `tests/Unit/Ingress/Mqtt/Moko/MonitMoistureIndexTest.php` (existente)

**As doze asserções passam sem uma alterada.** Só as duas chamadas ao normalizador
passaram a dizer explicitamente qual é o preset, porque o parâmetro é obrigatório de
propósito — a promessa inicial de "sem uma linha alterada" era incompatível com isso, e
o que importa é que nenhuma expectativa mudou. O mesmo se aplica ao `DecoderTest`.

### `tests/Unit/Ingress/Mqtt/Moko/BridgeMonitAlarmTest.php` (existente, estendido)

- **`settingsChangeReRaisesActiveAlarm`** — lookup falso a devolver `normal`, uma
  observação que aterra em `attention`, nenhum evento. O lookup passa a
  `more_alerts`, a mesma observação → `change_required` com `previousState` nulo e
  evento publicado.
- **`unchangedSettingsDoNotReRaise`** — a mesma configuração duas vezes, evento uma
  só vez.

### `tests/Integration/Persistence/DiaperSensitivityRepositoryTest.php` (novo)

- linha ausente → preset `normal`
- `upsert` seguido de leitura devolve o que foi escrito
- cache: com `cacheTtlSeconds = 0` a leitura vê sempre a base de dados; com o TTL
  por omissão uma escrita feita por SQL directo não é vista dentro da janela
- `delete` volta aos valores por omissão
- apagar o dispositivo no `whitelist` apaga a configuração (cascata da chave
  estrangeira)

### `tests/Integration/Api/` (novo)

- `GET` num sensor sem linha devolve `normal` e os presets
- `PUT` com um preset, `GET` reflecte, perfil derivado correcto
- `PUT` com valores fora da gama → 400 com `invalid_sensitivity` (o mapeador de estados
  do projecto usa 400 para entrada inválida, não 422 como esta spec dizia)
- `PUT` num dispositivo que não é `diaper_sensor` → 400
- `DELETE` volta ao `normal`

---

## 9. Verificação ponta a ponta

1. `make test` e `phpstan`.
2. Localmente em Docker, com o simulador ou reproduzindo uma trama capturada:
   `PUT` do perfil `more_alerts` no sensor `eec5000202f9` e confirmar no tópico
   `telemetry` que o `diaper_condition.data.state` muda para a mesma trama, que o
   `requiredChannelCount` acompanha, e que o evento `change_required` sai uma vez.
3. Confirmar que a alteração é aplicada em ≤ 5 s sem reiniciar o processo.
4. Reiniciar o `health-hub` e confirmar que a configuração persiste e que a primeira
   observação depois do arranque volta a aplicá-la.
