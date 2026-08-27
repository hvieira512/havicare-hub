# A dashboard

A interface do hub: uma página só, servida em PHP, com o comportamento em módulos ES que
o browser carrega tal como estão. **Não há build step** -- nem bundler, nem transpilador,
nem `node_modules` em produção. Guardar um ficheiro e recarregar a página é o ciclo todo.

Bootstrap, Font Awesome, amCharts, SweetAlert2 e o Swagger UI estão no repositório, em
`assets/vendor/`, e são servidos por nós -- ver o `README.md` dessa pasta. O resto é nosso.

```
src/Dashboard/
├── index.php          a página
├── main.js            o <script type="module"> que a página carrega
├── main.css           todo o CSS
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
3. **Só o `app.js` conhece toda a gente.** É a raiz de composição: cacheia os elementos,
   cria os modais, entrega o `els` a cada funcionalidade e liga os ouvintes.

Cada pasta com mais do que um ficheiro repete a regra 3 à sua escala: `settings/index.js`
é o único que conhece as quatro secções do modal, e `settings/shell.js` é o que todas
partilham sem conhecer nenhuma.

## A árvore

```
dashboard/
├── app.js                  a raiz de composição
├── dom.js                  cacheElements(): os ~200 getElementById, num sítio só
│
│   ── o núcleo partilhado: o que duas ou mais funcionalidades usam ──
├── state.js                o objeto de estado, um só, com um sub-objeto por ecrã
├── domain.js               o vocabulário: tipos de dispositivo, modelos, fornecedores, licenças
├── format.js               esc(), datas, e as etiquetas por chave
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
make test-unit                 # inclui os testes que lêem estes ficheiros como texto
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
- **`devices/config/inputs.js` (1212 linhas) e `devices/detail.js` (905).** São grandes
  porque o problema é grande -- um renderer por tipo de campo, um cartão por tipo de
  telemetria. Partir por tamanho, sem uma linha que os separe de verdade, só espalha.
- **`main.css` tem 2152 linhas num ficheiro só.** Sem build, dividi-lo custa `<link>`s.
  As secções maiores estão marcadas com `/* ===== nome ===== */`.
- **Os `id` entre o PHP e o `dom.js` não são verificados**, como está acima.
