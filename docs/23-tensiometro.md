# 23 — Tensiómetro de braço

O JoyTech DBP-6292B, que a Havicare vende como **HC-6292B**, e o DBP-6679B
(**HC-6679B**), que também faz ECG. Fala por GATT com o
gateway BLE da Havicare, como a [balança](22-balanca.md): o gateway só liga,
junta as notificações e escreve o que o hub manda; as tramas são todas do hub
(`src/Ingress/Mqtt/JoyTech/`). O protocolo é o `BP bluetooth protocol.xlsx`, em
[fornecedores/JoyTech](fornecedores/JoyTech/).

## Transporte

O aparelho só se anuncia à volta de uma medição. O gateway publica, com `source`
`joytech-node` e o MAC em `device.mac`, uma `session` (`connected`) ao ligar e ao
cair, e cada trama do `FFF1` inteira num `frame`. Com MTU 23 uma trama de 22 B
chega em duas notificações, e é o gateway que as junta pelo tamanho declarado.

Cada trama é `FA AA AA AF` · tamanho da trama inteira (2 B) · função · dados ·
soma do tamanho aos dados, módulo 256 · `F5 5F`. Uma trama com a soma errada é
deitada fora.

| Função | Sentido | O que é |
|---|---|---|
| `0x04` | hub → aparelho | A hora local: ano em dois dígitos, mês, dia, hora, minuto |
| `0x84` | aparelho → hub | A resposta à hora; o hub ignora-a |
| `0x83` | aparelho → hub | Um resultado: sistólica e diastólica (2 B cada), pulso, arritmia (`FF` sim), a hora do aparelho e o grupo de memória (1–4) |
| `0x0A` | hub → aparelho | Ler a memória |
| `0x8B` | aparelho → hub | Fim da memória |

## A cada ligação

O aparelho não pede a hora e o relógio dele começa em 2021. O hub manda o `0x04`
em cada `session` ligada e logo a seguir o `0x0A`, que lê a memória sem a apagar.
Como o aparelho só se liga quando se carrega em Start, esse `0x0A` chega quase
sempre a meio da medição e é ignorado: não chega o `0x8B`, e a espera do gateway
acaba sem erro visível. Por isso o hub volta a pedir a memória 15 s depois do
resultado ao vivo, já passadas as repetições, a não ser que a leitura dessa
ligação já tenha chegado ao fim. Na dashboard, «Ler a memória» faz o mesmo a
pedido. Fecha-o o gateway, que só responde depois do `0x8B` dessa leitura; pedido
a meio de uma medição, falha por tempo, e os dados chegam depois pela leitura
automática.

A hora vai no fuso do gateway: o `tzOffsetMinutes` de cada mensagem é o da
máquina onde ele corre, e é o mesmo que converte a hora do aparelho em
`occurredAt`.

## O que sai no MQTT

Cada resultado sai como `blood_pressure` (`systolicMmHg`, `diastolicMmHg`,
`irregularHeartbeat`, `memoryGroup`) e `heart_rate` (`bpm`), com o mesmo
`nativeType` `fff1:83`. O grupo é informativo: não liga a utentes.

- **Ao vivo** o resultado vem 6 vezes, de 2 em 2 s; sai uma, com a hora de
  chegada no `occurredAt`.
- **Da memória** sai com a hora do aparelho e `fromHistory: true`. Um `0x83` é da
  memória enquanto os registos chegarem seguidos depois de um `0x0A`, repetidos
  ou não; 3 s de silêncio quer dizer que a leitura não começou.

**Marca por aparelho e grupo.** O hub guarda no Redis
(`hub:joytech:history-mark:{mac}`), por grupo, a hora local do aparelho do
registo mais recente que leu da memória — local para a mudança de hora não a
mexer. Da memória só sai o que for mais recente do que a marca quando a leitura
começou. A memória é um anel e não vem por ordem, por isso cada registo
compara-se sozinho, e a marca só avança no `0x8B`: uma leitura interrompida
deixa-a onde estava, e o que dela já saiu fica na lista de baixo.

