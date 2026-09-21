# Refactor do frontend da dashboard: consolidar `components/`

## Origem

O Hugo levantou isto assim: _"a nível de estrutura, não falta um diretório
`components` no frontend? Acho que os components que nós temos estão demasiado
espalhados"_. Ficou em fila atrás da integração do dispensador de comprimidos,
que já está concluída.

## Estado actual (verificado, não de memória)

`src/Dashboard/dashboard/` tem **87 ficheiros JS**. O directório `components/`
**já existe** mas só tem dois ficheiros:

- `components/state-badge.js` — exporta `stateBadge()` e `onlineBadge()`
- `components/device-license.js` — exporta `deviceLicenseBlock()`

E são usados a sério, de várias áreas — 9 sítios a importar, em
`settings/api-users.js`, `settings/companies.js`, `settings/models/list.js`,
`devices/device-modal.js`, `devices/detail.js`, `devices/device-card.js` e
`devices/config/index.js`.

**A convenção está estabelecida, o problema é que a maioria dos componentes não
foi para lá.** Continuam na raiz, misturados com módulos que não são componentes
(estado, armazenamento, tema, validação):

| ficheiro na raiz                                                       | linhas | exports | o que é                                                                                                                                                                                    |
| ---------------------------------------------------------------------- | ------ | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `widgets.js`                                                           | 180    | 9       | `field`, `sectionStrip`, `modelImageHtml`, `modelPreviewHtml`, `renderButtonGroup`, `deviceTypeIcon`, `renderDeviceTypeTiles`, `filterChips`, `emptyPanel` — **nove componentes num saco** |
| `grid.js`                                                              | 550    | 8       | tabela/grelha                                                                                                                                                                              |
| `telemetry-cards.js`                                                   | 438    | 4       | cartões de telemetria                                                                                                                                                                      |
| `phone.js`                                                             | 263    | 4       | campo de telefone com país                                                                                                                                                                 |
| `request-card.js`                                                      | 206    | 2       | cartão de pedido                                                                                                                                                                           |
| `pagination.js`                                                        | 135    | 2       | paginação                                                                                                                                                                                  |
| `card-shell.js`                                                        | 85     | 1       | moldura de cartão                                                                                                                                                                          |
| WatchProtocolInterface → TcpProtocolInterface                          |
| WatchProtocolRegistry → TcpProtocolRegi                                |
| WatchMessage → TcpMessage                                              |
| WatchResponse → TcpResponse                                            |
| {Wonlex,Vivistar,FourPTouch,PillDispenser}WatchProtocol → …TcpProtocol |
| $watchProtocols            →  $tcpProtocols                            |
| sendWatchResponse() → sendTcpResponse                                  |

Ficou de fora, de propósito, tudo o que é mesmo sobre relógios ou sobre vigiar: as WatchCapabilityDefinitions (que são o tipo de dispositivo watch), o CrashWatch, o SystemdWatchdog, o watchdog.conf e o simulador w6b-watch.php.

Os moves foram com git mv, por isso o histórico segue os ficheiros. Actualizei também as duas referências na documentação (capítulos 2 e 19) e acrescentei o TcpProtocolRegistry à tabela de implementação do capítulo 2, que não o listava.

Verificação

As sete do composer test, não as peças soltas:

style [OK]
analyse [OK] No errors
lint [OK]
unit 1092 testes, 26471 asserções
integration 337 testes, 1953 asserções
frontend 575 testes, 0 falhas
scenarios 6 de 6 — all scenarios passed

- `components/state-badge.js` — exporta `stateBadge()` e `onlineBadge()`
- `components/device-license.js` — exporta `deviceLicenseBlock()`

E são usados a sério, de várias áreas — 9 sítios a importar, em
`settings/api-users.js`, `settings/companies.js`, `settings/models/list.js`,
`devices/device-modal.js`, `devices/detail.js`, `devices/device-card.js` e
`devices/config/index.js`.

**A convenção está estabelecida, o problema é que a maioria dos componentes não
foi para lá.** Continuam na raiz, misturados com módulos que não são componentes
(estado, armazenamento, tema, validação):

