# 99 — Notas de arquitetura

Os capítulos anteriores descrevem o sistema implementado. Este reúne as questões
de arquitetura em aberto e as decisões pendentes, mantidas separadas para que a
descrição do sistema não se confunda com propostas de alteração.

Nenhuma das questões listadas constitui defeito que exija correção imediata.

---

## 1. Duas capacidades publicadas continuam fora do catálogo, de propósito

**O que é.** O `heartbeat` e o `device_config` saem como telemetria e não têm
entrada no catálogo.

**Porque está certo assim.** O `heartbeat` é o sinal de vida, não uma medição —
e já está excluído do histórico da dashboard pela mesma razão. O `device_config`
é a confirmação de uma configuração, não uma leitura. Declará-los como
capacidades punha na matriz por modelo dois interruptores que ninguém quereria
desligar.

Fica registado para a pergunta não voltar a ser feita do zero.

---

## 2. A W6 identifica o toque pela configuração, não pelo protocolo

**O que é.** A pulseira W6 não tem trama de alarme. O modo de toque é deduzido de
_qual_ espaço de anúncio apareceu, e os identificadores desses espaços são uma
convenção que a pulseira tem de ser configurada para cumprir.

**Porque importa.** Uma W6 configurada de outra maneira é vista, mas os toques
dela não são lidos — e não há sinal nenhum de que isso está a acontecer. Já está
assinalado no código com um comentário `ponytail:`.

**Decisão.** Fica como está. A limitação é da firmware, e a expectativa é que
esta lógica venha a ser descartada em vez de generalizada.

**Onde.** `src/Ingress/Mqtt/Moko/W6Decoder.php`.

---

## 3. A produção não declara os seus identificadores de cliente MQTT

**O que é.** O `.env` da instância de produção não define `MQTT_CLIENT_ID_PREFIX`
nem `QINGLANST_CLIENT_ID_PREFIX`. Os valores efetivos — `health-mqtt` e
`qinglanst-radar` — vêm dos literais por omissão do `src/Config.php`.

**Porque importa.** A identidade da produção no broker está implícita em código.
Alterar um desses literais mudaria silenciosamente o identificador com que a
produção se apresenta, e o efeito de dois clientes trocarem de identidade é uma
expulsão mútua em ciclo, com a ingestão a falhar de forma intermitente.

Declará-los explicitamente no `.env` da produção remove o acoplamento. É uma
alteração de configuração, não de código, e obriga a reiniciar o serviço.

---

## 4. Ausência de tabela de telemetria

**Descrição.** O histórico reside exclusivamente no Redis, limitado a 100
entradas por lista e por dispositivo.

**Fundamento.** O âmbito da plataforma é a normalização e a entrega; o
arquivo é responsabilidade das aplicações que integram. A escrita de cada
leitura numa tabela alteraria a natureza do produto e introduziria um problema
de crescimento que atualmente não existe.

Fica registado por constituir uma decisão de arquitetura e não uma omissão.

---

## 5. A ingestão do dispensador de comprimidos não tem transporte decidido

**O que é.** O fabricante do Zayata M228 oferece dois modelos de integração. No
primeiro, o aparelho fala com a cloud dele e nós falamos com essa cloud por HTTPS,
recebendo eventos num callback nosso. No segundo, o aparelho liga-se por TCP
directamente ao hub, como já fazem os relógios. O fabricante **recomenda o
segundo**, por os dados de medicação deixarem de atravessar um servidor na China.

**Porque está em aberto.** Só o primeiro está documentado. A especificação do
protocolo TCP do segundo nunca nos foi entregue, e sem ela não é implementável. O
[capítulo 19](19-dispensador-de-comprimidos.md) descreve os dois.

**Porque importa.** A escolha muda a camada de entrada por inteiro — uma rota HTTP
com o seu cliente e o seu callback, contra um descodificador sobre o socket TCP
que já existe. Muda também o perfil de risco: no primeiro modelo a
disponibilidade da cloud do fabricante é uma dependência nossa, e há um callback
sem autenticação definida a proteger.

