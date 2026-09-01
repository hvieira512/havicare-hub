# A dashboard

A interface do hub: uma página só, servida em PHP, com o comportamento em módulos ES que
o browser carrega tal como estão. **Não há build step** -- nem bundler, nem transpilador,
nem `node_modules` em produção. Guardar um ficheiro e recarregar a página é o ciclo todo.

Bootstrap, Font Awesome, SweetAlert2 e o Swagger UI estão no repositório, em
`assets/vendor/`, e são servidos por nós -- ver o `README.md` dessa pasta. O resto é nosso.

```
src/Dashboard/
├── index.php          a página
├── main.js            o <script type="module"> que a página carrega
├── main.css           os modais: dispositivo, definições, assistente
├── assets/css/        o resto do CSS, por área: base, shell, device, login
├── components/        os partials PHP que produzem a marcação
├── assets/            o logo, as fontes e as bibliotecas de terceiros
├── dashboard/         todo o JavaScript  ← o resto deste documento
└── *.php              o servidor HTTP da dashboard e os seus stores
```

## As três regras

São só estas, e explicam onde cada ficheiro está:

1. **Uma funcionalidade nunca importa outra funcionalidade.** Pode importar `api/` e a
   raiz de `dashboard/`. Se `devices/` e `settings/` precisam de se falar, é o `app.js`
   que os apresenta.
2. **Um módulo que só uma funcionalidade usa vive dentro dela.** Sobe à raiz de
   `dashboard/` quando aparecer o segundo consumidor -- e não antes.
3. **Só a raiz de composição conhece toda a gente.** É o `app.js` mais o `wiring/`:
   cacheia os elementos, cria os modais, entrega o `els` a cada funcionalidade e liga os
   ouvintes. O `wiring/` existe porque o `app.js` tinha 567 linhas e quase noventa
   ouvintes; dividi-lo por área moveu linhas, não acoplamento. Os módulos do `wiring/`
   são raiz de composição como o `app.js` e podem importar de onde precisarem -- é
   precisamente por isso que os ouvintes vivem lá e não dentro das funcionalidades, já
   que quase todos atravessam duas ou três e a regra 1 proíbe-os de se conhecerem.

4. **Um widget novo nasce no seu próprio módulo, ao lado da funcionalidade que o usa.**
   Não se acrescenta ao `inputs.js` nem ao `telemetry-cards.js`. O `devices/device-card.js`
   é o exemplo a seguir.

Cada pasta com mais do que um ficheiro repete a regra 3 à sua escala: `settings/index.js`
é o único que conhece as quatro secções do modal, e `settings/shell.js` é o que todas
partilham sem conhecer nenhuma.

### Porque é que a regra 4 existe

Um cartão de dispositivo estava espalhado por quatro sítios: a marcação numa função do
`list.js`, o estilo no `main.css` global, o ouvinte no `wiring/`, e os dados no `state`.
Mudar um widget obrigava a abrir quatro ficheiros, e nada dizia que os quatro pedaços
eram a mesma coisa.

É também a razão de os ficheiros grandes serem grandes. O `inputs.js` com mil e
trezentas linhas e o `telemetry-cards.js` com mil e cento e trinta não estão mal
escritos: são o sítio onde tudo o que é *do mesmo género* se acumulou, porque não havia
sítio nenhum para o que é *da mesma funcionalidade*. Organizar por camada em vez de por
funcionalidade dá exactamente isto.

O `devices/device-card.js` é o primeiro feito assim, e mostra até onde a regra vai. Juntou
o que se podia juntar sem pagar por isso: a marcação e o esqueleto ficam no mesmo módulo,
porque têm de mudar juntos -- o esqueleto é o cartão com as mesmas classes e barras no lugar
do texto, e é isso que impede a lista de saltar --, e o nome do `data-action` passa a ser
exportado, para o ouvinte delegado do `wiring/` não repetir a string que a marcação escreve.

**O CSS não vem.** Fica no bloco `.device-card*` do `assets/css/device.css`, marcado com um
comentário que aponta para o módulo. Sem passo de compilação, um ficheiro de estilo por
widget é um pedido HTTP por widget, e foi por isso que a divisão do CSS parou em cinco
ficheiros por área. Co-locar o estilo custava mais do que resolvia.