| ficheiro na raiz     | linhas | exports | o que é                                                                                                                                                                                    |
| -------------------- | ------ | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `widgets.js`         | 180    | 9       | `field`, `sectionStrip`, `modelImageHtml`, `modelPreviewHtml`, `renderButtonGroup`, `deviceTypeIcon`, `renderDeviceTypeTiles`, `filterChips`, `emptyPanel` — **nove componentes num saco** |
| `grid.js`            | 550    | 8       | tabela/grelha                                                                                                                                                                              |
| `telemetry-cards.js` | 438    | 4       | cartões de telemetria                                                                                                                                                                      |
| `phone.js`           | 263    | 4       | campo de telefone com país                                                                                                                                                                 |
| `request-card.js`    | 206    | 2       | cartão de pedido                                                                                                                                                                           |
| `pagination.js`      | 135    | 2       | paginação                                                                                                                                                                                  |
| `card-shell.js`      | 85     | 1       | moldura de cartão                                                                                                                                                                          |
| `dialogs.js`         | 72     | 4       | diálogos                                                                                                                                                                                   |
| `tooltips.js`        | 31     | 2       | tooltips                                                                                                                                                                                   |

Há ainda um `cards/` separado (`diaper.js`, `gateway.js`, `location.js`,
`ncs.js`, `radar.js`, `sleep.js`, `shared.js`) — cartões por tipo de
dispositivo. Vale a pena decidir se `cards/` é um subconjunto de `components/`
ou uma categoria própria, e não deixar as duas coisas a coexistir por acidente.

## O critério que o `components/` actual estabelece

Olhando para os dois que lá estão: **uma função de render que devolve HTML, sem
estado próprio, reutilizada por mais do que uma área.** Sugiro manter esse
critério e não alargá-lo — `state.js`, `storage.js`, `theme.js`, `validation.js`,
`format.js`, `dom.js`, `html.js` e `api/` **não são componentes** e devem ficar
onde estão.

O `widgets.js` é o caso mais claro: nove componentes independentes num ficheiro
só, importado por meio mundo. Separá-los é onde está a maior parte do ganho.

## Restrições do projeto

- **Ler o `CLAUDE.md` da raiz primeiro.** Tem as convenções, e não são as
  habituais.
- **Comentários curtos, sobre o que o código faz, e só quando precisos.** Nunca
  a narrar a alteração nem o que lá estava antes — isso é para a mensagem de
  commit. Nada de over-commenting.
- **Indentação de 4 espaços, também no JavaScript.**
- **A dashboard é toda em português de Portugês; a
  tradução é no frontend, não no contrato.
- **Mensagens de commit em português**, a explicar o porquê.

## Verificação — e duas armadilhas que custaram caro hoje

Corre **`composer test`**, não as peças soltas. São **sete** verificações:
`style` (phpcs), `analyse` (phpstan), `lint:frontend` (eslint), `test:unit`,
`test:integration`, `test:frontend`, `test:scenarios`. Correr só o `npm test`
deixa o eslint de fora.

**Armadilha 1 — maiúsculas no caminho dos testes.** O directório é
`tests/Frontend/` com **F maiúsculo**. No macOS o sistema de ficheiros não
distingue, o git distingue, e um `git add tests/frontend/...` **não corresponde a
nada e não diz nada**. Hoje cinco ficheiros de teste ficaram por commitar assim,
sem erro nenhum. Confirma sempre com `git status` depois de adicionar.

**Armadilha 2 — o CI.** Depois do push, `gh run list`. O CI corre em PHP 8.4 e
8.5 e apanha em 60 segundos o que passa na máquina local. Hoje esteve vermelho
dez horas e meia por uma linha em branco num bloco de `use`.

Há um `.githooks/pre-commit` que corre o phpcs e o eslint antes de cada commit
(fica ligado sozinho no `composer install`). Não substitui o `composer test`.

## Nota sobre trabalho em paralelo

Se houver mais do que um agente a alterar ficheiros ao mesmo tempo, cada um corre
no seu próprio worktree. E **nunca `git add -A`** — só os ficheiros que se tocou,
porque há outros agentes no mesmo repositório.

## Sugestão de ordem

1. Separar o `widgets.js` em componentes individuais dentro de `components/` —
   é o maior ganho e o mais mecânico.
2. Mover os componentes puros da raiz (`card-shell`, `pagination`, `tooltips`,
   `dialogs`, `phone`, `request-card`).
3. Decidir o que fazer ao `grid.js` e ao `telndes, e
   pode fazer sentido serem subdirectórios em vez de ficheiros.
4. Decidir a relação entre `cards/` e `compon

Cada passo é um commit, com a suite a passar entre eles. São 88 ficheiros de
teste de frontend a prender o comportamento, por isso um movimento que parta
alguma coisa aparece logo.

Duas coisas que deixei lá de propósito: a armom F maiúsculo, que me comeu cinco ficheiros de teste hoje sem dar erro, e o hábito de ver o gh run list depois do push.
