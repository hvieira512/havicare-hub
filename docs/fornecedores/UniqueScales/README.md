# Unique Scales

Balanças de análise corporal CF621BLE e CF632BLE, com firmware Lefu. Não há
protocolo oficial: o que está aqui saiu da engenharia reversa que o Marcus fez à
CF632BLE em dezembro de 2025 e janeiro de 2026 (capturas `btmon`, HCI snoop da
app Unique Health e scripts `bleak`). A CF621BLE nunca foi descodificada.

## Anúncio

Fabricante `0xCF4C`, 17 bytes: os 5 últimos bytes do MAC seguidos de 12 zeros.
Nome no scan response: `CF632`. As capturas só têm anúncios com ninguém na
balança, porque o scan pára quando a app liga. Ainda não se sabe se os zeros
passam a trazer o peso durante uma pesagem sem ninguém ligado.

## GATT

A app oficial liga-se sempre à balança.

| Característica | Uso |
| --- | --- |
| `FFF1` (write) | Perfil do utilizador: `02 altura idade sexo soma`, em que a soma é a soma dos bytes anteriores, módulo 256 |
| `FFF3` (notify) | Medições |

Nas notificações de `FFF3` com pelo menos 14 bytes, o peso é um `uint16`
little-endian nos bytes 4–5, em centésimos de kg. O valor bate com a app: 92,4 kg
na app e 92,75–92,9 kg no script, no mesmo dia.

O que **não** está confirmado:

- **Frequência cardíaca (byte 12) e impedância (bytes 8–9):** deram 100 bpm e
  50 Ω em todas as pesagens, em duas balanças. Os offsets estão provavelmente
  errados.
- **Composição corporal:** a balança não a calcula. A app mostra 25 parâmetros,
  mas calcula-os a partir do peso, da impedância e do perfil, com o algoritmo da
  Lefu. A Lefu disponibiliza esse algoritmo como API na cloud, com AppKey:
  <https://lefuhengqi-en.apifox.cn/>. Os valores de gordura e músculo dos
  scripts do Marcus são uma fórmula inventada.

## Wi-Fi

Os modelos "BLE+WiFi" enviam as pesagens para um servidor. A Lefu deu um
servidor de exemplo (`lefu-wifi-scale-server-demo`, rotas `/lefu/wifi/config` e
`/lefu/wifi/record`). O `record` traz o peso, a frequência cardíaca e 10 valores
de impedância em bruto. As CF621BLE e CF632BLE são só BLE.
