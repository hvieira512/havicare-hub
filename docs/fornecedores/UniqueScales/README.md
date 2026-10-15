# Unique Scales

Balanças de análise corporal CF621BLE e CF632BLE, com firmware Lefu. A CF632BLE
fala o protocolo **Torre** do SDK da Lefu. Não há documento do protocolo, por
isso o que está aqui vem de três fontes:

- **SDK da Lefu:** `com.lefu.ppbluetoothkit:ppbluetoothkit` 4.6.26, que é público no
  repositório Maven `https://raw.githubusercontent.com/LefuHengqi/PPBaseKit-Android/main`.
  A demo está em <https://github.com/LefuHengqi/BluetoothKit-AndroidDemo>. O código
  do protocolo está em `com.peng.ppscale.business.torre`: `ProtocalTorreDeviceHelper`
  descodifica as tramas e `TorreDelegate` envia os comandos. O código-fonte do SDK é
  privado; o que se lê é o AAR descompilado.
- **2026-10-15:** sondas GATT ligadas a uma CF632BLE (`probes/lefu-scale.mjs`, no
  `veepoo-linux-spike`), com pesagens filmadas para comparar com o visor.
- **2025-12:** HCI snoop da app Unique Health feito pelo Marcus.

A CF621BLE nunca foi testada. Não se sabe se também é Torre.

## Transporte

**Só GATT.** A balança anuncia-se com dados de fabricante `xx cf` (o primeiro byte
variou: `4c`, `35`), seguidos dos 5 últimos bytes do MAC e de 12 zeros, e com o
nome `CF632`. Uma pesagem completa não muda o anúncio, por isso os gateways MOKO
não servem para esta balança.

A balança só acorda quando alguém sobe para ela e desliga a ligação cerca de
30 s depois de a pessoa sair.

| Característica | Handle no snoop | Uso |
| --- | --- | --- |
| `FFF1` (write, notify) | `0x0015` | Comandos e as respostas a eles |
| `FFF2` (write, notify) | `0x0018` | Utilizadores e histórico |
| `FFF3` (notify) | `0x001b` | Medições |

Todas as tramas seguem o formato `tipo, comprimento, dados`. O comprimento é o
número de bytes que vêm a seguir.

## Comandos (`FFF1`)

O inventário completo vem do `TorreDelegate` do SDK. Nem todos os modelos Torre
suportam tudo; o que a CF632 aceita confirma-se no aparelho (coluna "CF632").

Os comandos de definição seguem o formato `<cmd> 02 01 <valor>`, e os de leitura
`<cmd> 02 02 00`. A bateria não passa por aqui: lê-se na característica padrão
`2A19` (serviço `180F`), em %. Os comandos sem resposta foram testados a 2026-10-15
e a balança ignorou-os.

| Envio | O que faz | CF632 |
| --- | --- | --- |
| `02 00` | Pede o MTU (resposta `02 02 3d 00`) | ✔ |
| `04 06 <segundos LE> <fuso> <formato>` | Acerta o relógio. Segundos = unix + a parte do fuso abaixo da hora; `fuso` = horas, com o bit 7 como sinal; `formato` `01` = 24 h, `00` = 12 h | ✔ (`04 01 00`) |
| `05 02 01 <u>` / `05 02 02 00` | Define / lê a unidade | ✔ (lida: `00` = kg) |
| `06 02 01 <00/01>` / `06 02 02 00` | **Medição da frequência cardíaca**: `00` = ligada, `01` = desligada | ✔ De fábrica `00`. Responde `06 02 01 00` (ok) |
| `07 02 01 <00/01>` / `07 02 02 00` | **Medição da impedância**: `00` = ligada, `01` = desligada (o "modo grávida" da Lefu, sem corrente pelo corpo) | ✔ De fábrica `00`. Com `01` a balança só pesa |
| `2c 02 01 <0/1>` | Segunda variante da impedância | |
| `03 02 01 <n>` / `03 02 02 00` | Brilho do visor | ✘ sem resposta |
| `42 02 01 <n>` / `42 02 02 00` / `42 02 03 00` | Idioma / ler idioma / lista de idiomas | ✘ sem resposta |
| `51 02 01 <0/1>` / `51 02 02 00` | Ícone de Wi-Fi no visor | ✘ sem resposta |
| `58 02 01 <n>` / `58 02 02 00` | Mostrar um único indicador | ✘ sem resposta |
| `59 02 01 <n>` / `59 02 02 00` | Intervalo do G-sensor | ✘ sem resposta |
| `31 02 01 <tipo><estado>` / `31 02 02 00` | Modo demonstração | ✘ sem resposta |
| `01 02 <tipo> <estado>` | Mudar de modo | |
| `2e 05 01 <modo> <passo> <peso>` | Modo bebé (pesar ao colo) | |
| `1c 02 01 <0/1>` / `1c 02 02 00` | Estado de associação ao telemóvel (`deviceBindStatus`) | ✔ (lido `01`) |
| `24 00` / `28 00` | Começar / parar a medição | |
| `10 00` | Keep-alive | |
| `09 00` | Reiniciar | |
| `16 01 00`…`04` | Apagar tudo / utilizadores / histórico / Wi-Fi / definições | |
| `0e 01 00`, `27 00` | Estado do Wi-Fi, MAC do Wi-Fi | |
| `0b 00`, `0f 01 00/01` | OTA local / OTA por utilizador ou de teste | |

