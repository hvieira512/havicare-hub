# JoyTech

Medidores de tensão arterial, oxímetro e termómetro de testa. A Havicare vende-os
com modelo próprio:

| Havicare | JoyTech | Protocolo |
| --- | --- | --- |
| HC-6281B, HC-6292B (tensão, braço) | — , DBP-6292b | `BP bluetooth protocol.xlsx` |
| HC-6679B (tensão com ECG) | DBP-6679B | `BP bluetooth protocol.xlsx`, `ECG-protocol.xlsx` |
| HC-101 (oxímetro) | XM-101 | `oximeter-protocol.xlsx` |
| HC-3018B (termómetro de testa) | DET-3018B | `IR thermometer-protocol.xlsx` |

## Transporte

Todos precisam de **ligação GATT**. O anúncio só serve para encontrar o aparelho:
o SDK Android não lê nada do `scanRecord`. Depois de ligar, o aparelho envia
pelo `FFF1` (notify) e recebe pelo `FFF2` (write). As tramas começam por
`FA AA AA AF` e acabam em `F5 5F`. Logo a seguir à ligação, o aparelho pede a
hora e espera resposta; no ECG, sem resposta da app, não envia dados em tempo
real.

Os gateways MOKO só ouvem anúncios ([capítulo 05](../../05-gateways-ble.md)). O
tensiómetro HC-6292B e o termómetro HC-3018B entram pelo gateway BLE da Havicare,
que abre a sessão GATT ([capítulos 23](../../23-tensiometro.md) e
[24](../../24-termometro.md)); os outros ainda não entram.

## Versões

Os `.xlsx` são as versões de 2025-12-16. Havia cópias mais antigas do BP e do
termómetro, diferentes destas.

O SDK Android (`joytech-et.bp.xm-SDK-android.rar`, 119 MB) e as frameworks iOS
não estão no repositório. Ficaram na máquina do Marcus, em
`~/ownCloud/marcus.santos/Produtos/P&D/CE-MDR-BLE/JoyTech/SDK/` e
`~/HAVICARE/CE_MDR/JOYTECH/SDK/`.
