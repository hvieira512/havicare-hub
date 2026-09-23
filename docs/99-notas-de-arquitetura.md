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

**E há uma armadilha por baixo desta: o identificador é cortado aos 23
caracteres.** É o limite que o MQTT 3.1 fixa, e o `ConnectionFactory` aplica-o
com um `substr` silencioso. O identificador completo é `{prefixo}-{sufixo}`, e
os sufixos das ingestões são fixos — `ncs-sub`, `moko-sub`, `veepoo-sub` e
`sub`. Um prefixo comprido faz o corte comer o sufixo **e a parte do prefixo que
o tornava único**.

Aconteceu: um `QINGLANST_CLIENT_ID_PREFIX=qinglanst-radar-local-hugo` tem 26
caracteres e nunca chega inteiro ao broker — sai `qinglanst-radar-local-h`, e o
`-hugo` que distinguia aquela máquina desaparece. Duas pilhas locais com
prefixos diferentes mas o mesmo começo apresentam-se com o mesmo nome e
expulsam-se uma à outra. O mosquitto do `mqtt-prod` registou seis dessas
expulsões em 22 de setembro de 2026.

A regra prática é por prefixo, porque cada um se junta a sufixos diferentes. O
`MQTT_CLIENT_ID_PREFIX` serve três ingestões e o sufixo mais comprido é
`-veepoo-sub`, com onze caracteres: **sobram doze**. O
`QINGLANST_CLIENT_ID_PREFIX` só se junta a `-sub`, com quatro: sobram dezanove.

O `health-mqtt` da produção tem onze e passa inteiro. O `health-mqtt-dev` tem
quinze e é cortado nos sufixos mais longos — `health-mqtt-dev-veepoo-sub` sai
como `health-mqtt-dev-veepoo-` — mas continua distinto da produção, porque
diverge antes do corte. O perigo não é o corte em si: é escolher um prefixo que
partilhe os primeiros 23 caracteres com outro, e nesse caso nada no log diz que
foi isso que aconteceu.

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

## 5. O dispensador de comprimidos entra por TCP, não pela cloud do fabricante

**O que é.** O fabricante do Zayata M228 oferece dois modelos de integração. No
primeiro, o aparelho fala com a cloud dele e nós falamos com essa cloud por HTTPS,
recebendo eventos num callback nosso. No segundo, o aparelho liga-se por TCP
directamente ao hub, como já fazem os relógios.

**Decisão: o segundo.** O fabricante recomendou-o e entregou a especificação do
protocolo em Setembro de 2026. O [capítulo 19](19-dispensador-de-comprimidos.md)
descreve-o.

O caminho de subida já está construído: o hub descodifica as tramas, publica a
toma e o estado, e confirma cada pacote. Falta o de descida — configuração,
controlo e o plano dos nove alarmes.

**Porquê.** O segundo modelo ganha em todas as dimensões que se mediram. Os
eventos de medicação trazem instante absoluto em ISO-8601, contra um `HH:MM` sem
data nem fuso no callback. Expõe nove alarmes em vez de seis, e telemetria que a
API REST não tem de todo — temperatura, humidade, e cinco falhas discriminadas
onde a API dá uma. Dispensa um callback público que a documentação do fabricante
deixa **sem autenticação nenhuma**. E não faz atravessar dados clínicos de
utentes portugueses por um servidor na China, que foi a razão pela qual o próprio
fabricante o recomendou.

A camada de entrada também é a que já existe: um descodificador sobre o socket
TCP, à maneira dos [relógios](02-ingestao-tcp-relogios.md), em vez de uma rota
HTTP com cliente e callback próprios.

**O que a decisão traz consigo.** A disponibilidade do serviço passa a ser nossa:
a aplicação do fabricante sugere que a dispensação se suspende quando o aparelho
perde a rede, pelo que uma indisponibilidade do hub deixa de ser um problema de
telemetria e passa a ser de função clínica. Falta confirmar se é mesmo assim no
M228.

**O que continua a depender do fabricante.**

1. **Cartões SIM Cat1.** O modem é 4G Cat1 e os cartões M2M são CatM — não é
   configuração, é tecnologia de rádio incompatível. A escolha de operador é
   decisão de contrato, a partir da lista que o fabricante forneceu.
2. **Firmware com português.** Existe, mas só é instalável de fábrica, o que o
   torna uma condição de encomenda e não uma actualização.
3. **A chamada de emergência é um serviço pago.** Existe no protocolo, está
   desligada, e activá-la é conversa comercial.
4. **A correspondência entre identidades.** O protocolo identifica o aparelho por
   um inteiro de 64 bits com MAC ou IMEI; a aplicação mostra um número de série
   com prefixo `89-`. A relação entre os dois ainda não está estabelecida, e é
   dela que depende a entrada na whitelist.

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
| O contrato documentava doze capacidades de telemetria, e publicavam-se muitas mais              | corrigido no [capítulo 06](06-normalizacao.md)                           |
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
