# Dispensador M228 — parâmetros do tipo `0x02`

> A tabela de consulta do protocolo. O capítulo que se lê é o
> [19 — Dispensador de comprimidos](19-dispensador-de-comprimidos.md); isto é o anexo que se
> abre quando se quer uma TAG.

> **O anexo tem duas tabelas, uma por tipo de dispositivo, e dezassete TAGs
> mudam de significado entre elas.** O M228 é o tipo `0x02`; a tabela do `0x01` é
> de outro aparelho. Ler o M228 pela tabela errada não dá erro nenhum — dá campos
> com nomes plausíveis e conteúdo trocado. Foi o que aconteceu com o `0x8107`,
> que no tipo `0x01` é a tampa e aqui é o trinco do prato, com a polaridade ao
> contrário: a dashboard anunciou «Aberta» enquanto o prato estava trancado. Ao
> acrescentar uma TAG, confirmar sempre em que tabela se está a ler.

## Estado

| TAG | O quê | Notas |
|---|---|---|
| `0x8101` | medicação | `0` normal · `1` a acabar · `2` sem medicação |
| `0x8103` / `0x8104` | bateria | nível, e estado `0` normal · `1` cheia · `2` fraca · `3` a carregar · `4` sem bateria |
| `0x8102` | bloqueio de criança | `0` destrancado · `1` trancado |
| `0x8105` | estado do não incomodar | `0` desligado · `1` ligado · `2` **ligado e a silenciar agora** — descodificado, não publicado (ver secção do contrato) |
| `0x8106` | copo da medicação | `0` retirado · `1` colocado. **Responde sempre `1` no firmware `0x0502`, e por isso não é normalizado.** Testado a 25/09/2026 com o copo fora do aparelho, e com pedidos de actualização repetidos pela dashboard. É o mesmo caso do `0x8107`: os dois sensores de estado físico desta unidade estão presos, cada um no seu extremo. **O fornecedor confirmou-o a 2026-09-28**: *«0x8106 only reported during initialization and not used afterward»* |
| `0x8107` | tranca do prato | `0` destrancado · `1` trancado. **Responde sempre `0` no firmware `0x0502`, e por isso não é normalizado.** Testado a 25/09/2026 com o prato trancado à mão, com a fechadura de chave lateral trancada, e com o prato removido do aparelho: as três leituras deram `0`. Uma telemetria que só sabe dizer um valor não é telemetria, e num ecrã em que tudo o resto é verdade, um campo que nunca muda ensina a não confiar nos outros. O fornecedor respondeu a 2026-09-28 e fechou a questão: *«0x8107 not used»*. Não reflecte mecanismo nenhum, em firmware nenhum |
| `0x8109` | alimentação DC | `0` desligada da corrente · `1` ligada |
| `0x810A` / `0x810B` | sinal WiFi e GSM | INT16S, −300 a 300. **As duas escalas não são a mesma**: o WiFi dá dBm, e o `0x810B` de uma unidade 4G dá o **CSQ do módulo**, de 0 a 31. Ver abaixo |
| `0x810C` / `0x810D` | nível de sinal | a escala grosseira |
| `0x810E` / `0x810F` | **temperatura e humidade** | INT8S de −40 a 120 °C, INT8U de 0 a 100 %RH — **um byte cada**, ao contrário do sinal, que é INT16S |
| `0x8111` | alarme de temperatura/humidade | `0` normal · `1` em alarme — é o juízo que o aparelho faz sobre os dois anteriores |
| `0x8112` | chamada de emergência | `0` normal · `1` em curso |
| `0x811A` / `0x811B` / `0x811D` | célula actual, total e restantes | o `0x811B` é a **capacidade do prato**, não quantas vão carregadas — essas são a configuração `0x101C`. Conta **posições**, e o aparelho responde 29: a zero é a de repouso e não leva medicação, por isso o contrato publica 28. O `0x811D` desce a partir do `0x101C`: o aparelho não vê lá dentro, e o que lhe dissermos é o ponto de partida que ele acredita |
| `0x8121`–`0x8125` | falhas | rotação, reset do prato, empurrador, porta da célula, teclas |
| `0x8131`–`0x8139` | **estado de toma de cada um dos nove alarmes** | São **oito** e são **duas fases**: `0` nada · `1` a preparar · `2` à espera de sair · `3` **saiu, à espera de ser levantada** · `4` não chegou a sair · `5` **saiu e não foi levantada** · `6` falhada · `7` tomada. O aparelho primeiro empurra a dose para fora e só depois espera que alguém a levante, e cada fase tem o seu tempo esgotado |

## O que este firmware anuncia saber dizer

A descoberta de parâmetros (`0x0B`) devolveu **43 TAGs de estado**, e são estas:

```
0x8002 0x8003 0x8004 0x8005 0x8006 0x8007 0x8008 0x8009 0x800A 0x800B
0x8081 0x8082
0x8101 0x8102 0x8103 0x8104 0x8105 0x8106 0x8107 0x8109 0x810B 0x810D
0x810E 0x810F 0x8111 0x8112
0x811A 0x811B 0x811D
0x8121 0x8122 0x8123 0x8124 0x8125
0x8131 0x8132 0x8133 0x8134 0x8135 0x8136 0x8137 0x8138 0x8139
```

