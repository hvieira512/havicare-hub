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
- **2026-10-15, à tarde:** pesagens emparelhadas, primeiro na app do fabricante
  (`com.lefu.xin`) e logo a seguir no gateway, com perfis diferentes, para validar
  a composição corporal (ver abaixo).

A CF621BLE (4 barras) também fala Torre: faz o mesmo arranque, manda as mesmas
tramas, e a composição dá o mesmo que na CF632.

## Transporte

**Só GATT.** A balança anuncia-se com dados de fabricante `xx cf` (o primeiro byte
variou: `4c`, `35`), seguidos dos 5 últimos bytes do MAC e de 12 zeros, e com o
nome `CF632` ou `CF621_G`. Uma pesagem completa não muda o anúncio, por isso os gateways MOKO
não servem para esta balança.

A balança só acorda quando alguém sobe para ela (ou se carrega nela) e desliga
a ligação cerca de 30 s depois de a pessoa sair.

| | CF632 | CF621 |
| --- | --- | --- |
| Nome anunciado | `CF632` | `CF621_G` |
| Firmware (`2A26`) | `005.015` | `007.006` |
| MTU (resposta a `02 00`) | `3d` (61) | `f4` (244) |

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
| `04 06 <segundos LE> <fuso> <formato>` | Acerta o relógio. Segundos = unix + a parte do fuso abaixo da hora; `fuso` = horas, com o bit 7 como sinal; `formato` `01` = 24 h, `00` = 12 h. Resposta `04 01 <r>`: `00` aceite, outro valor recusado (o SDK só regista "syncTime fail") | ✔ (`04 01 00`). A **CF621 recusa** (`04 01 01`) |
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

A CF621 recusou o acerto do relógio em todas as variantes testadas a 2026-10-15:
`formato` `01` e `00`, `fuso` `01` e `00`, e segundos em hora local. O registo
de utilizador, a escolha do utilizador em linha e a medição funcionam. Só fica
por acertar a hora das pesagens que a balança guarda no histórico.

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
| 13–14 | Segunda impedância, a 100 kHz, no mesmo formato. O SDK lê-a e descarta-a; é a que a fórmula da composição usa (ver abaixo) |

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
01 0d a5 80 94 25 00 4a 00 32 a0 0f 64 33 0e   96,20 kg · 74 bpm · 400,0 / 363,5 Ω
```

**Descalço e sem meias.** A impedância e a FC medem-se pelos elétrodos, através
da pele. Com meias a FC falha (`ST & C0 == C0`) e a impedância varia muito de
pesagem para pesagem (de 338 a 406 Ω no mesmo corpo, a 2026-10-15). Descalço, a
FC mediu em todas as pesagens com o gateway e a impedância a 100 kHz ficou entre
385 e 391 Ω.

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

A balança calcula a composição e mostra-a no visor, mas **não a envia por BLE**.
O visor roda cinco valores, por esta ordem: massa óssea (kg), gordura (%),
músculo (%), água (%) e IMC.

No SDK, o cálculo corre em bibliotecas nativas da BestHealth que vêm no
`com.lefu:ppbasiccalculatekit` (1.1.1). Para 4 elétrodos com duas frequências
(`PPDeviceCalcuteTypeAlternate4_1`, `CalculateHelper4TwoChannel`) usa-se
`com.besthealth.bh3BodyComposition.BhTwoLegs240`, em `libbh3BodyComposition.so`.
As `.so` são para Android e exigem um secret da Lefu associado ao pacote da app.

**Não é preciso a biblioteca para os valores principais.** A fórmula pública do
MiScale/OneByone (a mesma do openScale), aplicada à impedância **em claro de
100 kHz** (bytes 13–14 da medição) e ao peso medido, dá os mesmos valores que a app
do fabricante. As regras derivadas vêm do código Java do SDK (`CalculateUtil`,
`CalculateHelper4TwoChannel`), que não é nativo.

```
c0     = h·9,058/100·(h/100) + P·0,32 + 12,226 − Z100·0,0068 − idade·0,0542
sub    = homem 0,8 · mulher ≤ 49 anos 9,25 · mulher > 49 anos 7,25
k      = 1; homem P < 61 → 0,98; mulher P > 60 → 0,96 (×1,03 se h > 160);
         mulher P < 50 → 1,02 (×1,03 se h > 160)
