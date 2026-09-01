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

## 5. Não há cópias de segurança das bases de dados

**O que é.** O servidor não guarda nenhum `mysqldump` periódico das duas bases.

**Porque importa.** A produção tem o inventário de dispositivos, as licenças, as
contas da API e o histórico de configurações. Nada disso se reconstrói a partir
dos aparelhos.

**Estado.** Reconhecido. A estratégia está por definir, e o ponto que a torna
menos trivial do que parece é a expiração: uma cópia periódica sem política de
retenção enche o disco do servidor que é suposto proteger.

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

--

## Coisas para fazer amanhã

- Investigar novo gateway MOKO, parece uma coisa ligada à tomada.
- Ver como fazer backups da base de dados de produção do hub
- Fix ao Gateway W812 NCS, Chamada de Enfermeira, ir ao PC do quarto da D. Alice
- Ver onde fica a lógica se existe deteção de fuga gateway <-> pulseira