Duas coisas que só a lista responde.

**Não há compartimento de destino.** As únicas TAGs de célula são as três acima —
actual, capacidade e restantes. O aparelho não diz para onde o prato vai a
seguir, e quem quiser sabê-lo tem de o deduzir do próximo alarme marcado. O hub
não o publica: seria um campo calculado por nós com cara de leitura dele.

**O `0x810A` não está na lista**, nem o `0x810C`. É a confirmação, vinda do
próprio aparelho, de que esta unidade é 4G e não tem rádio WiFi nenhum — só o
`0x810B` e o `0x810D` respondem.

> **Numa unidade 4G o `0x810B` é CSQ, não dBm.** O fornecedor deu a tabela a
> 2026-09-28: *«The WiFi unit is indeed dbm. For the 4G version, the CSQ returned
> by module transmission is as follows»* — `0` abaixo de −113 dBm, `1` a −111,
> `2`–`30` de −109 a −53, `31` acima de −51. É a escala do 3GPP, e cabe numa
> conta: **dBm = −113 + 2 × CSQ**.
>
> O hub publicava o número em cru com o sinal trocado, e por isso mostrava
> **−29 dBm** a um aparelho cujo sinal real eram **−55**. Vinte e seis dB de
> diferença, num campo que serve para decidir se vale a pena ir lá.
>
> A conversão distingue as duas escalas pelo sinal do número, porque não se
> sobrepõem: dBm de rádio é sempre negativo e o CSQ vai de 0 a 31. Um valor já
> negativo passa como está, o que cobre a variante WiFi e um firmware que venha a
> reportar dBm.
>
> **O `99` fica de fora.** A tabela do fornecedor dá-lhe −51 dBm, o mesmo que o
> `31`, mas no 3GPP TS 27.007 o `99` é *«not known or not detectable»*. Publicar
> o melhor valor da escala para dizer «não sei» é a pior troca possível neste
> campo, e por isso o hub não publica leitura nenhuma. Está por confirmar com
> eles.

> **O `0x8105` e o `0x8106` não estão *deprecated*.** Uma versão anterior deste
> capítulo dizia que sim, e estava a ler a tabela do **tipo 01** — onde são «Pill
> Tray ID» e «Low battery voltage», essas sim retiradas. No tipo 02 são o «não
> incomodar» e o copo da medicação, ambos correntes. É a quarta vez que a troca
> das duas tabelas nos morde, e é a razão do aviso da secção 5.

### O bloco de sistema, que vem no registo

O bloco `0x8002`–`0x800B` mais o `0x8081`/`0x8082` é identidade e transporte, e
**o aparelho manda-o inteiro em cada pacote de registo `0x01`** — as doze TAGs,
sem ninguém pedir. É por isso que o registo tem 112 bytes e o heartbeat 53. Os
valores desta unidade, em 2026-09-23:

| TAG | O quê | Valor |
|---|---|---|
| `0x8002` | versão | `1282` (`0x0502`); a especificação não diz como se lê o número |
| `0x8003` | tamanho máximo do pacote | 300 bytes, dos 1400 que a especificação permite |
| `0x8004` | intervalo de heartbeat | 60 s, que é o que se observa no fio |
| `0x8006` | tempo de espera pela resposta | 60 s, a meio da gama 10–120 |
| `0x8007` | retransmissões depois do tempo esgotado | 2 |
| `0x8008` | sincronização de parâmetros | `0`, não forçada |
| `0x800A` | ligação a utilizador | `0`, não obrigatória |
| `0x800B` | tempo online antes de dormir | `0`, que na especificação quer dizer **nunca dorme** |
| `0x8081` | funções extra | `4`, o bit 2 — a especificação só documenta o bit 0 (troca de servidor) e o bit 1 (OTA por Bluetooth) |
| `0x8082` | número personalizado | `0` |

Onze das doze ficam por publicar: são parâmetros do transporte e ninguém age
sobre eles. A que sai é a **versão**, pela capacidade `firmware_version` que os
relógios e as pulseiras já usam — é a única que muda ao longo da vida do
aparelho, e é o que se quer saber quando um lote vem com firmware mau. Vai em
hexadecimal, `0x0502`, porque a especificação não diz como se lê o número e o
decimal `1282` esconderia a única estrutura visível nele.

Não é pedível, e nem sequer pelo «Atualizar»: só o registo a traz, e o registo
é do aparelho. Um firmware novo chega sempre depois de um religar, e o religar
traz um registo.

**Estas TAGs lêem-se com o `0x07` e não com o `0x05`.** As `0x8xxx` são todas
estado, mesmo as que parecem definições: um `0x05` com as dez voltou com as dez
recusadas, todas com o estado `001`, «TAG inválida». É a mesma linha que a
descoberta já dizia — o `0x0A` devolve 54 TAGs de configuração e nenhuma delas é
do bloco de sistema.

