import test from "node:test";
import assert from "node:assert/strict";
import {readFileSync} from "node:fs";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import {deviceTypeFields} from "../../src/Dashboard/dashboard/domain.js";

/**
 * O assistente de adicionar um dispositivo. O motor está coberto no `wizard.test.js`; o que
 * se prende aqui é a moldura, e que o modal é próprio e separado do de edição.
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

test("adicionar e editar são dois modais", () => {
    assert.match(WIZARD, /render_modal\('deviceWizardModal'/);
    assert.match(EDIT, /render_modal\('deviceModal'/);
    assert.match(INDEX, /modals\/device-wizard\.php/, "o assistente esta incluido");
});

test("o modal de edicao deixou de saber criar", () => {
    // O modal de edição não faz os dois trabalhos: são dois modais.
    assert.doesNotMatch(MODAL_JS, /openAddDevice/);
    assert.doesNotMatch(MODAL_JS, /mode: "create"/);
});

test("o titulo do modal de edicao e estatico", () => {
    // Fixo no markup: não depende do trabalho que o modal vai fazer.
    assert.match(EDIT, /render_modal\('deviceModal', 'Editar dispositivo'/);
    assert.doesNotMatch(MODAL_JS, /deviceModalLabel\.textContent/);
});

test("a trilha é a barra de progresso, e tem lugar para a imagem", () => {
    // A trilha faz as duas funções numa linha só: as respostas e o progresso.
    assert.match(WIZARD, /id="wizardTrail"[^>]*role="progressbar"/);
    assert.doesNotMatch(WIZARD, /id="wizardSteps"/);
    assert.match(WIZARD, /id="wizardArt"/);
});

test("cada badge da trilha volta à sua pergunta, e não só à última", () => {
    const wizardJs = readFileSync(
        new URL("../../src/Dashboard/dashboard/devices/create-wizard.js", import.meta.url),
        "utf8",
    );

    // Cada badge volta à sua pergunta, sem obrigar a refazer as seguintes. O desenho da
    // trilha está no `classification-ui.test.js`; aqui prende-se o que o clique faz.
    assert.match(wizardJs, /wizard\.reopen\(badge\.dataset\.wizardReopen\)/);
    assert.doesNotMatch(wizardJs, /data-wizard-reopen="last"/);
});

test("o assistente comeca com o passo seguinte e sem separadores", () => {
    // Sem separador de configurações: um dispositivo por criar não pode ter configuração
    // guardada, porque a tabela tem chave estrangeira para a whitelist.
    assert.doesNotMatch(WIZARD, /nav-link|tab-pane/);
    assert.match(WIZARD, /id="wizardNextBtn"/);
    assert.match(WIZARD, /id="wizardBackBtn"/);
});

test("o corpo do assistente e quase vazio, porque e desenhado a partir da pergunta", () => {
    // Se isto crescer, é sinal de que voltaram campos estáticos para o markup e que a
    // revelação progressiva passou a ser esconder e mostrar.
    const fields = WIZARD.match(/<input|<select/g) || [];
    assert.equal(fields.length, 0, "nenhum campo estatico no markup do assistente");
});

test("o passo 2 de cada tipo vem da tabela e não do assistente", () => {
    const wizardJs = readFileSync(
        new URL("../../src/Dashboard/dashboard/devices/create-wizard.js", import.meta.url),
        "utf8",
    );

    // Nenhum tipo de dispositivo está escrito em condições dentro do assistente.
    for (const type of ["watch", "ncs", "radar", "gateway", "diaper_sensor", "bracelet"]) {
        assert.doesNotMatch(
            wizardJs,
            new RegExp(`=== ["']${type}["']`),
            `o assistente nao pode ramificar em ${type}`,
        );
    }
    // É a tabela que diz o que cada um mostra.
    assert.equal(deviceTypeFields("watch").sim, true);
    assert.equal(deviceTypeFields("diaper_sensor").gatewayLinks, true);
});

test("a grelha de cards tem uma classe só, e não uma por tipo de escolha", () => {
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

    // Um botão desactivado que nunca serve convida a ser premido. Voltar a uma resposta já
    // dada faz-se pela trilha.
    assert.match(wizardJs, /wizardBackBtn\.classList\.toggle\("d-none", !wizard\.canGoBack\(\)\)/);
    assert.doesNotMatch(wizardJs, /wizardBackBtn\.disabled/);
});

test("a licença não trava o passo: um dispositivo pode não ter nenhuma", () => {
    const wizardJs = readFileSync(
        new URL("../../src/Dashboard/dashboard/devices/create-wizard.js", import.meta.url),
        "utf8",
    );

    assert.match(wizardJs, /key: "owner"[\s\S]*?optional: true/);
});
