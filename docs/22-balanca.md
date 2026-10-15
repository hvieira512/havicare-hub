# 22 — Balança de análise corporal

A Unique Scales HC-CF632BLE fala o protocolo **Torre** da Lefu, só por GATT. O
protocolo byte a byte está no [README do fornecedor](fornecedores/UniqueScales/README.md);
aqui fica o que o hub faz com ele.

## Transporte

Um gateway por software da Havicare (`Havicare BLE Gateway`, protocolo
`havicare-ble-gateway`) liga-se à balança quando ela acorda e reencaminha **cada
notificação em bruto** para `…/gw/{gateway}/raw`, com `source: "lefu-torre-node"`.
O protocolo é todo do hub, à ida e à volta: o gateway não interpreta nada.

| `kind` | `payload` | O que o hub faz |
| --- | --- | --- |
| `session` | `connected`, `battery`, `mtu` | Põe a balança online ou offline, publica a `battery`, acerta o relógio e entrega a fila |
| `frame` | `char` (`fff1` · `fff2` · `fff3`), `hex` | Descodifica (`TorreFrameDecoder`) e publica, ou fecha o comando a que a trama responde |
| `command_result` | `dedupeKey`, `ok`, `error` | Tira o comando da fila; um `ok: false` falha-o |

A balança só está ligada enquanto alguém está em cima dela e cerca de 30 s depois.
Uma ordem dada com ela a dormir espera na fila do hub, com o prazo de sempre
(`DOWNLINK_QUEUE_TTL_SECONDS`), e sai no `connected: true` seguinte.

## O que sai no MQTT

As capacidades estão no [capítulo 06](06-normalizacao.md): `weight`, `heart_rate`,
`body_impedance`, `body_impedance_channel`, `body_impedance_failure`,
`weighing_status`, `scale_motion`, `scale_history_available`,
`scale_user_selection` e `battery`. Uma pesagem completa dá `weight`, `heart_rate`
(só se a FC foi medida) e `body_impedance` com o mesmo `occurredAt`.

A composição corporal não sai: a balança mostra-a no visor mas não a envia, e o
cálculo precisa da biblioteca da Lefu, com licença. As impedâncias cifradas vão no
`body_impedance` para se poder calcular mais tarde.

## Comandos

O hub monta as escritas GATT (`TorreCommandEncoder`) e manda-as ao gateway em
`…/gw/{gateway}/cmd` como `{operation, payload: {writes: [{char, hex, await?}]}}`.
O `await` diz ao gateway que espere pela resposta de um passo antes do seguinte,
como nos fluxos de utilizador do `FFF2`.

| Chave | Secção | O que faz |
| --- | --- | --- |
| `heart_rate_measurement` | Saúde | Liga e desliga a medição da FC (`06`) |
| `impedance_measurement` | Saúde | Liga e desliga a impedância (`07`); desligada, a balança só pesa |
| `select_scale_user` | Saúde, ação | Põe um utilizador já registado em linha; sem isto a FC não é medida |
| `weight_unit` | Sistema | A unidade do visor (`05`) |
| `sync_configuration` | Sistema, ação | Relê os três; a leitura sai em `device_config` |
| `calibrate_clock` | Sistema, ação | Acerta o relógio (`04`); o hub também o faz a cada ligação |
| `restart_device` | Sistema, ação | Reinicia (`09`) |

A reposição de fábrica (`16 01 00`) nunca entra. Ficam para a fase seguinte o
registo e a remoção de utilizadores, o histórico por membro e apagar o histórico.