> **«Tomada» não quer sempre dizer «tomada à hora».** Uma dispensa manual — o
> comando `0xA123` ou o botão verde do aparelho — consome a dose do **próximo
> alarme marcado** e dá-o como tomado, mesmo que a hora dele ainda esteja longe.
> Observou-se com o aparelho na mesa: um «Dispensar agora» às 13:10:50 marcou como
> tomado o alarme das 20:00, e dezasseis segundos depois chegou uma notificação
> `0x04` a dizê-lo. O compartimento actual avançou e os restantes desceram um.
>
> **E não dispensa se não houver alarme nenhum à frente.** Sem um alarme marcado
> por vir ainda nesse dia, o aparelho responde `0xA123 = 01` — «consegui» — e não
> faz absolutamente nada: não roda, não muda estado, não manda notificação. Medido
> a 24/09/2026: três ordens seguidas com o único alarme já passado, todas aceites e
> ignoradas; a seguir marcou-se um alarme para as 22:00 e a ordem seguinte rodou. É
> uma recusa silenciosa, e não há forma de a distinguir de um sucesso pela resposta.
>
> **O ecrã do aparelho denuncia-a antes de a ordem sair.** Quando não há mais
> nenhum slot por dar nesse dia, o ecrã principal deixa de anunciar um próximo
> alarme, embora o menu continue a mostrar os alarmes configurados. Observado a
> 29/09/2026 às 12:57, com o 13:30 e o 14:30 ainda por chegar mas os dois já em
> estado `7`: o aparelho dizia que não tinha próximo e um `0xA123` teria sido
> ignorado. É o único sinal legível desta regra — pelo protocolo, ela só se deduz
> somando os nove estados.
>
> **O número do slot é uma etiqueta, e não a ordem das tomas.** Medido a
> 29/09/2026 com o slot 1 às 14:00 e o slot 2 às 13:20: o ecrã anunciou o
> **13:20**. Quem decide é o relógio, e o prato anda um compartimento por dose
> seja qual for o slot que a pediu — a enésima toma do dia leva o enésimo
> compartimento. Não há por isso nada a ordenar do lado do hub, e ordenar seria
> nocivo: os slots guardam o estado do dia, e trocar horas entre eles a meio do
> dia salta ou repete uma toma.
>
> **Um slot só dá a dose dele uma vez por dia, e mudar-lhe a hora não a devolve.**
> É a segunda metade da regra de cima, e sozinha explica quase todas as recusas.
> Medido a 24/09/2026: cinco voltas a reescrever o **slot 1** com horas diferentes
> — 18:00, 19:00, 20:00, 21:00, 22:00, todas à frente da hora do aparelho — não
> moveram o contador um único passo. Marcados os **nove slots** em horas distintas,
> as ordens seguintes andaram `+1` cada uma, seis vezes seguidas, de 24 a 1. O que
> tem de variar é o slot, não a hora. São por isso nove dispensas manuais por dia,
> no máximo, e o contador delas é do dia do **relógio do aparelho**: adiantá-lo um
> dia com o `0xA101` devolve os nove — foi assim que se deu a volta completa ao
> prato para desencravar um objecto lá dentro.
>
> **Uma dose é um compartimento, e é sempre um passo.** Medido três vezes com
> configurações diferentes — dois alarmes marcados, nove, e dois outra vez — o
> `0x811A` andou `+1` em todas. O número de alarmes decide **quantas vezes por dia**
> a dose sai, nunca o tamanho do salto. E o prato mexe-se mesmo: confirmado à vista
> na terceira medição, de 22 para 23.
>
> **A avaria do prato não bloqueia a dispensa.** O `0x8122` esteve em `01` durante
> as três medições e nenhuma delas falhou por causa dele.
>
> **A volta fecha em `0`, e aí o `0x811D` fica preso a zero.** O `0x811A` conta
> `…27`, `28`, `0` — a posição de repouso — e depois `1`. Enquanto está em `0` os
> restantes ficam a zero e a dispensa seguinte é recusada; reescrever os
> «compartimentos carregados» repõe a contagem e desbloqueia.
>
> **Nada denuncia uma dose que não caiu.** A dispensa é por gravidade e o aparelho
> não tem sensor de queda: os contadores andam, o alarme fica «tomado» e a
> notificação `0x04` sai na mesma, com a dose ainda no compartimento. Medido a
> 24/09/2026 com uma chiclete, que passou pelo furo nove vezes sem cair por estar
> colada. Só serve medicação solta e seca.
>
> **O que bloqueia é uma dose por concluir.** Enquanto um alarme estiver em `2` ou
> `3`, as ordens seguintes são aceites e ignoradas. Reescrever o plano de medicação
> limpa os nove estados e desbloqueia — foi a única coisa que o desfez, entre o
> reinício do aparelho, o `0xA103` e o `RestartCycle` do menu.
>
> As notificações `0x04` trazem **só o que mudou** — um alarme, não os nove. É por
> isso que uma leitura vinda de uma notificação traz a lista incompleta, e só um
> `0x07` dá o estado dos nove de uma vez, e só nessa leitura é que as contagens de
> tomadas e falhadas são totais.

