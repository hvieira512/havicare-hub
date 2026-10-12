# 21 — Pulseira SOS Wi-Fi: ingestão pela cloud da Tuya

## Âmbito

A pulseira **PST CF103W** é um botão de pânico Wi-Fi com um módulo da Tuya. O
firmware é da Tuya e só fala com a cloud dela: o fabricante não o altera, e não
há maneira de apontar o aparelho para o hub. O hub recebe-a **da cloud da
Tuya**, pelo Message Service de um projeto na Tuya IoT Platform.

O aparelho dorme. Acorda quando se carrega no botão e **de oito em oito horas**,
manda a bateria, e volta a dormir uns minutos depois. Na Tuya é da categoria
`sos` e tem só dois pontos de dados:

| Código Tuya | DP | Tipo | No hub |
|---|---|---|---|
| `sos_state` | 23 | booleano | `help_call` no canal `events`, quando passa a `true` |
| `battery_percentage` | 3 | inteiro 0–100 | `battery` no canal `telemetry`, `data.percent` |

Não há nada que se configure nem comandos a enviar.

```mermaid
flowchart LR
  P["PST CF103W"] -->|"Wi-Fi"| T(["Cloud Tuya<br/>Europa Central"])
  T -->|"Pulsar por WebSocket"| H["TuyaBridge"]
  H --> M["HubMqttBridge"]
  M -->|"raw · status · events · telemetry"| B(["Broker MQTT"])
```

## 1. A ligação

O hub abre um WebSocket para o Pulsar da Tuya e fica a ouvir:

```text
wss://mqe.tuyaeu.com:8285/ws/v2/consumer/persistent/{accessId}/out/event/{subscrição}?ackTimeoutMillis=3000&subscriptionType=Failover
```

- **Autenticação:** cabeçalhos `username` (o Access ID) e `password`, que é
  `md5(accessId . md5(accessSecret))` do carácter 8 ao 24.
- **Confirmação:** cada mensagem confirma-se com `{"messageId": …}` depois de
  tratada. Uma trama que não se lê confirma-se e larga-se, porque voltaria para
  sempre. Uma falha do hub (Redis ou base de dados em baixo) não confirma, e o
  Pulsar volta a entregar a mensagem três segundos depois; à quinta entrega
  falhada, larga-se com erro no log. Quem consome pode por isso receber um
  `help_call` repetido, como em qualquer canal QoS 1.
- **Ligação perdida:** volta a ligar sozinha, de 1 a 60 segundos de espera. Um
  `ping` a cada 30 segundos mantém-na aberta, e sem nada do servidor durante 90
  segundos fecha-se e volta a abrir.
- **Retenção:** a Tuya guarda o que não foi confirmado durante **duas horas**.
  Um hub em baixo mais do que isso perde os alarmes desse intervalo.

Cada trama traz a mensagem de negócio cifrada em AES-128-GCM (a propriedade
`em` é `aes_gcm`) com os caracteres 8 a 24 do Access Secret: os 12 primeiros
bytes são o nonce e os 16 últimos a etiqueta. O ECB dos projetos anteriores a
2023 não se lê.

O código está em `src/Ingress/Tuya/`: `PulsarFrame` abre a trama, o
`MessageNormalizer` traduz, e a `TuyaBridge` liga, publica e regista.

## 2. As mensagens

| `bizCode` | O que o hub faz |
|---|---|
| `devicePropertyMessage` | Traduz cada propriedade como na tabela do âmbito. O `occurredAt` é a hora a que o aparelho a reportou. O `sos_state` a `false` é o botão a voltar sozinho ao repouso, e não sai |
| `deviceOnline` | Só conta como o aparelho ter falado |
| `deviceOffline` | Ignora-se: chega minutos depois de cada acordar |
| os outros | Ignoram-se: são da cloud, e não do aparelho |

O `source.protocol` é `tuya-cloud` e o `source.nativeType` é o código Tuya do
ponto de dados.

## 3. Ligado e desligado

A pulseira não tem sessão. Está ligada a partir da primeira mensagem, e é
anunciada desligada quando se cala por mais de `TUYA_IDLE_TIMEOUT_SECONDS`
(8 h 30 por omissão). O prazo vai no estado do aparelho (`idleTimeoutSeconds`),
e o varrimento geral de meia hora respeita-o: sem isso, a pulseira estaria
desligada sete horas e meia em cada oito.

Uma pulseira avariada só se dá por desligada ao fim desse prazo.

## 4. Identidade e registo

O identificador do aparelho é o `devId` da Tuya (22 caracteres, por exemplo
`bf7cbe84a6199e98168qvb`). Regista-se na whitelist com esse valor, fornecedor
`PST` e modelo `CF103W`, tipo `bracelet`. Um `devId` desconhecido aparece nos
aparelhos por registar, como os dos outros protocolos.

Para chegar ao projeto Tuya, a pulseira emparelha-se na app Tuya Smart com uma
conta ligada ao projeto (*Devices → Link App Account*).

## 5. Configuração

| Variável | |
|---|---|
| `TUYA_ENABLED` | Liga a ingestão; desligada por omissão |
| `TUYA_ACCESS_ID`, `TUYA_ACCESS_SECRET` | As chaves do projeto Tuya (*Cloud → Project → Overview*) |
| `TUYA_PULSAR_URL` | `wss://mqe.tuyaeu.com:8285/`, o da Europa Central |
| `TUYA_SUBSCRIPTION` | A subscrição Pulsar desta instância |
| `TUYA_IDLE_TIMEOUT_SECONDS` | O prazo da secção 3 |

**Cada instância usa a sua subscrição.** O tópico de produção tem onze partições,
e dois consumidores na mesma subscrição repartem-nas: cada instância veria só
parte dos aparelhos. A produção usa a `{accessId}-sub`, que a Tuya cria; o dev
usa outra, criada na consola (*Message Service → Subscription Management*), e
cada subscrição recebe uma cópia de tudo.

## 6. Licença e limites

O projeto está no plano **Trial** do IoT Core: 50 aparelhos, 68 000 mensagens por
mês, um mês de validade renovável à mão na consola, e **uso comercial
proibido**. Com clientes é preciso um plano pago ou o *Device Data Sharing* do
Data Act; nenhum dos dois tem preço público.

Do próprio aparelho: dois toques com menos de um ou dois segundos entre eles não
dão alarme; toques com dez segundos de intervalo dão todos.