E o ouvinte também não vem: continua delegado na raiz da lista, que é o padrão da casa --
as opções são redesenhadas a cada resposta, e um ouvinte por cartão obrigava a religá-los
todos de cada vez.

A regra não pede uma migração. Pede que o próximo widget nasça no sítio certo, e que os
ficheiros grandes encolham por atrito, à medida que se passa por eles.

O `devices/event-summary-cards.js` é o segundo, e mostra como é o atrito na prática. A
última chamada de ajuda e a última queda estavam no `telemetry-cards.js` só por serem
cartões: o resto daquele ficheiro é um cartão por *tipo de telemetria*, com uma tabela a
mapear tipo em ícone e corpo, enquanto estes dois lêem o histórico inteiro e resumem-no.
Ao saírem, o que era partilhado subiu em vez de vir atrás -- o `displayPersonIndex` para o
`format.js`, as tabelas de nomes para o `domain.js` --, que é a regra 2 a funcionar. O
`telemetry-cards.js` ficou com menos 170 linhas.

## A árvore

```
dashboard/
├── app.js                  a raiz de composição
├── wiring/                 os ouvintes, por área -- raiz de composição, como o app.js
│   ├── devices.js          lista, filtros, modal, painel de configuração, detalhe
│   └── settings.js         modelos, capacidades, utilizadores da API, empresas
├── dom.js                  cacheElements(): os ~200 getElementById, num sítio só
│
│   ── o núcleo partilhado: o que duas ou mais funcionalidades usam ──
├── state.js                o objeto de estado, um só, com um sub-objeto por ecrã
├── domain.js               o vocabulário: tipos de dispositivo, modelos, fornecedores, licenças
├── format.js               esc(), datas, e as etiquetas por chave
├── html.js                 html`` e raw(): a marcação escapa por omissão
├── widgets.js              os pedaços de HTML que mais do que um ecrã desenha
├── telemetry-cards.js      o catálogo dos cartões de telemetria: ícone, cor e corpo por tipo
├── pagination.js           o paginador das listagens
├── phone.js                o campo de telefone com indicativo
├── storage.js              as chaves e os acessos ao localStorage
├── tooltips.js             re-atar os tooltips do Bootstrap depois de um render
├── notifications.js        o sino da barra (funcionalidade de um ficheiro)
│
├── api/                    um ficheiro por recurso; o único sítio com fetch
│   ├── http.js             requestJson(), formRequest(), withQuery() e o token
│   ├── auth.js             o bilhete de vida curta que abre o stream
│   ├── index.js            o barril que o resto importa
│   └── devices.js  models.js  licenses.js  companies.js  users.js  …
│
├── auth/session.js         login, refresh do token, e o ecrã de entrada
│
├── devices/                o ecrã principal
│   ├── list.js             a coluna da esquerda: lista, busca, paginação, modal de escolha
│   ├── detail.js           a coluna da direita: eventos, pedidos, cartões, filtros do painel
│   ├── filters.js          os filtros da lista e os paginadores dos dois painéis
│   ├── device-modal.js     o modal de um dispositivo
│   ├── create-wizard.js    o assistente de adicionar: perguntas, grelhas, e a criação
│   ├── edit-wizard.js      a mesma classificação, no modal de editar
│   ├── wizard.js           o motor das perguntas (não é uma biblioteca: ~110 linhas)
│   ├── classification-ui.js  as grelhas de tipo/fornecedor/modelo/licença, partilhadas
│   ├── gateway-links.js    que gateways são elegíveis (lógica pura)
│   ├── gateway-links-ui.js os cards de gateway no modal
│   ├── gateway-signal.js   o RSSI de cada par (dispositivo, gateway)
│   ├── device-card.js      o cartão de um dispositivo, e o seu esqueleto
│   ├── event-summary-cards.js  a última chamada de ajuda e a última queda
│   ├── stream.js           o EventSource que traz os eventos em directo
│   ├── config/             o separador de configurações de um dispositivo
│   │   ├── index.js        desenha a raiz e as secções
│   │   ├── panel.js        gravar, refrescar, e o estado de cada bloco
│   │   ├── handlers.js     os eventos delegados na raiz do painel
│   │   ├── inputs.js       um renderer por tipo de campo
│   │   ├── readers.js      o inverso: lê o payload de volta do DOM
│   │   ├── normalizers.js  as formas que os protocolos usam
│   │   ├── row-editing.js  adicionar e remover linhas (contactos, alarmes, planos)
│   │   ├── protocol-catalog.js  o que cada protocolo aceita
│   │   ├── alarm-fields.js      horas e recorrências dos alarmes
│       ├── four-p-touch-take-pills.js  o lembrete de comprimidos da 4P Touch
│       └── take-pills-audio.js         a gravação de voz desse lembrete
│
└── settings/               o modal de definições
    ├── index.js            a raiz de composição do modal: conhece as quatro secções
    ├── shell.js            o que as secções partilham: menu, contagens, paginação, separadores
    ├── clicks.js           os cliques delegados de todas as secções
    ├── capabilities.js     o separador Capacidades: o catálogo por tipo de dispositivo
    ├── companies.js        o separador Licenças: empresas com as suas licenças dentro
    ├── api-users.js        o separador Utilizadores API
    └── models/             o separador Catálogo, que são três slides de um carrossel
        ├── shell.js        o contexto e o carrossel
        ├── list.js         slide 1: a árvore tipo → fornecedor → modelo
        ├── form.js         slide 2: criar um modelo novo
        └── detail.js       slide 3: a ficha de um modelo e as suas capacidades
```