A temperatura e a humidade **não existem na API REST**. As falhas, que na API
REST eram um único `rotate`, aqui vêm discriminadas em cinco.

## Configuração

| TAG | O quê |
|---|---|
| `0x1001`–`0x1003` | idioma, formato de data, formato de hora |
| `0x1004`–`0x100A` | período de validade dos alarmes e respectivo interruptor |
| `0x100B`–`0x100E` | som das teclas, bloqueio de criança, toma antecipada, **chamada de emergência** |
| `0x1012` / `0x1013` | tipo de toque e volume — o toque vai de `0` («nenhum») a `3`. A tabela do tipo de dispositivo 02 dá máximo `3` e enumera na mesma um «Ringtone 4» que ficou do tipo 01; o aparelho recusa o `4` |
| `0x1014` / `0x1015` | calibração automática de relógio, fuso horário |
| `0x1017` / `0x1018` / `0x1019` | aviso de atraso, tempo até falha, e o interruptor que decide se uma dose já dada como falhada continua acessível — é ele que faz existir o desfecho `abnormal` do evento de toma |
| `0x101C` | **até que compartimento o prato está cheio** — um índice, e não uma contagem |
| `0x1021`–`0x1029` | **hora de cada um dos nove alarmes** |
| `0x1031`–`0x1039` | minuto de cada alarme |
| `0x1041`–`0x1049` | interruptor de cada alarme — **inerte neste firmware**, ver abaixo |
| `0x1051`–`0x1055` | não incomodar: interruptor e janela |
| `0x8004` / `0x800B` | intervalo de heartbeat, tempo de permanência online — a especificação põe-nos aqui, mas o aparelho só os serve como estado |

**São nove alarmes, não seis.** A API REST só expõe seis.

**Cinco destas o hub nunca mandou, e nenhuma precisa de ser mudada.** Lidas do
aparelho a 01/10/2026, todas com estado `0`:

| TAG | O quê | Valor |
|---|---|---|
| `0x1002` | formato de data | `1` — `DD-MM-YYYY`, e o ecrã cumpre-o. A gama vai a `2`; a ficha descreve os dois primeiros e o terceiro é a ordem que falta, com o mês à frente |
| `0x1003` | formato de hora | `0` — 24 horas |
| `0x100B` | som das teclas | `1`, ligado |
| `0x100E` | chamada de emergência | `1`, ligada |
| `0x1014` | acerto automático do relógio | `1`, ligado |

A única com valor de produto é o `0x100E`: é o interruptor que liga e desliga a
chamada de emergência, e a dashboard não o expõe. A ficha dele declara gama `0`
a `3` e descreve só o `0` e o `1` — os outros dois não estão documentados em
lado nenhum.

**O `0x1055` fixa-se.** Apesar de a descoberta não o anunciar, escrever `47` no
minuto final do não incomodar devolveu `47` com estado `0`. A janela que o hub
manda vale inteira.

## Um alarme desliga-se esvaziando-o, e não pelo interruptor

O `0x1041`–`0x1049` não faz nada neste firmware. Um alarme sai como uma hora
preenchida ou como o par `24:60`, que é o «sem alarme» do próprio aparelho.

Medido a 25/09/2026, com o interruptor como única variável:

| | |
|---|---|
| `0x1049 = 0`, alarme às 16:12 | **tocou e dispensou** |
| `0x1049 = 1`, alarme às 16:10 | tocou e dispensou |
| o ecrã do aparelho | desenha os dois casos **iguais** — não há lá nada para desligar |
| leitura de volta | devolve o que se escreveu, `0` ou `1`, com estado `0` |

Aceita, guarda, devolve, e ignora. É uma gaveta.

**Reconfirmado no firmware `0x0503`, a 01/10/2026.** Com o interruptor desligado,
o alarme 2 tocou às 15:51 e o alarme 1 às 16:04, cada um a gastar o seu
compartimento. A escrita foi aceite com as 27 TAGs e nenhuma recusada.

> Uma medição intermédia dessa tarde pareceu dizer o contrário — um alarme
> desligado ficou calado —, e é o caso sem explicação descrito a seguir. Um
> resultado animador numa experiência contra hardware não se dá por bom sem o
> controlo que o tente derrubar.

## Um alarme que não tocou, e continua por explicar

**Um alarme marcado para dois minutos à frente toca.** Medido a 02/10/2026, com
os dois alarmes na mesma trama e ambos com o interruptor ligado:

| plano entregue | alarme | distância | |
|---|---|---|---|
| 09:14:02 | 09:16 | 1 min 58 s | tocou |
| 09:14:02 | 09:19 | 4 min 58 s | tocou |

Fica aqui porque houve **um** caso em que não tocou, a 01/10/2026: plano entregue
às 15:37:04 com o alarme 1 às 15:39 e o alarme 2 às 15:42, e só o segundo tocou.
A escrita foi aceite com as 27 TAGs e nenhuma recusada.

