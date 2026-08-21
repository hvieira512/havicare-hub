import test from "node:test";
import assert from "node:assert/strict";
import {readFileSync} from "node:fs";

// Tem de vir antes dos modulos do dashboard: o api/http.js toca em window ao carregar.
import "./support/browser-env.js";
import {deviceTypeFields} from "../../src/Dashboard/dashboard/domain.js";

/**
 * O assistente de adicionar um dispositivo.
 *
 * O motor esta coberto no wizard.test.js. O que se prende aqui e a moldura: que existe um
 * modal proprio, separado do de edicao, e que o de edicao deixou de fazer os dois
 * trabalhos.
 */

const WIZARD = readFileSync(
    new URL("../../src/Dashboard/components/modals/device-wizard.php", import.meta.url),
    "utf8",
);
const EDIT = readFileSync(
    new URL("../../src/Dashboard/components/modals/device.php", import.meta.url),
    "utf8",
);
const MODAL_JS = readFileSync(
    new URL("../../src/Dashboard/dashboard/devices/device-modal.js", import.meta.url),
    "utf8",
);
const INDEX = readFileSync(
    new URL("../../src/Dashboard/index.php", import.meta.url),
    "utf8",
);

test("adicionar e editar sao dois modais", () => {
    assert.match(WIZARD, /render_modal\('deviceWizardModal'/);
    assert.match(EDIT, /render_modal\('deviceModal'/);
    assert.match(INDEX, /modals\/device-wizard\.php/, "o assistente esta incluido");
});

test("o modal de edicao deixou de saber criar", () => {
    // Era ele que fazia os dois trabalhos, escondendo e revelando metade da sua estrutura.
    assert.doesNotMatch(MODAL_JS, /openAddDevice/);
    assert.doesNotMatch(MODAL_JS, /mode: "create"/);
});

test("o titulo do modal de edicao e estatico", () => {
    // Antes era escrito por JavaScript porque dependia do trabalho que ia fazer.
    assert.match(EDIT, /render_modal\('deviceModal', 'Editar dispositivo'/);
    assert.doesNotMatch(MODAL_JS, /deviceModalLabel\.textContent/);
});

test("o assistente tem barra de progresso, trilha e lugar para a imagem", () => {
    assert.match(WIZARD, /id="wizardSteps"/);
    assert.match(WIZARD, /id="wizardTrail"/);
    assert.match(WIZARD, /id="wizardArt"/);
    assert.match(WIZARD, /role="progressbar"/);
});

test("o assistente comeca com o passo seguinte e sem separadores", () => {
    // Sem separador de configuracoes: um dispositivo por criar nao pode ter configuracao
    // guardada, porque a tabela tem chave estrangeira para a whitelist.
    assert.doesNotMatch(WIZARD, /nav-link|tab-pane/);
    assert.match(WIZARD, /id="wizardNextBtn"/);
    assert.match(WIZARD, /id="wizardBackBtn"/);
});

test("o corpo do assistente e quase vazio, porque e desenhado a partir da pergunta", () => {
    // Se isto crescer, e sinal de que voltaram campos estaticos para o markup e que a
    // revelacao progressiva passou a ser esconder e mostrar, como no modal antigo.
    const fields = WIZARD.match(/<input|<select/g) || [];
    assert.equal(fields.length, 0, "nenhum campo estatico no markup do assistente");
});

test("o passo 2 de cada tipo vem da tabela e nao do assistente", () => {
    const wizardJs = readFileSync(
        new URL("../../src/Dashboard/dashboard/devices/create-wizard.js", import.meta.url),
        "utf8",
    );

    // Nenhum tipo de dispositivo esta escrito em condicoes dentro do assistente.
    for (const type of ["watch", "ncs", "radar", "gateway", "diaper_sensor", "bracelet"]) {
        assert.doesNotMatch(
            wizardJs,
            new RegExp(`=== ["']${type}["']`),
            `o assistente nao pode ramificar em ${type}`,
        );
    }
    // E a tabela e que diz o que cada um mostra.
    assert.equal(deviceTypeFields("watch").sim, true);
    assert.equal(deviceTypeFields("diaper_sensor").gatewayLinks, true);
});

test("o tipo e o modelo partilham a grelha de cards", () => {
    const wizardJs = readFileSync(
        new URL("../../src/Dashboard/dashboard/devices/create-wizard.js", import.meta.url),
        "utf8",
    );

    // Uma so funcao desenha as duas grelhas. Duplica-la duplicava tambem os estados de
    // foco e de selecao, que vivem no CSS de uma classe.
    assert.match(wizardJs, /function cardGrid\(/);
    assert.match(wizardJs, /renderTypeGrid[\s\S]*?return cardGrid\(/);
    assert.match(wizardJs, /renderModelGrid[\s\S]*?return cardGrid\(/);
});

test("os cards de modelo levam fotografia, nome comercial e modelo interno", () => {
    const wizardJs = readFileSync(
        new URL("../../src/Dashboard/dashboard/devices/create-wizard.js", import.meta.url),
        "utf8",
    );

    // A fotografia vem do mesmo desenho que o modal de edicao usa; nao ha aqui um
    // segundo caminho para a imagem de um modelo.
    assert.match(wizardJs, /wizard-card-thumb[\s\S]*?modelPreviewHtml\(model/);
    // O comercial e o titulo, porque e o que se reconhece da caixa; o interno e o
    // subtitulo, porque e o que aparece depois nos topicos e na base de dados.
    assert.match(wizardJs, /label: commercial \|\| internal/);
    assert.match(wizardJs, /sub: commercial && commercial !== internal \? internal : ""/);
});

test("a grelha de cards tem uma classe so, e nao uma por tipo de escolha", () => {
    const css = readFileSync(
        new URL("../../src/Dashboard/main.css", import.meta.url),
        "utf8",
    );

    assert.match(css, /\.wizard-card-grid \{/);
    assert.match(css, /\.wizard-card \{/);
    assert.doesNotMatch(css, /wizard-type-card/, "a classe do tipo foi substituida pela partilhada");
    assert.match(css, /prefers-reduced-motion[\s\S]*?\.wizard-card \{ transition: none/);
});

test("o Anterior esconde-se no primeiro passo em vez de ficar cinzento", () => {
    const wizardJs = readFileSync(
        new URL("../../src/Dashboard/dashboard/devices/create-wizard.js", import.meta.url),
        "utf8",
    );

    // Um botao desactivado que nunca serve ocupa espaco e convida a ser premido. Voltar a
    // uma resposta ja dada faz-se pelo "alterar" na trilha.
    assert.match(wizardJs, /wizardBackBtn\.classList\.toggle\("d-none", !wizard\.canGoBack\(\)\)/);
    assert.doesNotMatch(wizardJs, /wizardBackBtn\.disabled/);
});
