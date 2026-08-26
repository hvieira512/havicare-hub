# Separar adicionar de editar, e nomear a terceira caixa

**Estado:** implementado.

## Contexto

Um só `#deviceModal`, em ecrã inteiro, faz dois trabalhos. O `openAddDevice()` esconde
o separador das configurações, esconde o botão de eliminar, força o separador "Geral" e
limpa a identidade; o `editDevice()` faz o inverso. São 769 linhas num módulo, e o
utilizador vê o mesmo formulário a mudar de forma debaixo dos pés — todos os campos de
uma vez, uns relevantes e outros não, dependendo de escolhas que ainda não fez.

A sensibilidade do medidor de fraldas expôs o problema de fundo. A dashboard tem duas
caixas para arrumar coisas de um dispositivo: os **metadados** (quem é, de quem é, como
se identifica) e a **configuração por downlink** (um comando enviado ao dispositivo, com
estado de entrega e confirmação). Uma regra decidida no hub não é nenhuma das duas, e foi
por isso que andou de um separador para o outro antes de assentar: não havia sítio, havia
dois sítios errados.

Três mudanças, e a terceira é a que evita a próxima confusão:

1. Adicionar deixa de partilhar o modal com editar, e passa a ser um assistente por
   passos.
2. As diferenças por tipo de dispositivo passam a ser uma tabela declarativa, em vez de
   predicados espalhados e cadeias de `if`.
3. As regras do hub ganham nome e lugar próprios, ao lado dos metadados e da configuração
   por downlink.

Nada disto toca no painel de configurações por downlink. É a parte maior e mais exercida,
e não é a que dói.

---

## 1. O assistente de adicionar

### Dois passos, e não quatro

A proposta inicial tinha quatro: tipo, fornecedor+modelo, empresa+licença, e o resto.

Tipo, fornecedor e modelo são a mesma pergunta com precisão crescente, e o tipo **filtra**
os fornecedores, que **filtram** os modelos — o `suppliersForDeviceType()` e o
`modelsForSupplierAndType()` já fazem isso. Separados em dois ecrãs, o utilizador escolhe
um tipo, avança, e encontra uma lista encolhida sem perceber que foi a escolha dele que a
encolheu. Juntos, vê-se o funil.

A empresa e a licença também não sustentam um ecrã: são dois campos, o segundo depende do
primeiro, e em muitas empresas há uma licença só. Mas juntá-los mecanicamente ao último
passo dava-lhe quatro blocos — empresa, licença, identidade e gateways — que é
exactamente a densidade de que esta mudança foge.

O corte que equilibra é outro, e não é pelo número de campos:

| Passo | Pergunta | Conteúdo | Natureza |
|---|---|---|---|
| 1 | Que espécie de coisa é, e de quem é? | Tipo → Fornecedor → Modelo → Empresa → Licença | tudo escolher de listas |
| 2 | Qual é esta unidade em concreto? | Identidade conforme o tipo, SIM se for relógio, gateways autorizados se se aplicarem | escrever à mão e confirmar |

Cinco controlos no primeiro passo parecem muitos e não são, porque **não estão no ecrã ao
mesmo tempo**. O passo 1 tem três perguntas em sequência, e cada resposta colapsa numa
badge: escolhe-se o tipo numa grelha de seis cards e a grelha desvanece, ficando a badge;
escolhe-se o fornecedor e o modelo — o modelo noutra grelha de cards, com a fotografia, o
nome comercial em título e o modelo interno em subtítulo — e voltam a colapsar. O ecrã
nunca acumula: três perguntas passam por ali e há no máximo dois controlos à vista.

A imagem do modelo entra à direita quando ele já é conhecido, e fica como âncora nas
perguntas seguintes. Enquanto se escolhe o modelo não aparece, porque as fotografias estão
na grelha.

**Sem passo de confirmação.** O segundo mostra um resumo compacto do que foi escolhido no
primeiro e o botão diz "Criar dispositivo". Quem instala vinte sensores seguidos agradece
os cliques que não deu, e quem escreveu um MAC à mão vê-o ao lado do resto antes de
confirmar.

### Regras do assistente

- **Não avança sem o passo estar completo.** O botão "Seguinte" fica inactivo, em vez de
  avançar e mostrar um erro.
- **Voltar atrás preserva o que já foi escrito**, excepto o que a alteração invalida:
  mudar o tipo limpa fornecedor e modelo, porque podem não existir para o tipo novo, e
  mudar a empresa limpa a licença. São as duas únicas invalidações em cascata, e são
  explícitas.
- **A barra de progresso mostra os dois passos com nome**, não só uma percentagem. Quem
  está no primeiro tem de conseguir ver que falta a identidade.
- **O "Anterior" esconde-se no primeiro passo** em vez de ficar cinzento: um botão que
  nunca serve ocupa espaço e convida a ser premido. Voltar a uma resposta já dada faz-se
  pelo "alterar" na trilha, que é onde ela está.
- **O passo muda ao avançar, e não ao responder.** Foi um defeito da primeira versão do
  motor, apanhado por um teste: derivava o passo da pergunta activa, e a barra saltava
  para o passo 2 no instante em que a última resposta do 1 entrava.