Uma versão anterior deste capítulo explicava-o pela distância — «menos de dois
minutos não arma» — a partir dessa observação única. **A medição de 02/10 desmente
isso**, e a causa continua por saber. A única diferença que separa o caso silencioso
de todos os que tocaram: nele o alarme ia com o **interruptor desligado e havia
outro alarme ligado na mesma trama**. Um interruptor desligado sozinho não cala
nada — está medido duas vezes —, mas a combinação nunca foi repetida.

Enquanto não for, não se escreve aqui uma regra. O que se sabe é que aconteceu
uma vez.

**`00:00` não serve de vazio: é meia-noite a sério.** Com os nove slots a zero e
o relógio do aparelho posto às 23:57, à meia-noite o alarme 1 percorreu
`1 → 2 → 3 → 7` e o carrossel andou de 7 para 8. Um slot deixado por preencher
custa uma dose por dia.

**`24:60` é o vazio, e o aparelho aceita-o de volta.** Escrito à mão nos oito
slots não usados, voltou como `0x18`/`0x3C` com estado `0`, o ecrã passou a
mostrar um só alarme, e a meia-noite seguinte passou sem tocar e sem mexer o
carrossel — compartimento 10, restantes 18, antes e depois. É o que o hub
escreve hoje, e é por isso que o cartão da dashboard não tem interruptores.

> O sentinela já era conhecido na dashboard — ela desenha vazio acima de 24 —,
> mas o construtor limitava a hora a 23 e esmagava-o contra o tecto. Era essa a
> raiz de o «desligar» escrever meia-noite.
>
> **O fornecedor confirmou-o a 2026-09-30:** *«24:60 is used as the value when no
> alarm is set»*. A gama que a especificação publica para o `0x1021`–`0x1029` é
> 0–23, e o sentinela está fora dela: não é lapso nosso, é o documento que não o
> descreve.

**O `0x100A` é honrado**, e é outra coisa: liga a validade por datas. Com um
intervalo já terminado, o plano inteiro desaparece do ecrã e não toca, mesmo com
o interruptor do alarme ligado. Serve o intervalo de datas, não o desligar de um
alarme sozinho.

**Alarmes à mesma hora fundem-se num só.** Nove slots marcados para as 16:34
deram uma dose: compartimento 8 → 9, restantes 20 → 19, e os alarmes 2 a 9 nunca
saíram do estado `0`. Deixou de ser preciso desde que o `24:60` funciona, mas
explica porque é que os nove slots a `00:00` davam uma dose à meia-noite e não
nove.

## O que este firmware anuncia aceitar

A descoberta de parâmetros (`0x0A`) devolveu **54 TAGs de configuração**, que são
as da tabela acima menos o bloco de sistema `0x8004`/`0x800B`:

```
0x1001 0x1002 0x1003 0x1004 0x1005 0x1006 0x1007 0x1008 0x1009 0x100A
0x100B 0x100C 0x100D 0x100E
0x1012 0x1013 0x1014 0x1015 0x1017 0x1018 0x1019 0x101C
0x1021 0x1022 0x1023 0x1024 0x1025 0x1026 0x1027 0x1028 0x1029
0x1031 0x1032 0x1033 0x1034 0x1035 0x1036 0x1037 0x1038 0x1039
0x1041 0x1042 0x1043 0x1044 0x1045 0x1046 0x1047 0x1048 0x1049
0x1051 0x1052 0x1053 0x1054 0x1055
```

**Três TAGs da especificação não estão na lista** e uma versão anterior deste
capítulo dava-as como aceites: o `0x101A` (célula actual), que neste firmware é
só estado — `0x811A` —, o `0x101D` (**limiar de aviso de medicação a acabar**) e
o `0x1063` (pausa do toque). Nenhuma configuração exposta assenta nelas:
aparecem apenas na tabela de tipos do adaptador, e o `DeviceCommandCatalog`
nunca as emite.

> O `0x101D` foi perguntado ao aparelho a 24/09/2026 e voltou com **«TAG
> inválida»**. Quer dizer que **o limiar do aviso não é configurável** neste
> firmware: o aparelho decide sozinho a partir de que ponto diz que a medicação
> está a acabar. Uma versão anterior deste capítulo chamava-lhe «células
> restantes», que é o nome dele na tabela do **tipo 01** — mais um caso da
> confusão que o aviso da secção 5 descreve.

Também não estão anunciados o `0x1011` (duração do toque) nem o `0x1061` (tempo
de pressão para a chamada de emergência), pela mesma razão que o `0xA124` e o
`0xA125`: existem na especificação da série, não no firmware deste aparelho.

## Controlo

`0xA001` reiniciar · `0xA002` reposição de fábrica · `0xA003` cancelar
sincronização forçada · `0xA004` novo registo · `0xA101` calibrar relógio ·
`0xA102` silenciar · `0xA103` repor o prato · `0xA123` toma antecipada.

