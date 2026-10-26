# 20 — O frontend da dashboard

## Âmbito

Este capítulo é sobre **como o código do frontend está organizado**. O que a
dashboard faz, e como se articula com as outras camadas, está no
[capítulo 13](13-dashboard.md).

A interface do hub: uma página só, servida em PHP, com o comportamento em
módulos ES que o browser carrega tal como estão. **Não há build step** — nem
bundler, nem transpilador, nem `node_modules` em produção. Guardar um ficheiro
e recarregar a página é o ciclo todo.

Bootstrap, Font Awesome, SweetAlert2 e o Swagger UI estão no repositório, em
`assets/vendor/`, e são servidos por nós — ver o `README.md` em
`src/Dashboard/assets/vendor/`. O resto é nosso.

Este capítulo não fecha com a tabela de implementação dos restantes: o mapa
ficheiro a ficheiro é a árvore da secção 2, com uma linha por
módulo, e uma tabela ao lado dela seria a mesma matéria escrita duas vezes.

```
src/Dashboard/
├── index.php          a página
├── main.js            o <script type="module"> que a página carrega
├── assets/css/        o CSS, por área: base, shell, device, config, login e modals
├── components/        os partials PHP que produzem a marcação
├── assets/            o logo, as fontes e as bibliotecas de terceiros
├── dashboard/         todo o JavaScript  ← o resto deste documento
└── *.php              o servidor HTTP da dashboard e os seus stores
```

## 1. As quatro regras

São só estas, e explicam onde cada ficheiro está:

1. **Uma funcionalidade nunca importa outra funcionalidade.** Pode importar
   `api/` e o `core/`. Se `devices/` e `settings/` precisam de se
   falar, é o `app.js` que os apresenta.
2. **Um módulo que só uma funcionalidade usa vive dentro dela.** Sobe ao `core/`
   quando aparecer o segundo consumidor — e não antes. Dentro de uma
   funcionalidade, o que forma um conjunto ganha a sua pasta: `devices/detail/`,
   `devices/gateways/`, `devices/radar/`.
3. **Só a raiz de composição conhece toda a gente.** É o `app.js` mais o
   `wiring/devices.js`: cacheia os elementos, cria os modais e liga os
   ouvintes que atravessam funcionalidades. Os ouvintes do dispositivo vivem lá
   porque quase todos atravessam duas ou três partes do `devices/`. Os das
   definições não atravessam nada, e cada secção liga os seus no `initX()`,
   que o `settings/index.js` chama.

4. **Uma peça de interface nasce em `components/` se for pura e mais do que uma
   área a desenhar; nasce ao lado da funcionalidade se só ela a desenhar.**
   Pura é: recebe dados, devolve uma string de HTML, não toca em nenhum
   elemento, não guarda estado e não conhece nenhuma biblioteca. O
   `devices/device-license.js` e o `devices/device-card.js` são peças puras que
   só os dispositivos desenham, e por isso vivem lá; sobem a `components/` no
   dia em que aparecer o segundo consumidor, que é a regra 2 aplicada a peças de
   interface.

   Um widget que escreve no elemento que recebeu, que abre um modal ou que fala
   com o Bootstrap **não é um componente**: ou é parte da funcionalidade, ou é
   um módulo do `core/` (o `dialogs.js`) ou da área que o usa (o
   `devices/phone.js` e o `devices/tooltips.js`). A
   fronteira é essa, e não o tamanho.

   Quando um módulo destes tem os dois lados, separam-se: o
   `components/pagination.js` devolve os botões e o `core/pagination.js` é
   que os escreve nos três elementos do painel. A janela de sete lugares passou
   a testar-se sozinha, sem um documento à volta.

   E em nenhum dos casos se acrescenta ao fundo de um ficheiro que já existe só
   por ser do mesmo género. Foi assim que os cartões de telemetria chegaram a
   seiscentas linhas.

Cada pasta com mais do que um ficheiro repete a regra 3 à sua escala:
`settings/index.js` é o único que conhece as quatro secções do modal, e
`settings/shell.js` é o que todas partilham sem conhecer nenhuma.

### Porque é que a regra 4 existe

Um cartão de dispositivo estava espalhado por quatro sítios: a marcação numa
função do `list.js`, o estilo num CSS global, o ouvinte no `wiring/`, e
os dados no `state`. Mudar um widget obrigava a abrir quatro ficheiros, e nada
dizia que os quatro pedaços eram a mesma coisa.