**Decisão.** Aguardar a especificação antes de escrever a ingestão. O que não
depende dela — o contrato das capacidades, a declaração no `CapabilityCatalog`, o
cartão da dashboard e a superfície de configuração — pode avançar, porque a
semântica de uma toma não muda com o transporte.

**Dependências do fabricante.** Além da especificação: credenciais de produção
para a nossa empresa; autenticação e política de repetição do callback; o APN
`internetm2m` gravado de fábrica nas unidades vendidas para Portugal, sem o qual
os cartões M2M não anexam; firmware com português, se existir; o manual do M228,
que não é público; e como se activa o botão de emergência, que hoje não produz
evento nenhum do lado do parceiro.

---

## Divergências corrigidas

Registo das divergências já resolvidas, para não voltarem a ser reportadas como
novas:

| O quê                                                                                           | Estado                                                                   |
| ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| O `StartupBanner` anunciava tópicos com quatro segmentos, forma que o hub já não produz         | corrigido                                                                |
| O `.env.example` documentava um par de variáveis de administrador de arranque que não existem   | corrigido                                                                |
| O `AGENTS.md` era uma cópia desatualizada do `CLAUDE.md` e mandava trabalhar direto em produção | corrigido                                                                |
| O `README.md` descrevia o hub como um encaminhador de bytes crus                                | reescrito                                                                |
| A documentação dava os tópicos do NCS com quatro segmentos                                      | corrigido no [capítulo 03](03-ingestao-mqtt-ncs.md)                      |
| O contrato prometia `pulseBpm` em `blood_pressure`, que nunca foi emitido                       | corrigido no [capítulo 06](06-normalizacao.md)                           |
| O contrato documentava doze capacidades de telemetria; são vinte                                | corrigido no [capítulo 06](06-normalizacao.md)                           |
| O `README` dizia que a integração contínua procurava "skipped" na saída; faz o contrário        | corrigido no [capítulo 16](16-testes.md)                                 |
| O `README` dizia que a dashboard aceitava `license_client`; nunca aceitou                       | corrigido no [capítulo 13](13-dashboard.md)                              |
| O `docs/` não era referenciado por nenhum ficheiro do repositório                               | corrigido                                                                |
| O `alarm` dos relógios era publicado e não existia no catálogo                                  | acrescentado, com migração                                               |
| A `proximity` era publicada para pulseiras e sensores e não existia no catálogo                 | acrescentada, com migração                                               |
| O NCS declarava `pager_call` e publicava `help_call`                                            | alinhado; a mensagem no MQTT não mudou                                   |
| O radar publicava no MQTT com o `uid` do tópico e escrevia na dashboard com o IMEI              | passou a usar o IMEI nos dois — [capítulo 04](04-ingestao-mqtt-radar.md) |
| Um SOS de relógio saía em `telemetry` a QoS 0; uma queda de radar em `events` a QoS 1           | os alarmes passaram a `events` — [capítulo 08](08-contrato-mqtt.md)      |
| O envelope MQTT levava um `schemaVersion` que ninguém lia e que seguia o produtor, não o canal  | removido — [capítulo 06](06-normalizacao.md)                             |
| O downlink por MQTT era uma segunda porta de comandos, só para relógios e sem registo           | removido; entra tudo pela [API](09-api.md)                               |
| O `ConnectionRegistry` rotulava um transporte `websocket` que não existe                        | corrigido                                                                |
| Os cenários recriavam o contentor de desenvolvimento e nunca o repunham                         | correm em projeto compose próprio — [capítulo 16](16-testes.md)          |
| O `composer.json` aceitava PHP 8.1, versão que nunca foi testada                                | passou a `^8.4`                                                          |
| O IMEI por omissão do simulador não existia no inventário semeado                               | corrigido no `Makefile`                                                  |
| A nota dizia não haver cópias de segurança das bases; passaram a existir, com rotação e temporizador | corrigido no [capítulo 18](18-backups.md)                           |