Antes do SDK pensávamos que `02 00` era a bateria e que `1c` ligava a FC. Por
isso enviámos `1c 02 01 01` nas sondas; mudou só o estado de associação. Nos
interruptores `06` e `07` o valor `00` liga e `01` desliga ("0打开 1关闭", na demo).

## Medição (`FFF3`, tipo `01`)

```
01 0d  ST FL  WW WW  00  HR  00  32 I1 I1  64 I2 I2
```

| Bytes | Campo |
| --- | --- |
| 2 `ST` | Fase da medição (bits) |
| 3 `FL` | `80` medição completa; `40` a balança vai desligar |
| 4–5 | Peso, `uint16` LE, em centésimos de kg |
| 7 `HR` | Frequência cardíaca em bpm; só é válida com `ST & C0 == 80` |
| 9, 12 | Frequência da medição da impedância, em kHz: `0x32` = 50, `0x64` = 100 (leitura nossa; o SDK não usa estes bytes) |
| 10–11 | Impedância em décimos de Ω, `uint16` LE. O SDK divide por 10 e guarda Ω inteiros |
| 13–14 | Segunda impedância, a 100 kHz, no mesmo formato. O SDK lê-a e descarta-a |

Os bits de `ST`, tal como o `onWeightDataProtocal` do SDK os lê:

| Máscara | Significado |
| --- | --- |
| `0C` | `04` = peso estável, `08` = excesso de peso |
| `30` | `10` = a medir a impedância, `20` = impedância medida (com sapatos não chega a aparecer) |
| `C0` | `40` = a medir a FC, `80` = FC medida (vem no byte 7), `C0` = FC falhou |

O SDK entrega a medição quando `FL` tem `80` e ignora uma segunda trama completa
que chegue nos 2 s seguintes. O hub deve fazer o mesmo e ignorar as intermédias.

Exemplos reais:

```
01 0d 35 80 fd 25 00 00 00 32 00 00 64 00 00   97,25 kg, calçado: sem impedância nem FC
01 0d e5 80 b2 25 00 00 00 32 7c 0f 64 9d 0d   96,50 kg, descalço: 396,4 / 348,5 Ω, FC falhou
01 0d a5 80 72 24 00 56 00 32 9f 10 64 aa 0f   93,30 kg, app do Marcus: FC 86 bpm
```

**A FC só é medida com um utilizador em linha** (`05 03 06`… `05 01 07`, ver
abaixo). Sem utilizador, a fase da FC termina logo em `C0`: foi o que aconteceu em
todas as nossas pesagens até 2026-10-15 às 10:41. Com o utilizador em linha, a
balança repete a trama `65` enquanto espera e entrega a FC cerca de 3 s depois:

```
01 0d 65 00 94 25 00 00 00 32 a0 0f 64 33 0e   a medir a FC, à espera do utilizador
01 0d a5 80 94 25 00 4a 00 32 a0 0f 64 33 0e   96,20 kg · 74 bpm · 400,0 / 362,7 Ω
```

## Outras tramas (`FFF3`)

O primeiro byte é o tipo. O SDK trata-as em `protocoDataF3`.

| Tipo | Conteúdo |
| --- | --- |
| `01`, `11` | Medição (acima) |
| `02` | **Impedância cifrada.** O byte 3 indica o tipo: `01` = 4 elétrodos, `02` = 4 elétrodos com 2 frequências (a CF632), outro valor = 8 elétrodos. Os bytes 4–7 e 8–11 são `uint32` LE: a impedância e a impedância a 100 kHz, cifradas |
| `03` | Impedância em claro, por canal (`03 04 20 <canal> <uint16 LE>`). O SDK lê-a e descarta-a |
| `04` | G-sensor acionado |
| `05` | A balança tem histórico novo |
| `06` | Estado da escolha de utilizador na balança |
| `16` | A medição da impedância falhou. O byte 4 indica o membro: `01`/`04` perna esquerda, `02`/`08` perna direita, `10`/`40` braço esquerdo, `20`/`80` braço direito |