É também a razão de os ficheiros grandes serem grandes. O
`components/cards/telemetry.js` não está mal escrito: é o sítio onde tudo o que
é *do mesmo género* se acumulou, porque não havia sítio nenhum para o que é *da
mesma funcionalidade*. Organizar por camada em vez de por funcionalidade dá
exactamente isto.

É por isso que o `config/inputs/` está partido por fornecedor e não por
tamanho: a linha que separa os grupos é o que cada protocolo declara nas
definições em `src/Command/Configuration/Definition/`.

O `devices/device-card.js` é o primeiro feito assim, e mostra até onde a regra
vai. Juntou o que se podia juntar sem pagar por isso: a marcação e o esqueleto
ficam no mesmo módulo, porque têm de mudar juntos — o esqueleto é o cartão com
as mesmas classes e barras no lugar do texto, e é isso que impede a lista de
saltar —, e o nome do `data-action` passa a ser exportado, para o ouvinte
delegado do `wiring/` não repetir a string que a marcação escreve.

**O CSS não vem.** Fica no bloco `.device-card*` do `assets/css/device.css`,
marcado com um comentário que aponta para o módulo. Sem passo de compilação, um
ficheiro de estilo por widget é um pedido HTTP por widget, e foi por isso que a
divisão do CSS parou em cinco ficheiros por área. Co-locar o estilo custava
mais do que resolvia.

E o ouvinte também não vem: continua delegado na raiz da lista, que é o padrão
da casa — as opções são redesenhadas a cada resposta, e um ouvinte por cartão
obrigava a religá-los todos de cada vez.

A regra não pede uma migração. Pede que o próximo widget nasça no sítio certo,
e que os ficheiros grandes encolham por atrito, à medida que se passa por eles.

O `devices/detail/event-summary-cards.js` é o segundo, e mostra como é o atrito na
prática. A última chamada de ajuda e a última queda estavam no catálogo dos
cartões só por serem cartões: o resto daquele ficheiro é um cartão por *tipo de
telemetria*, com uma tabela a mapear tipo em ícone e corpo, enquanto estes dois
lêem o histórico inteiro e resumem-no. Ao saírem, o que era partilhado subiu em
vez de vir atrás — o `displayPersonIndex` para o `format.js`, as tabelas de
nomes para o `domain.js` —, que é a regra 2 a funcionar. O catálogo ficou com
menos 170 linhas.

## 2. A árvore