- **Sem separador de configurações.** Um dispositivo por criar não pode ter configuração
  guardada — a tabela `diaper_sensor_settings` tem chave estrangeira para a `whitelist` —
  e o assistente não finge que pode. O que ele faz é acabar com uma ligação directa para
  as regras do dispositivo recém-criado, que é para onde a pessoa quer ir a seguir.

Isto resolve por desenho a aresta que existe hoje: no modal actual, escolher "Medidor de
fraldas" ao criar não dá forma de definir a sensibilidade, e nada o diz.

---

## 2. A tabela de tipos de dispositivo

### O problema

As diferenças por tipo estão em três formas diferentes ao mesmo tempo. Predicados no
`domain.js` (`linksToGateway()`, `usesMacAddress()`), cadeias de `if` no modal
(`deviceType === "ncs"` para trocar o rótulo e a ajuda do Device ID, `=== "radar"` para
outro rótulo, `=== "watch"` em dois sítios para o IMEI e o SIM), e visibilidade de linhas
espalhada por `classList.toggle("d-none", ...)`. Acrescentar um tipo de dispositivo
obriga a encontrar os três.

### A forma

Uma tabela declarativa, no `domain.js`, que é quem já tem o `deviceTypeOptions` e os
predicados. Uma linha por tipo:

```js
export const DEVICE_TYPES = {
    watch: {
        label: "Relógio",
        identity: {field: "imei", label: "IMEI", help: "...", placeholder: "..."},
        sim: true,
        gatewayLinks: false,
        hubSettings: [],
    },
    diaper_sensor: {
        label: "Medidor de fraldas",
        identity: {field: "deviceId", label: "MAC", help: "Endereço MAC canónico..."},
        sim: false,
        gatewayLinks: true,
        hubSettings: ["diaper_sensitivity"],
    },
    // ...
};
```

Os predicados existentes passam a ler a tabela em vez de terem a lista própria, e mantêm
a assinatura — `linksToGateway(type)` continua a funcionar e passa a ser uma linha. Isso é
o que permite fazer a mudança sem tocar em todos os chamadores de uma vez.

O assistente e o modal de edição lêem a mesma tabela. O passo 2 do assistente renderiza
`identity`, o SIM se `sim`, e o selector de gateways se `gatewayLinks`. O separador das regras do hub
existe se `hubSettings` não estiver vazio.

### A dívida que isto deixa por pagar

Esta tabela **duplica** o que o hub já sabe. O backend tem a família de definições por
tipo (`BraceletCapabilityDefinitions`, `DiaperSensorCapabilityDefinitions`, e as outras) e
já serve capacidades por dispositivo. Ter a mesma verdade em PHP e em JavaScript é uma
fonte de deriva, e a saída certa a prazo é a API servir o descritor, como já serve as
capacidades.

Não se faz agora porque não é isso que dói hoje, e porque consolidar primeiro em um só
sítio no frontend é o passo que torna essa migração trivial depois: passa a haver uma
tabela para substituir por uma chamada, em vez de dez ramificações para caçar. Fica
marcado com um comentário `ponytail:` na tabela, a dizer isto.

---

## 3. As regras do hub, sem separador próprio

### As três coisas que um dispositivo tem

| | O que é | Onde vive | Como se grava | Tem estado de entrega |
|---|---|---|---|---|
| **Metadados** | identidade, empresa, licença, gateways | `whitelist`, `gateway_device_links` | com o dispositivo | não |
| **Configuração por downlink** | um comando para o dispositivo | `device_configurations` | por configuração, e espera confirmação | **sim** |
| **Regra do hub** | política que muda como o hub deriva dados | tabela própria por regra | por regra, de imediato | não |

### E ficam todas no mesmo separador

A primeira versão desta especificação dava às regras do hub um separador próprio,
"Regras do hub", ao lado de "Configurações". **Estava errado**, e a revisão apanhou-o:
isso nomeia a *implementação*. Ambas são configuração, e obrigar a pessoa a saber se uma
alteração viaja para o dispositivo — antes de saber onde clicar para a fazer — é pedir-lhe
que conheça a arquitectura.

O que difere é uma propriedade **de cada regra**, e é lá que aparece:

| | Estado | Botão | Rodapé |
|---|---|---|---|
| viaja para o dispositivo | `Confirmado` / `À espera` | Enviar | "Enviado ao relógio às 14:32" |
| decidida no hub | `Aplicada no hub` | Guardar | "Guardado" |

Isto retira dívida em vez de a criar. O `quietWhenEmpty` que tinha sido acrescentado ao
`renderDeviceConfigurationRoot` para calar a mensagem "este protocolo não tem
configurações suportadas" continua a existir, mas passou a ter o significado certo: o
painel só se declara vazio quando não há downlinks **nem** regras do hub. Antes calava-se
porque havia uma regra encostada por cima dele, o que era um remendo.

### No código