## Como um ecrã está montado

Todos seguem a mesma forma, e vale a pena reconhecê-la antes de abrir qualquer ficheiro.

**O `els`.** `dom.js` corre uma vez e devolve um objeto com todos os elementos da página.
O `app.js` entrega-o a cada módulo através de uma função `initX(context)`, que o guarda
num `let els` do próprio módulo. Nenhum módulo faz `getElementById` por sua conta.

```js
let els;
export function initGatewayLinksUi(context) {
    els = context.els;
}
```

Um `id` que não exista dá `undefined`, e não um erro: renomeá-lo num template deixava o
ouvinte por ligar e o botão a não fazer nada, em silêncio. O acoplamento entre os `id` do
PHP e o JavaScript é o preço de não haver build, e não vai deixar de existir -- mas está
verificado. O `tests/Unit/Dashboard/DashboardElementIdsTest.php` desenha a página a sério,
com os modais e os auxiliares já corridos, e exige que cada `els.x` que o JavaScript lê
exista mesmo.

**O estado.** Tudo o que sobrevive a um render está em `state.js`, com um sub-objeto por
ecrã (`state.settingsModal`, `state.deviceModal`, `state.summary`). Não há estado
duplicado em variáveis de módulo -- essas guardam só o `els` e coisas que não são dados.

**Os eventos.** São delegados na raiz de cada zona e resolvidos por `data-action`:

```html
<button data-action="selectCapabilitySupplier" data-value="3">Wonlex</button>
```
```js
const button = event.target.closest('[data-action="selectCapabilitySupplier"]');
if (button) selectCapabilitySupplier(button.dataset.value);
```

Isto existe porque as listas são redesenhadas por `innerHTML` a cada resposta: atar um
ouvinte a cada botão obrigava a religá-los todos de cada vez.

**Os dados.** Só `api/` chama `fetch`. Toda a resposta tem a forma `{data, pagination}` ou
`{error: {code, message}}`, e quem chama verifica `response.error` -- não há `throw` a
atravessar camadas.

## Do PHP até ao JS

Um `id` é o contrato entre os dois lados, e é atravessado à mão:

```
components/modals/settings.php   <div id="capabilitySupplierButtons">
        ↓
dashboard/dom.js                 capabilitySupplierButtons: document.getElementById(…)
        ↓
dashboard/settings/capabilities.js   els.capabilitySupplierButtons.innerHTML = …
```

Metade dos `id` nasce em argumentos de helpers PHP (`search_input('capabilityCatalogSearch', …)`),
por isso um grep pelo `id="..."` não os encontra todos -- procura pelo nome sozinho.
**Nada verifica que os dois lados concordam:** uma gralha dá `null` silencioso no `els`.
Ao mexer num `id`, mexe-se nos três sítios.

## Configurações que não viajam

Uma configuração é normalmente um downlink à espera de acontecer: vira comandos nativos,
sai para o dispositivo, e o bloco mostra o estado da entrega. Nem todas -- a sensibilidade
dos alertas de um medidor de fraldas não tem para onde ir, porque o sensor é um beacon BLE
que só transmite, e o que ela muda é a regra com que o hub deriva o estado da fralda.