```
dashboard/
├── app.js                  a raiz de composição
├── notifications.js        o sino da barra (funcionalidade de um ficheiro)
├── wiring/
│   └── devices.js          os ouvintes da lista, filtros, modal, configuração e detalhe
│
├── core/                   o núcleo partilhado: o que duas ou mais áreas usam
│   ├── dom.js              o `els` (todos os elementos com id, num objeto), o renderInto e o whileBusy
│   ├── state.js            o objeto de estado, um só, com um sub-objeto por ecrã
│   ├── html.js             html`` e raw(): a marcação escapa por omissão
│   ├── format.js           esc(), datas pelo Intl, durações, horas, unidades e valores de campo
│   ├── i18n.js             o dicionário pt-PT: capacidades, opções, ajudas, verbos
│   ├── pt-PT.json          o texto do dicionário, servido na ilha #hub-i18n
│   ├── domain.js           o vocabulário: tipos de dispositivo, modelos, fornecedores, licenças
│   ├── capability-catalog.js  o ícone de cada secção de capacidades
│   ├── licenses.js         as licenças, uma vez por sessão
│   ├── medication-plan.js  as horas dos planos de medicação, achatadas
│   ├── medication-runout.js  a data em que a medicação acaba
│   ├── radar-style.js      como se pinta o que um radar vê: posturas e tipos de área
│   ├── dialogs.js          as confirmações e os avisos (SweetAlert2)
│   ├── validation.js       marca o campo inválido e escreve a mensagem por baixo
│   ├── pagination.js       escreve o paginador no painel, e resolve a página de um clique
│   ├── wizard.js           o motor das perguntas dos assistentes de aparelho e de modelo
│   ├── storage.js          as chaves e os acessos ao localStorage
│   ├── theme.js            o tema claro/escuro pelo data-bs-theme
│   ├── sticky-top.js       mede o que está colado ao topo e publica-o em --sticky-top
│   └── observability.js    o handler global de erros: o que falha sem catch deixa rasto
│
├── components/             uma peça de interface por ficheiro: recebe dados, devolve HTML
│   ├── state-badge.js      a pastilha de estado: ponto ou ícone, rótulo e tom
│   ├── form-field.js       etiqueta, controlo e linha de ajuda
│   ├── device-type-tiles.js  o mosaico de tipos de dispositivo, e o ícone de cada um
│   ├── chips.js            as pastilhas de secção e as de filtro aplicado
│   ├── empty-panel.js      o estado vazio de um painel, em texto
│   ├── pagination.js       os botões do paginador: as setas e a janela de sete lugares
│   ├── wizard-cards.js     a migalha, a barra de progresso e as grelhas de tipo e de fornecedor
│   ├── model-image.js      a imagem de um modelo, ou o ícone do tipo quando não há
│   └── cards/              os cartões de telemetria, que são um bloco fechado
│       ├── telemetry.js        o catálogo: ícone, cor e corpo por tipo
│       ├── shell.js            a moldura de um cartão
│       ├── request.js          o cartão de um pedido ao dispositivo
│       ├── shared.js           o resumo compacto que mais do que uma família usa
│       ├── medication.js       a faixa do dia: uma coluna por dose
│       └── radar.js · diaper.js · gateway.js · ncs.js · location.js · sleep.js
│
├── api/                    o único sítio com fetch
│   ├── http.js             requestJson() (com query opcional), formRequest() e o token
│   ├── errors.js           o texto de cada `code` da API, do dicionário
│   └── index.js            um nome por endpoint, que é o que o resto importa
│
├── auth/session.js         login, refresh do token, e o ecrã de entrada
│
├── devices/                o ecrã principal
│   ├── list.js             a coluna da esquerda: lista, busca, paginação, modal de escolha
│   ├── list-filters.js     o painel de filtros da listagem: o que desenha e o que trata
│   ├── filter-tree.js      uma opção de uma árvore de escolha: a caixa, o nome e a contagem
│   ├── device-card.js      o cartão de um dispositivo, e o seu esqueleto
│   ├── device-license.js   a empresa e a licença de um dispositivo, ou «Sem licença»
│   ├── device-modal.js     o modal de um dispositivo, e a porta do painel de configurações
│   ├── create-wizard.js    o assistente de adicionar: perguntas, grelhas, e a criação
│   ├── edit-wizard.js      a mesma classificação, no modal de editar
│   ├── classification-ui.js  a grelha de modelos, o escolhedor de licença e as pastilhas da classificação
│   ├── phone.js            o campo de telefone com indicativo
│   ├── tooltips.js         re-atar os tooltips do Bootstrap depois de um render
│   ├── stream.js           o stream que traz os eventos em directo
│   ├── detail/             a coluna da direita
│   │   ├── detail.js           eventos, pedidos, cartões e filtros do painel
│   │   ├── detail-filters.js   o que reduz o histórico de um dispositivo, e pagina os dois painéis
│   │   ├── activity-table.js   a tabela genérica de atividade (telemetria e pedidos): linhas e gaveta
│   │   ├── connection-history.js  a faixa das ligações, com as quebras e a lista delas
│   │   └── event-summary-cards.js  a última chamada de ajuda e a última queda
│   ├── gateways/           os gateways de um dispositivo
│   │   ├── gateway-links.js    que gateways são elegíveis (lógica pura)
│   │   ├── gateway-links-ui.js os cards de gateway no modal
│   │   ├── gateway-signal.js   o RSSI de cada par (dispositivo, gateway)
│   │   └── device-check-card.js  a verificação de um aparelho a partir dos gateways
│   ├── radar/              a planta de um radar
│   │   ├── radar-map-modal.js  o modal da planta, com os sinais vitais ao lado
│   │   ├── radar-scene.js      a divisão em Konva
│   │   ├── radar-vitals.js     os gráficos dos sinais vitais
│   │   ├── radar-area-editor.js · radar-area-draft.js  editar as áreas e validar o rascunho
│   │   └── load-script.js      carrega o Konva à primeira vez que faz falta
│   └── config/             o separador de configurações de um dispositivo
│       ├── index.js        desenha a raiz e as secções
│       ├── catalog-model.js  as secções do painel, já filtradas, agrupadas, ordenadas e com nome
│       ├── panel.js        gravar, refrescar, e o estado de cada bloco
│       ├── delivery.js     o que aconteceu ao que se enviou: o estado e a frase de cada um
│       ├── handlers.js     os eventos delegados na raiz do painel
│       ├── inputs/         um descritor por tipo de campo, agrupado por fornecedor
│       │   ├── index.js        junta os grupos num registo só
│       │   ├── shared.js       o contador de ids, o campo numérico, o interruptor
│       │   ├── generic.js      o que mais do que um fornecedor declara
│       │   ├── capability.js   alarmes, contactos SOS, lista branca, dados pessoais
│       │   └── four-p-touch.js · vivistar.js · wonlex.js · pill-dispenser.js · radar.js · scale.js
│       ├── readers.js      as primitivas que lêem o payload de volta do DOM
│       ├── normalizers.js  as formas que os protocolos usam
│       ├── row-editing.js  adicionar e remover linhas (contactos, alarmes, planos)
│       ├── protocol-catalog.js  o que cada protocolo aceita
│       ├── alarm-fields.js      horas e recorrências dos alarmes
│       ├── loaded-cells-runout.js  o «carregado até» do dispensador como data de fim
│       ├── four-p-touch-take-pills.js  o lembrete de comprimidos da 4P Touch
│       └── take-pills-audio.js         a gravação de voz desse lembrete
│
└── settings/               o modal de definições
    ├── index.js            a raiz de composição do modal: conhece as secções
    ├── shell.js            o que as secções partilham: menu, contagens, paginação, separadores
    ├── row-editor.js       a linha aberta numa listagem onde só pode estar uma
    ├── button-group.js     uma escolha única em botões
    ├── row-actions-menu.js o menu «⋮» dos verbos de uma linha
    ├── capabilities.js     o separador Capacidades: o catálogo por tipo de dispositivo
    ├── companies.js        o separador Licenças: empresas com as suas licenças dentro
    ├── radar-credentials.js  as credenciais da cloud dos radares, por licença
    ├── api-users.js        o separador Utilizadores API
    ├── denylist.js         a denylist: ver e desbloquear
    └── models/             o separador Catálogo, que são três painéis
        ├── shell.js        a troca de painel
        ├── list.js         painel 1: a árvore tipo → fornecedor → modelo
        ├── form.js         painel 2: criar um modelo novo
        ├── detail.js       painel 3: a ficha de um modelo e as suas capacidades
        └── capabilities-editor.js  a grelha de capacidades de um modelo
```