gordura %      = (1 − (c0 − sub)·k / P)·100            (> 63 → 75)
osso kg        = c0·0,05158 − (homem 0,18016894 | mulher 0,245691014)
                 ± 0,1 (+ se > 2,2, − se não); homem > 5,2 ou mulher > 5,1 → 8
água %         = (100 − gordura)·0,7, × 1,02 se esse valor < 50, senão × 0,98
massa gorda    = P·gordura/100;  massa livre de gordura = P − massa gorda
massa muscular = massa livre de gordura − osso;  músculo % = massa muscular / P·100
IMC            = trunc(P/(h/100)²·10)/10, mínimo 10
```

Regras do SDK em Java:

```
gordura subcutânea % = gordura · 2/3
peso ideal kg        = trunc(22·(h/100)²·10)/10   (a app trunca; o SDK tira-o da biblioteca)
controlo de peso     = peso ideal − P             (a app mostra-o sem sinal)
controlo muscular    = máx(0, peso ideal·(homem 0,797 | mulher 0,724) − massa muscular)
controlo de gordura  = homem: IMC ≤ 22,5 → (massa gorda < 8,5 ? 9 − massa gorda : 0)
                       mulher: IMC ≤ 21 → (massa gorda < 10,5 ? 10,2 − massa gorda : 0)
                       senão f5·IMC²/idade + idade·f4/IMC + IMC·f3 + idade·f2 + f
                       homem f=24,1589 f2=−0,6282 f3=−0,5865 f4=9,8772 f5=−0,368
                       mulher f=67,2037 f2=0,6351 f3=−2,6706 f4=−18,1146 f5=−0,2374
```

Validação de 2026-10-15, com pesagens emparelhadas e descalço: primeiro na app e,
logo a seguir, no gateway, que dá a impedância.

| Perfil | Peso | Z100 | Gordura: app / fórmula |
| --- | --- | --- | --- |
| Homem, 23 anos, 181 cm | 96,70 / 96,60 kg | 379,0 Ω | 29,5 / 29,41 % |
| Mulher, 21 anos, 168 cm | 96,80 kg | 370,0 Ω | 43,0 / 42,94 % |
| Mulher, 54 anos, 168 cm | 96,95 kg | 388,9 Ω | 42,9 / 42,89 % |
| Homem, 60 anos, 181 cm | 96,95 kg | 385,4 Ω | 31,7 / 31,66 % |
| Homem, 23 anos, 165 cm | 96,90 / 97,00 kg | 390,6 Ω | 34,8 / 34,79 % |

Em todos, a massa gorda, a água, o osso, o músculo, a massa livre de gordura, o
IMC, a gordura subcutânea, o controlo de gordura e o peso ideal bateram a menos de
0,1. A app mostra o osso e a água truncados a uma casa (3,48 aparece como 3,4).
A CF621 dá o mesmo: com Z100 389,0 Ω e 96,20 kg (homem, 23 anos, 181 cm), a gordura
dá 29,32 %.

**O modo atleta não se reproduz.** Com o perfil de atleta (homem, 23 anos, 181 cm,
96,95 kg, Z100 390,6 Ω), a app deu 22,1 % de gordura, 3,7 kg de osso e 53,6 % de
água; a fórmula normal dá 29,6 %. Nem o fator de atleta da OneByone (×1,0958) nem um
fator único na massa magra acertam em todos os campos. A gordura subcutânea (2/3)
e o controlo de gordura continuam a seguir as regras de cima.

**Só a biblioteca nativa dá:** metabolismo basal, gordura visceral, proteína,
músculo esquelético, pontuação e idade corporal. Nos casos medidos, a proteína ficou
perto de 0,35 × músculo esquelético %, mas sem o músculo esquelético isso não serve
de fórmula.

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
