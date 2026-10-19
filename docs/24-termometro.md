# 24 — Termómetro de testa

O JoyTech DET-3018B, que a Havicare vende como **HC-3018B**. Entra como o
[tensiómetro](23-tensiometro.md): pelo gateway BLE da Havicare, com `source`
`joytech-node` e as mesmas tramas (`FA AA AA AF` … `F5 5F`). O nome do aparelho
em `device.model` separa os dois: `DET…` é o termómetro, `DBP…` o tensiómetro. O
protocolo é o `IR thermometer-protocol.xlsx`, em
[fornecedores/JoyTech](fornecedores/JoyTech/).

## A conversa

| Função | Sentido | O que é |
|---|---|---|
| `0x06` | aparelho → hub | Pede a hora ao ligar, 4 vezes por segundo. Sem resposta não mede, e desliga-se ao fim de uns 40 s |
| `0x86` | hub → aparelho | A hora local: ano em dois dígitos, mês, dia, hora, minuto. Responde-se ao primeiro pedido da rajada |
| `0x03` | aparelho → hub | Depois de medir, as últimas 30 medições numa trama, a mais recente primeiro |
| `0x83` | hub → aparelho | A confirmação. Não apaga nada: cada ligação traz as mesmas 30 |

Cada medição tem 8 B: o sítio (1 ouvido, 2 testa, 3 objeto), o valor em 2 B (o
bit 15 diz °F, o resto são centésimas) e a hora do aparelho. Não há comandos nem
configurações.

## O que sai no MQTT

`temperature` com `bodyCelsius`, arredondado a uma casa como o ecrã e convertido
quando vem em °F, e `site` (`ear` ou `forehead`). As medições de objetos não
saem. O `occurredAt` é a hora do aparelho; a mais recente da trama é ao vivo se
tiver menos de 5 minutos, e as outras levam `fromHistory: true`. As anteriores a
1 de janeiro de 2025 ficam de fora: o reset das pilhas data-as de 2024-01-01.

Como as 30 vêm a cada ligação, o hub guarda no Redis
(`hub:joytech:thermometer-seen:{mac}`) as chaves das últimas 60 que publicou —
o registo de 8 B tal como veio — e só publica o que não estiver lá. Duas
medições no mesmo minuto são duas, porque o valor faz parte da chave; duas no
mesmo minuto, no mesmo sítio e com o mesmo valor contam como uma. A lista não
expira, porque sem ela as 30 voltavam a sair.

A hora do aparelho converte-se com o fuso do gateway no momento da leitura: uma
medição feita antes de uma mudança de hora e lida depois fica com uma hora de erro.