## 3. Como um ecrã está montado

Todos seguem a mesma forma, e vale a pena reconhecê-la antes de abrir qualquer
ficheiro.

**O `els`.** O `dom.js` exporta um objeto só com todos os elementos da página,
que o `cacheElements()` do `app.js` enche uma vez ao arranque. Cada módulo
importa-o, e nenhum faz `getElementById` por sua conta. Num teste, o
`cacheElements(view)` põe lá os elementos que o teste montou.

```js
import { els } from "../dom.js";
```

Um `id` que não exista dá `undefined`, e não um erro: renomeá-lo num template
deixava o ouvinte por ligar e o botão a não fazer nada, em silêncio. O
acoplamento entre os `id` do PHP e o JavaScript é o preço de não haver build, e
não vai deixar de existir — mas está verificado. O
`tests/Unit/Dashboard/DashboardElementIdsTest.php` desenha a página a sério,
com os modais e os auxiliares já corridos, e exige que cada `els.x` que o
JavaScript lê exista mesmo.

**O estado.** Tudo o que sobrevive a um render está em `core/state.js`, com um
sub-objeto por ecrã (`state.settingsModal`, `state.deviceModal`,
`state.summary`). O que só um módulo lê fica numa variável dele — nalgumas
secções das definições, a página que está à vista, que morre quando o modal
fecha.

A forma de cada sub-objeto declara-se uma vez, no `blankDeviceModal` e no
`blankSettingsModal`. O `Object.seal` só apanha gralhas no primeiro nível, e
enquanto as chaves do segundo se repuseram à mão houve cinco a serem escritas
sem nunca terem sido declaradas.

**Os eventos.** São delegados na raiz de cada zona e resolvidos por
`data-action`:

```html
<button data-action="selectCapabilitySupplier" data-value="3">Wonlex</button>
```
```js
const button = event.target.closest('[data-action="selectCapabilitySupplier"]');
if (button) selectCapabilitySupplier(button.dataset.value);
```

Isto existe porque as listas são redesenhadas por `innerHTML` a cada resposta:
atar um ouvinte a cada botão obrigava a religá-los todos de cada vez.

