# 23 — Tensiómetro de braço

O JoyTech DBP-6292B, que a Havicare vende como **HC-6292B**. Fala por GATT com o
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
ligação já tenha chegado ao fim. Na
dashboard, «Ler a memória» faz o mesmo a pedido, e fecha com o `0x8B`.

## O que sai no MQTT

Cada resultado sai como `blood_pressure` (`systolicMmHg`, `diastolicMmHg`,
`irregularHeartbeat`, `memoryGroup`) e `heart_rate` (`bpm`), com o mesmo
`nativeType` `fff1:83`. O grupo é informativo: não liga a utentes.

- **Ao vivo** o resultado vem 6 vezes, de 2 em 2 s; sai uma, com a hora de
  chegada no `occurredAt`.
- **Da memória** sai com a hora do aparelho e `fromHistory: true`. Um `0x83` é da
  memória enquanto os registos chegarem seguidos depois de um `0x0A`; 3 s de
  silêncio quer dizer que a leitura não começou.

**Marca por aparelho e grupo.** O hub guarda no Redis
(`hub:joytech:history-mark:{mac}`), por grupo, a hora do aparelho do registo
mais recente que leu da memória. Da memória só sai o que for mais recente do que
a marca quando a leitura começou. A memória é um anel e não vem por ordem, por
isso cada registo compara-se sozinho e a marca só avança no fim da leitura.

Cada ligação começa com uma medição, porque o aparelho só se anuncia quando se
carrega em Start, e ela chega antes da memória. Por isso o ao vivo **não** avança
a marca: o que se mediu sem gateway antes dele ainda tem de entrar. Fica guardado
tal como veio (`hub:joytech:live:{mac}:{grupo}`), a memória não o repete, e sai
dessa lista quando a marca o ultrapassa.

Só contam horas plausíveis: desde 1 de janeiro de 2025 e não mais de 5 minutos à
frente. As medições feitas com o relógio por acertar ficam de fora.

**Limites conhecidos.** Se o relógio do aparelho voltar a 2021 (pilhas trocadas)
e se medir sem gateway por perto, essas medições ficam abaixo da data mínima e
perdem-se. Duas medições no mesmo grupo e no mesmo minuto contam como uma.