> **O `0xA001` é confirmado sem o aparelho reiniciar, e o `0xA004` é que serve.**
> Medidos a 01/10/2026 no firmware `0x0503`. O reinício voltou acusado sem registo
> novo e sem falha de heartbeat — a sessão TCP nunca se interrompeu. O novo
> registo também é acusado de imediato, mas **setenta segundos depois chega um
> `0x01` a sério**, numa sessão nova.
>
> Isto resolve um problema prático do hub: as tramas em fila só saem quando um
> aparelho se autentica, e até aqui a única forma de o provocar era reiniciar o
> serviço. Com o `0xA004` ao fim de cada lote, o lote seguinte entrega-se sozinho.

A lista acaba aqui. O `0xA124` (rodar para uma célula indicada) e o `0xA125`
(pausa da medicação) **não existem neste firmware**: um `0xA124` mandado à mão é
acusado com o valor ecoado — `0E`, `05`, `19` — sem que o prato mexa e sem que o
`0x811A` mude. Medido a 24/09/2026
três vezes, com a avaria `0x8122` activa e depois com ela limpa, para excluir que
fosse o índice do prato a ser recusado.

A razão é o **tipo de dispositivo**, e são TAGs que este aparelho nunca vai ter.
O `0xA124`, o `0xA125` e ainda o `0xA121`/`0xA122` (SSID e palavra-passe de WiFi)
estão na secção **8.3, «TAG Definition - Device Type 01»**, que vai da linha 1522
à 1951 do documento. A secção **8.4**, que é a nossa, começa na 1952 e a lista de
controlo dela acaba mesmo no `0xA123`. O fornecedor confirmou-o a 2026-09-28:
*«The parameters mentioned in question 1 are all non-M2 series parameters»*.

> Uma versão anterior deste capítulo dizia exactamente isto, e eu «corrigi-a» a
> 25/09 para a idade do firmware, sem reabrir o documento. Estava certa e ficou
> errada. É a quinta vez que as duas tabelas de TAGs enganam alguém neste
> capítulo, e a única defesa é confirmar em que secção a linha está antes de
> afirmar seja o que for sobre ela.

O `0x1063` (pausa do toque) é outro caso e esse **é** do tipo `0x02`, na linha
2072 — existe para nós, mas este aparelho recusa-o: lido a 01/10/2026 no firmware
`0x0503`, voltou com estado `1`, TAG inválida. O mesmo para o `0x101D`.

> **A descoberta não é exaustiva, e por isso não serve de prova de ausência.**
> Três TAGs que ela não anuncia funcionam: o `0x8139` (estado de toma do nono
> alarme) e o `0x1055` (minuto final do não incomodar) respondem com estado `0`,
> e o `0xA123` é a toma antecipada que o hub usa todos os dias. Confirmado a
> 01/10/2026 nas três listas. O argumento que vale contra o `0xA124`/`0xA125`
> continua a ser o das duas tabelas e a palavra do fornecedor; o «a descoberta não
> os anuncia» não vale nada. A prova de ausência que vale é a leitura voltar com
> estado `1`.

**O `0xA021`–`0xA023` está anunciado e o `0x8081` diz que não.** A lista de
controlo traz as três TAGs do servidor, mas o identificador de funções adicionais
veio `4` — só o bit 2, actualização remota —, com o bit 0 («server switching») a
zero. Não está medido qual dos dois manda, e enquanto não estiver, reapontar este
aparelho para outro servidor é uma hipótese e não um procedimento.

Não há por isso forma remota de rodar o prato sem consumir uma dose: o `0xA103`
reassenta-o sem mexer no contador, e o `0xA123` anda um compartimento mas gasta a
dose de um slot. Quem precisar de dar a volta ao prato — para desencravar alguma
coisa — faz nove passos por dia com o `0xA123`, ou adianta o relógio do aparelho
para ter outros nove.

E, com relevo para a operação: `0xA011` intervalo de heartbeat, **`0xA021` IP do
servidor, `0xA022` domínio e `0xA023` porta**. O aparelho pode ser reapontado
para outro servidor pelo próprio protocolo.

## Como se carrega o prato, e porque é que a dashboard sozinha não chega

O prato tem 28 compartimentos e **nenhum número impresso**. O que traz é um
**autocolante de esquema**, removível, com grupos de doses — `1 2 3` repetidos
para «3x por dia, 9 dias» — e uma **marca cor-de-rosa** que é o ponto de partida.

**Nove alarmes e vinte e oito compartimentos não são o mesmo número porque não
medem a mesma coisa.** Os nove alarmes são um ritmo — quantas doses por dia — e
os 28 compartimentos são capacidade. Cada alarme gasta um compartimento, por
isso os dias que o prato dá são `28 ÷ doses por dia`: 28 dias a uma por dia, 14
a duas, 9 a três, 3 a nove. São exactamente os grupos que o autocolante traz
impressos, e o carrossel atravessa os dias sem se repor — anda uma posição por
dose até chegar ao «carregado até».