**Os dados.** Só `api/` chama `fetch`. Toda a resposta tem a forma `{data,
pagination}` ou `{error: {code, message}}`, e quem chama verifica
`response.error` — não há `throw` a atravessar camadas.

## 4. Do PHP até ao JS

Um `id` é o contrato entre os dois lados, e é atravessado à mão:

```
components/modals/settings.php   <div id="capabilitySupplierButtons">
        ↓
dashboard/core/dom.js            cacheElements() apanha todo o [id] da página
        ↓
dashboard/settings/capabilities.js   els.capabilitySupplierButtons.innerHTML = …
```

Metade dos `id` nasce em argumentos de helpers PHP
(`search_input('capabilityCatalogSearch', …)`), por isso um grep pelo
`id="..."` não os encontra todos — procura pelo nome sozinho. **Nada verifica
que os dois lados concordam:** uma gralha dá `null` silencioso no `els`. Ao
mexer num `id`, mexe-se nos três sítios.

## 5. Configurações que não viajam

Uma configuração é normalmente um downlink à espera de acontecer: vira comandos
nativos, sai para o dispositivo, e o bloco mostra o estado da entrega. Nem
todas — a sensibilidade dos alertas de um medidor de fraldas não tem para onde
ir, porque o sensor é um beacon BLE que só transmite, e o que ela muda é a
regra com que o hub deriva o estado da fralda.

**O frontend não sabe disto, e é de propósito.** Quem decide é a capacidade em
PHP, que o `CapabilityRegistry::travelsToDevice` dá como aplicada no hub: o `DeviceConfigurationUpdateService`
guarda o valor e dá-o por aplicado sem comandos, o bloco recebe `command: ""`
no catálogo, e o painel lê disso as duas únicas diferenças que se vêem — o
botão diz "Guardar" em vez de "Enviar", e não se mostra vocabulário de
protocolo que não existe.

Isto já teve um caminho paralelo no frontend: uma pasta `hub-rules/`, estado
próprio, handlers próprios, e um estado de UI a dizer "Aplicada no hub" para
exprimir o que a via genérica já dizia com "Aplicado". Se aparecer a tentação
de repetir esse padrão para uma regra nova, a resposta é uma capacidade nova em
PHP.

## 6. Onde ponho isto?

| o que estou a escrever | onde vai |
|---|---|
| uma chamada nova à [API](09-api.md) | `api/<recurso>.js`, exportada em `api/index.js` |
| uma pergunta sobre tipos de dispositivo, modelos ou licenças | `core/domain.js` |
| HTML puro que dois ecrãs desenham | `components/<nome>.js`, um ficheiro por peça |
| HTML que um ecrã desenha | o ficheiro desse ecrã |
| uma listagem plana que se filtra | uma linha por registo no ficheiro do ecrã, com os filtros do `columns` da API por cima |
| um handler de clique | ao lado do módulo que desenha o que ele trata |
| um campo novo de configuração | um descritor em `devices/config/inputs/<grupo>.js` |
| uma configuração que o hub aplica sem downlink | nada de especial no frontend: é a capacidade em PHP, acrescentada ao `CapabilityRegistry::travelsToDevice` |
| um ecrã novo nas definições | `settings/<nome>.js`, ligado no `settings/index.js` |
| estado que sobrevive a um render | `core/state.js`, no sub-objeto do ecrã |
| um módulo que duas áreas usam | `core/<nome>.js` |

## 7. A rede de segurança

```bash
npm run lint                   # eslint sobre main.js, dashboard/, assets/js/ e tests/Frontend
npm test                       # ~130 ficheiros em tests/Frontend/
composer test:unit             # inclui os testes que lêem estes ficheiros como texto
```

As quatro suites e o que cada uma cobre estão no [capítulo 16](16-testes.md).

Dois valem por si:

- **`tests/Frontend/module-graph.test.js`** importa cada porta de entrada e
  falha se algum import não resolver. Um nome importado que ninguém exporta
  deita a dashboard abaixo com uma página em branco, e nenhum `node --check` o
  apanha. O mesmo teste falha se um módulo ficar órfão. São **quatro** portas e
  não uma, e cada uma está lá porque quem a traz, traz-na por `import()`: o
  `main.js` é o que o browser carrega; o `dashboard/app.js` entra depois do
  login; e o `devices/config/panel.js` e o `handlers.js` entram quando se abre
  o separador de configurações. Segui-las só pelos `from` deixava de fora quase
  todo o grafo.

  **Quem puser um `import()` novo acrescenta aqui a porta dele**, senão o que
  está por trás deixa de ser verificado sem que nada o diga.