```
src/Dashboard/dashboard/devices/hub-rules/
    index.js                registo: lê `hubRules` do tipo e monta os blocos
    diaper-sensitivity.js   a primeira regra
```

Cada regra exporta a mesma forma pequena — `load`, `render`, `read`, `validate`, `save`,
`resetProfile` — e o `index.js` não sabe nada de fraldas. Os blocos são desenhados dentro
do `deviceConfigRoot`, antes dos downlinks, porque valem de imediato e não há nada a
aguardar entrega.

Os limiares da deteção de queda, quando chegarem, são um segundo ficheiro nesta pasta e
uma entrada no `hubRules` da pulseira.

### No backend

Continua sem generalização. Uma regra não justifica um registo genérico, e adivinhar a
forma da segunda antes de ela existir é como se acerta ao lado. O padrão a seguir está
estabelecido — interface em `src/Domain/`, repositório com cache TTL, tabela própria — e é
com a segunda regra que se decide se vale uma rota genérica.

---

## 4. Ficheiros

**Novos**

```
src/Dashboard/components/modals/device-wizard.php    a moldura do assistente
src/Dashboard/dashboard/devices/wizard.js            o motor, ~110 linhas
src/Dashboard/dashboard/devices/create-wizard.js     as perguntas e as grelhas
src/Dashboard/dashboard/devices/hub-rules/index.js
src/Dashboard/dashboard/devices/hub-rules/diaper-sensitivity.js
tests/Frontend/device-type-fields.test.js            a rede de segurança
tests/Frontend/wizard.test.js
tests/Frontend/create-wizard.test.js
tests/Frontend/hub-rules.test.js
```

**Alterados**

```
src/Dashboard/dashboard/domain.js                    a tabela, e os predicados a lê-la
src/Dashboard/dashboard/devices/device-modal.js      769 -> 658 linhas, sem criação
src/Dashboard/components/modals/device.php           título estático, sem a sensibilidade
src/Dashboard/dashboard/devices/config/panel.js      desenha as regras do hub
src/Dashboard/dashboard/devices/gateway-links-ui.js  exporta o card do gateway
src/Dashboard/dashboard/devices/list.js              a coluna da lista
src/Dashboard/dashboard/app.js                       liga o assistente e as regras
src/Dashboard/dashboard/dom.js                       elementos do assistente
src/Dashboard/main.css                               grelha de cards, trilha, animações
src/Dashboard/index.php                              inclui o assistente
tests/Unit/Dashboard/NotificationSourceTest.php      o mesmo comportamento, nomes novos
tests/Unit/Dashboard/DeviceModalTabStateSourceTest.php
```

**Removidos**

```
src/Dashboard/dashboard/devices/diaper-sensitivity-ui.js   passou a hub-rules/
```

O motor do assistente não é uma biblioteca: sabe qual é a pergunta activa, se pode
avançar, e que respostas já há. As perguntas são um array de
`{key, step, clears, isAnswered, badges}`. Nada de máquinas de estado nem dependências
novas.

A grelha de cards é uma função só, partilhada pelo tipo e pelo modelo. Duplicá-la
duplicava também os estados de foco e de selecção, que vivem no CSS de uma classe.

---

## 5. O que os testes apanharam

Vale registar, porque a rede de segurança foi escrita de propósito antes de mexer:

1. **O motor derivava o passo da pergunta activa.** A barra de progresso saltava para o
   passo 2 no instante em que a última resposta do passo 1 entrava, antes de a pessoa
   premir "Seguinte". O passo passou a ser estado de navegação.
2. **O import do `deviceTypeFields` foi para o bloco errado.** O teste do grafo de módulos
   apanhou-o: o `linksToGateway` vem reexportado pelo `list-detail.js`, não directamente
   do `domain.js`.
3. **Dois testes PHP fixavam o texto da implementação antiga** — nomes de funções, não
   comportamento. Foram reescritos para afirmar o que sobrevive: que uma notificação de
   dispositivo não autorizado abre o assistente já com o que o hub reportou, e que o
   assistente não tem separadores para deixar no estado errado.

O teste de caracterização dos campos por tipo (`device-type-fields.test.js`) passou sem
uma alteração depois de a tabela existir, que é a prova de que ela descreve o
comportamento que estava em produção e não um novo.

---

## 6. Verificação

1. `npm test`, `npx eslint src/Dashboard/dashboard`, `composer analyse`, `vendor/bin/phpunit`.
2. O `device-type-fields.test.js` verde sem alterações — a tabela descreve o que já havia.
3. Criar um dispositivo de cada um dos seis tipos pelo assistente, no Docker local, e
   confirmar na base de dados que a linha da `whitelist` fica igual à que o modal antigo
   produzia: mesmo `device_type`, mesma identidade no campo certo, mesmos links.
4. Num medidor de fraldas, confirmar que a sensibilidade aparece como bloco no separador
   das configurações, que grava, e que num relógio esse separador continua a mostrar os
   downlinks exactamente como antes.
5. Reproduzir as tramas reais capturadas do MECS contra a ingestão, para confirmar que
   nada do lado dos dados mudou — esta alteração é toda de dashboard.