**A impedância que conta para a composição corporal é a cifrada, da trama `02`.**
Em claro só há a que vem na medição, e o SDK só a usa nos modelos de cálculo
"Direct". A cifrada é a que o SDK guarda em `impedance` e
`ppImpedance100EnCode` e entrega ao algoritmo da Lefu (`ppcalculatekit`).
Também é o formato que o servidor Wi-Fi da Lefu recebe (`"impedance":
"920589"`). Exemplo real:

```
02 0a 18 02 | 10 53 6f 00 | ff 16 db 00   →  7295760 e 14358271
```

## Composição corporal

A balança calcula a composição e mostra-a no visor (5 valores rotativos), mas
**não a envia por BLE**. A app calcula os parâmetros a partir do peso, das duas
impedâncias cifradas e do perfil (altura, idade, sexo).

O cálculo corre localmente, em bibliotecas nativas da BestHealth que vêm no
`com.lefu:ppbasiccalculatekit` (1.1.1, no mesmo repositório Maven). Para 4
elétrodos com duas frequências (`PPDeviceCalcuteTypeAlternate4_1`, o caso da CF632)
usa-se `com.besthealth.bh3BodyComposition.BhTwoLegs240`, em `libbh3BodyComposition.so`.

- **Entra:** `bhZTwoLegsEnCode50Khz` e `bhZTwoLegsEnCode100Khz` (as impedâncias
  cifradas da trama `02`, o que confirma as frequências de 50 e 100 kHz), o peso e
  o perfil.
- **Sai:** a impedância decifrada, a gordura, a água, o músculo, o osso, a proteína,
  o metabolismo basal, a gordura visceral e a pontuação corporal.

Isto tem três limites para o hub:

- **As `.so` são para Android** (ABIs `arm64-v8a`, `armeabi-v7a`, `x86`, `x86_64`,
  ligadas à libc do Android). Não carregam num servidor Linux nem num Mac sem um
  ambiente Android.
- **Exigem licença.** O `PPCalculateKit` precisa de um secret associado ao nome do
  pacote da app; sem ele devolve `PP_ERROR_TYPE_SECRET`. O `PPBluetoothKit.initSdk`
  pede `appKey` e `appSecret`, que a Lefu emite.
- **Alternativa:** a mesma conta Lefu dá acesso à API de cálculo na cloud
  (<https://lefuhengqi-en.apifox.cn/>, "Dual-frequency AC four-electrode").

Sem licença, o hub fica com o peso, a FC e a impedância em claro (a de 50 kHz),
que vem na trama de medição.

## Utilizadores e histórico (`FFF2`)

A balança guarda utilizadores e atribui cada pesagem a um deles.

**Utilizador** (`TorreHelper.getSyncUserInfoHex`). O registo começa com o
`userID` e o `memberID`, cada um em ASCII com 64 bytes, completados com zeros.
Segue-se:

```
idade(1) sexo(1) altura_cm(2 LE) atleta(1) peso×100(2 LE) índice(1)
peso_alvo peso_ideal n(1) 7×peso(2 LE) 7×timestamp(4 LE) nome(bitmap) [data de nascimento]
```

No snoop, `3c 01 a9 00` = 60 anos, homem, 169 cm. O envio começa com
`05 03 01 <comprimento LE>`, segue-se o registo partido conforme o MTU, e o fim
leva `05 01 02`.

**Utilizador em linha** (`sendCurrentUserinfoStart`): `05 03 06 <comprimento LE>`,
depois o `userID` e o `memberID`, e no fim `05 01 07`. A balança atribui a esse
utilizador a pesagem seguinte. As respostas (`processSyncUserInfo`) chegam como
`05 02 <passo> <resultado>`. O resultado `00` é sucesso; os outros valores são
falha, "a escolher utilizador na balança", "falta o nome" ou "memória cheia".

**Histórico** (`historyItem`). O histórico pede-se por utilizador:
`02 03 07 <comprimento LE>`, depois o `memberID`, e no fim `02 01 08`. Cada
registo tem 16 bytes:

```
timestamp(4 LE)  peso(2 LE)  FC(1)  tipo_impedância(1)  impedância cifrada(4 LE) …
```

O tipo de impedância usa os mesmos códigos da trama `02`. O SDK descarta
registos anteriores a 2023-02-03.

Isto resolve a atribuição de uma pesagem a uma pessoa: o hub regista cada
pessoa na balança e define-a como utilizador em linha antes da pesagem, ou lê o
histórico de cada utilizador.

A conversa completa da app está em
`~/dev/marcus-docs/balanca-cf632-sniffer/marcus-app-conversa-gatt.txt`.