**O frontend não sabe disto, e é de propósito.** Quem decide é a capacidade em PHP, marcada
com `HubAppliedCapability`: o `DeviceConfigurationUpdateService` guarda o valor e dá-o por
aplicado sem comandos, o bloco recebe `command: ""` no catálogo, e o painel lê disso as duas
únicas diferenças que se vêem -- o botão diz "Guardar" em vez de "Enviar", e não se mostra
vocabulário de protocolo que não existe.

Isto já teve um caminho paralelo no frontend: uma pasta `hub-rules/`, estado próprio,
handlers próprios, e um estado de UI a dizer "Aplicada no hub" para exprimir o que a via
genérica já dizia com "Aplicado". Se aparecer a tentação de repetir esse padrão para uma
regra nova, a resposta é uma capacidade nova em PHP.

## Onde ponho isto?

| o que estou a escrever | onde vai |
|---|---|
| uma chamada nova à API | `api/<recurso>.js`, exportada em `api/index.js` |
| uma pergunta sobre tipos de dispositivo, modelos ou licenças | `domain.js` |
| HTML que dois ecrãs desenham | `widgets.js` |
| HTML que um ecrã desenha | o ficheiro desse ecrã |
| um handler de clique | ao lado do módulo que desenha o que ele trata |
| um campo novo de configuração | `devices/config/inputs.js` + `readers.js` |
| uma configuração que o hub aplica sem downlink | nada de especial no frontend: é a capacidade em PHP que se marca com `HubAppliedCapability` |
| um ecrã novo nas definições | `settings/<nome>.js`, ligado no `settings/index.js` |
| estado que sobrevive a um render | `state.js`, no sub-objeto do ecrã |

## A rede de segurança

```bash
npx eslint src/Dashboard/dashboard tests/Frontend --max-warnings 0
npm test                       # 198 testes em tests/Frontend/
composer test:unit             # inclui os testes que lêem estes ficheiros como texto
```

Dois valem por si:

- **`tests/Frontend/module-graph.test.js`** importa o `main.js` e falha se algum import
  não resolver. Um nome importado que ninguém exporta deita a dashboard abaixo com uma
  página em branco, e nenhum `node --check` o apanha. O mesmo teste falha se um módulo
  ficar órfão, isto é, inalcançável a partir do `main.js`.
- **O `no-unused-vars` do eslint** é aviso e não erro, mas corre com `--max-warnings 0`:
  é o que apanha um import que ficou para trás depois de mover código.

Vários testes em `tests/Unit/Dashboard/` lêem estes ficheiros **como texto** e afirmam que
certas linhas lá estão. Mover uma função entre ficheiros parte-os -- é de propósito, e a
correcção é apontar o teste ao ficheiro novo.

## O que ainda não está direito

- **`settings/models/list.js` e `form.js` importam-se um ao outro.** A lista repõe o
  formulário do outro slide, o formulário volta à lista depois de gravar. Resolvem-se em
  tempo de chamada e não quebram nada; parti-los obrigava a um registo de callbacks que
  custa mais do que resolve. É o único ciclo do grafo.
- **`devices/config/inputs.js` e `telemetry-cards.js` passam das mil linhas.** Partir por
  tamanho, sem uma linha que os separe de verdade, só espalha. A linha que os separa é a
  regra 4: são o sítio onde tudo o que é do mesmo *género* se acumulou, e encolhem por
  atrito à medida que cada widget novo nasce junto do seu CSS e do seu ouvinte.
- **O CSS está dividido por área**, em cinco ficheiros: `assets/css/base.css` (tokens e
  fontes), `shell.css` (moldura, navbar, cartões), `device.css` (o ecrã do dispositivo),
  `login.css`, e o `main.css` fica com os modais. A ordem no `<head>` é essa, e é a
  cascata original: cada ficheiro é uma fatia contígua do que era um só. O `main.css`
  ficou em último e na raiz porque `/main.css` é uma rota fixa no
  `DashboardHttpServer::publicAssetPath()` -- os ficheiros da raiz não são apanhados por
  padrão, só o `/main.css` e o `/main.js`. Sem build, cada ficheiro é mais um pedido, e
  por isso são cinco e não vinte.