O aparelho, por dentro, conta de 1 a 28 e é esse número que o `0x811A` reporta.
**Esse número não existe em lado nenhum no prato.** Dizer a alguém «está no
compartimento 21» não o ajuda: não há 21 para encontrar. Por isso o cartão
traduz a posição para a linguagem do autocolante — com três doses por dia, a
posição 21 é «dia 7, 3ª dose», e sete grupos contam-se a partir da marca sem
hesitar. O número cru fica na gaveta do cartão, porque o autocolante e o plano
configurado podem não corresponder.

## A data em que a medicação acaba

Nem o número de doses nem a posição respondem à pergunta de quem cuida: **em que
dia tenho de ir lá recarregar.** A dashboard responde-a por si, com os alarmes do
plano, a posição do prato e o «carregado até ao compartimento» — percorre os
alarmes a partir de agora e pára na última dose carregada.

Aparece em dois sítios: no cartão «Células restantes», onde a data toma o lugar
da posição na linha visível (a posição e o compartimento ficam no `title`), e por
baixo do campo «Carregado até ao compartimento», onde a frase muda enquanto se
escreve o número — é aí que se vê se o que se carregou chega.

**A conta não é `28 ÷ doses por dia`.** Essa divisão só vale com o carrossel no
zero; a partir do momento em que o prato anda, o que conta é a posição. E há três
casos em que a dashboard se cala, porque não há resposta honesta: sem plano
configurado não há ritmo, com um período de plano já terminado os alarmes que
faltavam nunca tocam, e com o «carregado até» abaixo da posição não há doses
nenhumas por dispensar.

O procedimento, e a ordem importa:

1. **Reiniciar o ciclo no aparelho**, pelo menu — é o `RestartCycle` do manual.
   Não há comando para isto no protocolo: os treze controlos do tipo `0x02` só
   têm o `0xA103`, que reassenta o prato mecanicamente e **não mexe no
   contador**. Observado a 24/09/2026: o prato rodou e o `0x811A` ficou em 20
   antes e depois.
2. **Carregar a partir da marca cor-de-rosa**, seguindo os grupos do autocolante.
3. **Só então dizer ao hub até que compartimento o prato ficou cheio**, na
   configuração «Carregado até ao compartimento». É o número do último que se
   encheu, e não quantos se encheram: o `0x811D` é este menos a posição actual.
   Depois de reiniciar o ciclo a posição é zero e os dois números coincidem, mas
   ao recarregar a meio não coincidem — parado no 10 e cheio até ao 28,
   escreve-se 28.

## O prato acabar não pára nada

**Quando os compartimentos carregados se esgotam, o aparelho continua a
dispensar.** Não pára, não recusa, não avisa: a dose seguinte roda como qualquer
outra e entra na contabilidade como entregue.

Medido a 29/09/2026, com o «carregado até» em 7 e o prato na posição 6, ou seja
uma dose por dar:

```
13:37   o alarme esgota o prato       posição 6 → 7, restantes 0, nível 2 (vazio)
13:42   o alarme seguinte dispara     posição 7 → 8, restantes 0
13:44   e acaba em falhada, como qualquer dose que ninguém levante
```

O `0x811D` ficou em zero porque a conta corta aí — é `carregado até − posição`,
com corte a zero —, mas o `0x811A` andou na mesma. **Se alguém carregar no botão
dentro do tempo, a dose fica registada como tomada com o compartimento vazio**,
porque não há sensor de queda. É a falha calada deste aparelho, e o «carregado
até» é a única coisa que o poderia travar — e não trava.

Para quem opera: ao fim dos dias que o prato dá, o aparelho toca e reporta doses
indefinidamente até alguém o encher e reescrever o «carregado até». Quem sabe que
o prato acabou é o hub, pelos restantes a zero e pelo nível `2`; o aparelho não
distingue esse estado de um dia normal.

> **O prato volta ao zero quando a última dose carregada é levantada — e só
> então.** Se ela falhar, fica onde está e a dose seguinte passa para lá do
> carregamento. O que repõe é chegar ao «carregado até», e **não** esgotar os
> alarmes do dia:
>
> | posição no fim | alarmes do dia | última dose | repôs-se |
> |---|---|---|---|
> | 3, com o carregado em 3 | 3 | tomada (`7`) | sim, 34 s depois |
> | 7, com o carregado em 7 | — | falhada (`6`) | não — a seguinte foi ao 8 |
> | 4, com o carregado em 4 | 4 | tomada (`7`) | sim, 42 s depois |
> | 3, com o carregado em 6 | 2 | tomada (`7`) | **não** |
>
> A última linha é a que separa as duas explicações: nas três primeiras o número
> de alarmes e o de compartimentos carregados coincidiam, e por isso nenhuma
> delas distinguia uma causa da outra. Medida a 01/10/2026, com dois alarmes
> contra seis carregados, as duas doses foram tomadas a horas e o prato ficou na
> posição 3 com três por dispensar.
>
> Repor-se não resolve nada: com o «carregado até» intacto, os restantes voltam ao
> número cheio e o aparelho recomeça a dispensar os mesmos compartimentos, agora
> vazios. Seja qual for o caminho, quem tem de reagir é quem recarrega.

## Como se limpa uma avaria de reposição do prato

