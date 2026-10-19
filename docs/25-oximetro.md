# 25 — Oxímetro de dedo

O JoyTech XM-101, que a Havicare vende como **HC-101**. Entra como o
[tensiómetro](23-tensiometro.md) e o [termómetro](24-termometro.md): pelo gateway
BLE da Havicare, com `source` `joytech-node` e as mesmas tramas (`FA AA AA AF` …
`F5 5F`). O nome do aparelho em `device.model` separa-os: `XM…` é o oxímetro. O
protocolo é o `oximeter-protocol.xlsx`, em [fornecedores/JoyTech](fornecedores/JoyTech/).

## A conversa

Não há conversa: o aparelho liga-se quando se põe o dedo e manda logo a leitura
corrente, cerca de 11 vezes por segundo, até se tirar o dedo. Não pede a hora,
não tem memória nem comandos, e o hub não lhe escreve nada.

| Função | Sentido | O que é |
|---|---|---|
| `0x88` | aparelho → hub | SpO2 em %, pulso em bpm, PI, barra e onda, um byte cada |

Enquanto procura o pulso, o SpO2 e o pulso vêm a zero e a barra traz o bit
`0x20`; uma leitura válida tem os dois acima de zero e a barra entre 0 e 9. O PI
vem sempre a zero: este modelo não o mede. A barra e a onda são para o ecrã do
aparelho e não saem.

## O que sai no MQTT

Uma medição sai uma vez, quando acaba, como `blood_oxygen` (`spo2Percent`) e
`heart_rate` (`bpm`), com o mesmo `nativeType` `fff1:88`.

- **Acaba** com 5 s sem leituras válidas ou com a sessão a cair, o que vier
  primeiro. Tirar o dedo não manda nada: as tramas param, e a ligação só cai uns
  20 s depois.
- **O valor** é a mediana das leituras válidas dos últimos 5 s, cada grandeza à
  parte; num número par, a de cima, para ser sempre um valor que o ecrã mostrou.
  Os primeiros segundos, ainda a estabilizar, ficam de fora.
- **Menos de 3 s** de leituras válidas é o dedo a entrar e a sair, e não sai
  nada.
- **O `occurredAt`** é a hora de chegada da última leitura válida: o aparelho
  não tem relógio.

A medição em curso só existe em memória: um hub reiniciado a meio perde-a.
