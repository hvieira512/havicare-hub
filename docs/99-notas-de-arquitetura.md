# 99 — Notas de arquitetura

Os capítulos anteriores descrevem o sistema implementado. Este reúne as questões
de arquitetura em aberto e as decisões pendentes, mantidas separadas para que a
descrição do sistema não se confunda com propostas de alteração.

Nenhuma das questões listadas constitui defeito que exija correção imediata.

---

## 1. O radar usa duas chaves diferentes para o mesmo aparelho

**O que é.** Em todas as ingestões, a chave que vai no tópico MQTT e a chave com
que se escreve na dashboard são a mesma. No radar Qinglanst não são: publica com
o `uid` que veio no tópico de origem, e escreve na dashboard com o IMEI da
whitelist.

**Porque importa.** Na base de exemplo os dois valores coincidem, mas nada no
código o garante. Se divergirem, o mesmo radar aparece com dois nomes conforme se
olhe para o broker ou para a interface, e correlacionar os dois passa a exigir
uma consulta à whitelist que ninguém sabe que é precisa.

**Onde.** `src/Ingress/Mqtt/Qinglanst/Bridge.php` — comparar as chamadas de
publicação com as de escrita na dashboard.

---

## 2. O downlink por MQTT só serve relógios

**O que é.** O subscritor de downlink exige que o terceiro segmento do tópico
seja `watch`. Não há caminho MQTT para gateway, radar, NCS, pulseira ou sensor de
fralda.

**Porque importa.** A API já sabe mandar comandos a mais tipos do que o MQTT. Um
integrador que descubra o tópico de downlink e assuma que ele vale para tudo fica
sem entender porque nada acontece — não há erro, a mensagem simplesmente não
casa com o filtro.

Ou o MQTT passa a aceitar os outros tipos, ou fica documentado como sendo só
para relógios de propósito. Hoje é o segundo caso por omissão, não por decisão.

**Onde.** `src/Device/HubDownlinkSubscriber.php`.

---

## 3. Duas capacidades publicadas continuam fora do catálogo, de propósito

**O que é.** O `heartbeat` e o `device_config` saem como telemetria e não têm
entrada no catálogo.

**Porque está certo assim.** O `heartbeat` é o sinal de vida, não uma medição —
e já está excluído do histórico da dashboard pela mesma razão. O `device_config`
é a confirmação de uma configuração, não uma leitura. Declará-los como
capacidades punha na matriz por modelo dois interruptores que ninguém quereria
desligar.

Fica registado para a pergunta não voltar a ser feita do zero.

---

## 4. Os alarmes dos relógios são telemetria; os do radar são eventos

**O que é.** Um SOS de um relógio sai no canal `telemetry`, com `type: "alarm"`.
Uma queda detetada por um radar sai no canal `events`.

**Porque importa.** São a mesma classe de acontecimento e chegam por canais
diferentes, com garantias de entrega diferentes: `events` vai a QoS 1, `telemetry`
a QoS 0. **Um SOS de relógio tem menos garantia de entrega do que uma queda de
radar**, e isso não foi escolhido — resultou de os dois caminhos terem sido
escritos em alturas diferentes.

É a divergência com maior impacto potencial das aqui listadas.

---

## 5. A W6 identifica o toque pela configuração, não pelo protocolo

**O que é.** A pulseira W6 não tem trama de alarme. O modo de toque é deduzido de
_qual_ espaço de anúncio apareceu, e os identificadores desses espaços são uma
convenção que a pulseira tem de ser configurada para cumprir.

**Porque importa.** Uma W6 configurada de outra maneira é vista, mas os toques
dela não são lidos — e não há sinal nenhum de que isso está a acontecer. Já está
assinalado no código com um comentário `ponytail:`; a passagem a configuração
faz sentido quando aparecer uma segunda W6 com outra convenção.

**Onde.** `src/Ingress/Mqtt/Moko/W6Decoder.php`.

---

## 6. O `ConnectionRegistry` ainda fala de WebSocket

**O que é.** O registo de ligações rotula o transporte como `'websocket'` quando
não é uma ligação TCP. Não existe implementação de WebSocket nenhuma no
repositório.

**Porque importa.** É pouco, mas é enganador: sugere um transporte que não
existe, e o valor `websocket` pode chegar a uma sessão e daí ao histórico.

---

## 7. O IMEI predefinido do simulador não está no seed

**O que é.** `make simulate-vivistar-tcp` usa, por omissão, o IMEI
`865028000000308`, que não existe no `database/seed.sql`. O hub recusa-o e fecha
a ligação — corretamente.

**Porque importa.** É a primeira coisa que alguém faz depois de levantar o
projeto, e falha. Passar o valor por omissão para um IMEI semeado resolve, e é
uma linha.

**Onde.** `Makefile`, alvos `simulate-vivistar-tcp` e `listen-vivistar-tcp`.

---

## 8. O `composer.json` aceita PHP 8.1

**O que é.** Declara `"php": ">=8.1"`. O `Dockerfile` fixa 8.4 e a integração
contínua corre 8.4 e 8.5.

**Porque importa.** Nada garante que o código corra em 8.1, porque nunca foi
testado lá. A restrição devia declarar o que se testa.

---

## 9. Ausência de tabela de telemetria

**Descrição.** O histórico reside exclusivamente no Redis, limitado a 100
entradas por lista e por dispositivo.

**Fundamento.** O âmbito da plataforma é a normalização e a entrega; o
arquivo é responsabilidade das aplicações que integram. A escrita de cada
leitura numa tabela alteraria a natureza do produto e introduziria um problema
de crescimento que atualmente não existe.

Fica registado por constituir uma decisão de arquitetura e não uma omissão.

---

## Divergências corrigidas

Registo das divergências já resolvidas, para não voltarem a ser reportadas como
novas:

| O quê                                                                                           | Estado                                              |
| ----------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| O `StartupBanner` anunciava tópicos com quatro segmentos, forma que o hub já não produz         | corrigido                                           |
| O `.env.example` documentava um par de variáveis de administrador de arranque que não existem   | corrigido                                           |
| O `AGENTS.md` era uma cópia desatualizada do `CLAUDE.md` e mandava trabalhar direto em produção | corrigido                                           |
| O `README.md` descrevia o hub como um encaminhador de bytes crus                                | reescrito                                           |
| A documentação dava os tópicos do NCS com quatro segmentos                                      | corrigido no [capítulo 03](03-ingestao-mqtt-ncs.md) |
| O contrato prometia `pulseBpm` em `blood_pressure`, que nunca foi emitido                       | corrigido no [capítulo 06](06-normalizacao.md)      |
| O contrato documentava doze capacidades de telemetria; são vinte                                | corrigido no [capítulo 06](06-normalizacao.md)      |
| O `README` dizia que a integração contínua procurava "skipped" na saída; faz o contrário        | corrigido no [capítulo 16](16-testes.md)            |
| O `README` dizia que a dashboard aceitava `license_client`; nunca aceitou                       | corrigido no [capítulo 13](13-dashboard.md)         |
| O `docs/` não era referenciado por nenhum ficheiro do repositório                               | corrigido                                           |
| O `alarm` dos relógios era publicado e não existia no catálogo                                  | acrescentado, com migração                          |
| A `proximity` era publicada para pulseiras e sensores e não existia no catálogo                 | acrescentada, com migração                          |
| O NCS declarava `pager_call` e publicava `help_call`                                            | alinhado; a mensagem no MQTT não mudou              |