Cada ligação começa com uma medição, porque o aparelho só se anuncia quando se
carrega em Start, e ela chega antes da memória. Por isso o ao vivo **não** avança
a marca: o que se mediu sem gateway antes dele ainda tem de entrar. Fica guardado
tal como veio (`hub:joytech:live:{mac}:{grupo}`), com o que já saiu de uma
leitura por acabar; a memória não o repete, e sai dessa lista quando a marca o
ultrapassa. Um registo que chegue fora de uma leitura e não seja mais recente do
que a marca é da memória, e não sai como ao vivo. A lista expira 90 dias depois da última
escrita; a marca não expira, porque sem ela a memória toda voltava a sair.

Só contam horas plausíveis: desde 1 de janeiro de 2025 e não mais de 5 minutos à
frente. As medições feitas com o relógio por acertar ficam de fora.

**Limites conhecidos.** Se o relógio do aparelho voltar a 2021 (pilhas trocadas)
e se medir sem gateway por perto, essas medições ficam abaixo da data mínima e
perdem-se. Duas medições no mesmo grupo e no mesmo minuto contam como uma. Na
mudança de hora, um registo medido antes e lido depois fica com uma hora de erro
no `occurredAt`, mas não se repete; e na hora repetida do outono, uma medição ao
vivo com a hora do aparelho abaixo da marca é tomada por memória e não sai. Um hub reiniciado com o aparelho ligado
refaz a sessão com a primeira trama que chegar.

## O HC-6679B, com ECG

O JoyTech DBP-6679B, vendido como **HC-6679B**, é o mesmo tensiómetro: a tensão
segue tudo o que está acima. O nome `DBP-6679…` em `device.model` não muda a
ponte; é o modelo registado que lhe dá o protocolo `joytech-bp-ecg`, e com ele a
capacidade `ecg`.

| Função | Sentido | O que é |
|---|---|---|
| `0x85` | aparelho → hub | A contagem antes do exame, uma por segundo |
| `0x05` | hub → aparelho | Começar. Sem ele, durante a contagem, o aparelho não manda nada |
| `0x87` | aparelho → hub | 30 s de traçado a 250 Hz, 50 tramas por segundo: 4 a 6 amostras em int16 com sinal, e dois bytes de estado no fim |
| `0x86` | aparelho → hub | O fim: estado, FC, um byte a zero, a hora do aparelho e o grupo. Repete-se de 2 em 2 s |
| `0x06` | hub → aparelho | A confirmação do fim |

O hub responde à primeira trama da contagem e à primeira do fim, e ignora as
repetições dos 5 s seguintes. O exame sai num só `ecg`, no formato do da pulseira para o traçado
se desenhar igual: `samples` (cerca de 7500), `frequencyHz` 250 e `heartRateBpm`
do `0x86`, com a hora do aparelho no `occurredAt`. O pulso não sai à parte. As
amostras vão como chegam: **a unidade não é conhecida**, e pediu-se à JoyTech o
fator para mV.

Um exame interrompido não sai: a sessão a cair antes do `0x86`, ou menos de 95%
das 7500 amostras quando ele chega. Conta-se o que o aparelho mandou e não quando
chegou, para um atraso a caminho do hub não deitar fora o exame; o que sai fora
fica no log. A memória dos ECG do aparelho (`0x0E`) não
responde, e fica de fora. A lista da dashboard guarda a contagem das amostras
(`sampleCount`) e não o traçado, como a da pulseira.

**A memória só a pedido.** O HC-6679B só manda a memória com o ecrã MEM aberto
(«memory mode» nas duas folhas do protocolo): fora dele ignora o `0x0A`, e a
espera do gateway ocupa-o 30 s. Por isso o hub não lha pede sozinho, nem ao
ligar nem depois do resultado. O «Ler a memória» da dashboard diz para abrir a
memória no aparelho antes de pedir, e fica à espera até ele se ligar.

Um HC-6679B por registar aparece nas notificações com o protocolo `joytech-bp`:
o protocolo sai do modelo registado, e ainda não há nenhum.