O `0x8122` — *Pill Tray Reset Fault*, que o aparelho mostra no ecrã como
«Restart cycle failure» — **não sai com nenhuma ordem remota**. Medido a
24/09/2026, e por esta ordem, sem efeito nenhum:

- o `0xA103` (**«Repor o prato»**) quatro vezes, todas confirmadas com
  `0xA123 = 01`;
- um reinício do aparelho com a avaria ainda por resolver;
- o `RestartCycle` do menu, duas vezes — a segunda já com o prato vazio;
- o `Recycle Test` do menu de fábrica;
- desmontar e reencaixar o prato à mão, duas vezes.

**O que a limpa é uma sequência, e a ordem é tudo:**

1. **Tirar o que estiver preso** ao prato.
2. **Desmontar e reencaixar** o prato.
3. **Só então reiniciar o aparelho.**

A razão é que **o aparelho não deteta o prato a ser reencaixado**: não existe
TAG de presença do prato na tabela do tipo `0x02`, e um desmonte não produz
notificação nenhuma. Ele só reavalia o estado do mecanismo **ao arrancar** — e
por isso o reinício tem de vir depois de o problema estar fisicamente resolvido,
não antes. Um reinício sozinho, com o prato ainda mal posto, não adianta nada: foi
o que se tentou primeiro.

> **Sem nada preso, o reinício remoto sozinho chega.** Os dois primeiros passos
> existem por causa da obstrução, e não do reinício. Medido a 25/09/2026: a avaria
> voltou com o prato já vazio, e saiu com um `0xA001` mandado pela dashboard, sem
> ninguém tocar no aparelho. Vale a pena tentar isso primeiro — é o único remédio
> remoto que existe para esta avaria, e poupa uma deslocação a casa do utente.
>
> **O que a fez voltar foi o prato ser mandado rodar para lá do carregamento.** O
> `0x811A` estava em 22 com os compartimentos carregados em 14, e o `0x811D` a
> zero; o alarme das 09:30 disparou, o aparelho tentou rodar para uma posição que
> se dava por esgotada, e falhou. Manter o «carregado até ao compartimento» a
> dizer a verdade não é cosmético: é o que impede esta avaria — e a ajuda antiga,
> que pedia **quantos** compartimentos se tinham carregado, levava direita a um
> número abaixo da posição sempre que o prato se recarregava a meio.

> Enquanto a avaria está activa, **o aparelho continua a dispensar normalmente**.
> As três dispensas medidas nesse dia foram todas com o `0x8122` em `01`. É uma
> bandeira, não um bloqueio — o que bloqueia é outra coisa, descrita na secção 5.

Saltar o primeiro passo deixa o contador do aparelho a meio da volta anterior, e
a partir daí tudo o que a dashboard mostra sobre posições está deslocado.

## Uma actualização de firmware apaga a configuração do aparelho

A subida de `0x0502` para `0x0503`, a 01/10/2026, repôs valores de fábrica. Lido
do aparelho logo a seguir, antes de lhe escrevermos por cima:

| | antes | depois |
|---|---|---|
| `0x101C` carregados | 6 | **28** |
| `0x1021`/`0x1031` alarme 1 | 10:47 | **24:60** |
| `0x1022`/`0x1032` alarme 2 | 10:50 | **24:60** |
| `0x1054` não incomodar, hora final | 23 | **6** |

O prato também se repôs: posição 0.

**E o hub não deu por nada.** A dashboard continuou a mostrar os alarmes antigos
e a data em que a medicação acabaria, porque é isso que está guardado como
aplicado. Durante esse tempo o aparelho não tinha alarme nenhum e nada no ecrã o
dizia. Quem actualizar o firmware de uma unidade instalada **tem de reconfigurar
o plano e o carregamento a seguir**, e confirmá-los com uma sincronização — a
configuração do hub é a intenção, não a verdade do aparelho.

## Duas definições que só existem no aparelho

O manual do M228A descreve definições que **o protocolo TCP não expõe**. Quem
instalar isto tem de as configurar no próprio aparelho ou na aplicação do
fabricante, porque pela dashboard não se lá chega — e a primeira decide se a
medicação chega sequer ao utente.

**O modo de dispensa.** O manual dá-lhe dois valores:

> **Button:** *When the medication time arrives, users need to press [tecla] for
> the medicine to fall into the medicine cup.*
> **Auto:** *When the medication time arrives, the medicine will automatically
> fall into the medicine cup.*

Em **Button**, a dose só cai se alguém carregar no botão do aparelho. Não há
TAG nenhuma para isto na tabela do tipo `0x02`: o hub não o lê nem o escreve, e
uma instalação feita toda pela dashboard pode ficar com um aparelho que nunca
dispensa sozinho.

**A toma antecipada tem quatro modos, e nós vemos dois.** O `0x100D` é `0: Off`
e `1: On`, mas o manual descreve *Disable*, *Enable*, *Lock* (exige destrancar
o bloqueio de criança antes) e *Double Check* (carregar duas vezes). O protocolo
dá-nos uma vista reduzida do que o aparelho sabe fazer.