- **O `no-unused-vars` do eslint** é erro: é o que apanha um import que ficou
  para trás depois de mover código. Quem apanha um import **partido** é o `node --test`,
  que falha logo a carregar.

Vários testes em `tests/Unit/Dashboard/` lêem estes ficheiros **como texto** e
afirmam que certas linhas lá estão. Mover uma função entre ficheiros parte-os —
é de propósito, e a correcção é apontar o teste ao ficheiro novo.

## 8. O que ainda não está direito

- **`settings/models/list.js` e `form.js` importam-se um ao outro.** A lista
  repõe o formulário do outro painel, o formulário volta à lista depois de
  gravar. Resolvem-se em tempo de chamada e não quebram nada; parti-los
  obrigava a um registo de callbacks que custa mais do que resolve. É o único
  ciclo do grafo.
- **Um tipo de campo de configuração declara as suas faces num descritor só** —
  `render` ou `control` e `read`, em `devices/config/inputs/`, todos com a
  assinatura `(entry, desired, meta)`. O valor de partida, as opções e os
  limites vêm da API; o texto, do `i18n.js`.

  O `render` e o `control` decidem a forma do bloco e não são cosmética: com
  `render` o descritor desenha o corpo inteiro do cartão, com `control` devolve
  só o controlo e o painel monta o cartão à volta dele — nome, subtítulo,
  pastilha de estado e a unidade colada ao campo. O que se desenha tem de ser o
  que o `read` devolve — o `config-payload-roundtrip.test.js` prende-o.
- **As famílias de cartões que só existem num aparelho vivem em `cards/`** —
  radar, fralda, gateway, NCS e localização. A linha não é o tamanho: uma
  postura ou uma contagem de pessoas não existe num relógio. O eixo do *tipo de
  dispositivo* não serviria, e foi testado — a frequência cardíaca sai de
  relógio, radar e pulseira, e a bateria de quatro tipos.
- **O que fica no `components/cards/telemetry.js` é o registo e as
  primitivas**, que são genuinamente uma coisa só: o mapa dos cartões por tipo,
  o ícone, o tom e a badge de estado. Partir isso por tamanho só espalharia.
- **O CSS está dividido por área**, em seis ficheiros de `assets/css/`:
  `base.css` (tokens e fontes), `shell.css` (moldura, navbar, cartões),
  `device.css` (o ecrã do dispositivo), `config.css` (o modal do dispositivo, o
  painel de configuração e os campos de um alarme), `login.css`, e o
  `modals.css` com os modais. A ordem no `<head>` é essa, e é a cascata: o
  `config.css` carrega depois do `device.css` e é daí que lhe ganha. Sem build,
  cada ficheiro é mais um pedido, e por isso são seis e não vinte.

  A excepção é **a linguagem de selecção**, que vive toda no `shell.css`: as
  cinco caixas que se escolhem — o mosaico de tipos, o cartão de dispositivo, a
  pastilha de secção, o cartão de gateway e o do assistente — dizem o mesmo em
  repouso, por cima, no foco e escolhidas, e o ficheiro da área de cada uma leva
  só a geometria. Falharam-no uma vez, com duas a passar a borda cheia da marca
  por cima e três a subtil; o `SelectionLanguageTest` prende-o agora.

- **O grupo «Recorrência» de um alarme encolhe com a linha.** Pede 306px: onde
  eles existem — num modal de 1140, por exemplo — fica numa linha como sempre
  esteve, e onde não existem, como nos 282 de um telemóvel, são as etiquetas dos
  três botões que partem em duas. Preso a `flex-shrink-0` não cedia, e o que
  partia era o cartão. Encurtar as etiquetas mudava vocabulário e dar-lhe a linha
  toda crescia ~30px por alarme numa lista que vai até dez; a quebra dentro do
  próprio botão não custa nada e nenhum fica cortado.

  O mesmo vale para o controlo de uma definição que cabe numa linha — o campo com
  a unidade colada. O `.config-line-control` deixa-o encolher até aos 7rem que
  mostram um número de quatro dígitos: sem isso, num telemóvel, o «Intervalo de
  localização» punha o campo e o «s» para lá do bordo do cartão.
