import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initSettingsModels } = await import(
    "../../src/Dashboard/dashboard/settings/models/shell.js",
);
const {
    handleModelWizardClick,
    handleModelWizardInput,
    modelWizardBack,
    openNewModelForm,
    saveModel,
} = await import("../../src/Dashboard/dashboard/settings/models/form.js");

/**
 * O modelo novo é o assistente dos dispositivos com três passos -- tipo, fornecedor,
 * informações -- e não um formulário com tudo à vista ao mesmo tempo.
 */

const FILTERS = [
    {
        deviceType: "watch",
        suppliers: [
            { id: 3, name: "4P Touch" },
            { id: 5, name: "Wonlex" },
        ],
    },
    { deviceType: "radar", suppliers: [{ id: 7, name: "Qinglanst" }] },
];

const TEMPLATE = {
    supplier: "4P Touch",
    deviceType: "watch",
    enabledCapabilities: ["heart_rate", "battery", "sos"],
};

let els;
let sent;

/** Os elementos que o assistente escreve, com botão onde o `disabled` tem de contar. */
function element(name) {
    return document.createElement(name.endsWith("Btn") ? "button" : "div");
}

function openWizard() {
    const cache = {};
    els = new Proxy(cache, {
        get(target, name) {
            if (typeof name !== "string") return undefined;
            if (!(name in target)) target[name] = element(name);
            return target[name];
        },
    });
    initSettingsModels({ els, ui: {} });
    return openNewModelForm();
}

const clickIn = (root, selector) => {
    const target = root.querySelector(selector);
    assert.ok(target, `não há ${selector} no passo à vista`);
    handleModelWizardClick({ target });
};

const typeIn = (root, fieldName, value) => {
    const input = root.querySelector(`[data-model-field="${fieldName}"]`);
    assert.ok(input, `não há campo ${fieldName} no passo à vista`);
    input.value = value;
    handleModelWizardInput({ target: input });
};

/** O template do fornecedor é um pedido: deixa-o resolver antes de se ler o resumo. */
const settled = () => new Promise((resolve) => setTimeout(resolve, 0));

const trailText = () => els.modelWizardTrail.textContent.replace(/\s+/g, " ").trim();

beforeEach(() => {
    sent = [];
    // O carrossel dos três slides é do Bootstrap, que não existe fora do browser.
    globalThis.bootstrap = {
        Carousel: class {
            constructor(element) {
                this._element = element;
            }

            to() {}
        },
    };
    state.settingsModal.modelsCarousel = null;
    state.settingsModal.modelFilters = FILTERS;
    state.settingsModal.sectionLoaded.modelFilters = true;
    state.settingsModal.modelCatalog = [];
    globalThis.fetch = async (url, options = {}) => {
        sent.push({ url: String(url), options });
        const body = String(url).startsWith("/api/models/template")
            ? TEMPLATE
            : { status: "ok" };
        return { ok: true, status: 200, text: async () => JSON.stringify(body) };
    };
});

test("abre no primeiro passo, a perguntar o tipo", async () => {
    await openWizard();

    assert.match(trailText(), /Passo 1 de 3 · Tipo$/);
    assert.ok(els.modelWizardAsk.querySelector("[data-model-type=\"watch\"]"));
    assert.equal(els.modelWizardSaveBtn.textContent.trim(), "Seguinte");
    assert.equal(els.modelWizardBackBtn.classList.contains("d-none"), true);
});

test("escolher o tipo avança para o fornecedor, e só mostra os desse tipo", async () => {
    await openWizard();
    clickIn(els.modelWizardAsk, "[data-model-type=\"watch\"]");

    assert.match(trailText(), /Passo 2 de 3 · Fornecedor$/);
    assert.deepEqual(
        [...els.modelWizardAsk.querySelectorAll("[data-model-supplier]")].map(
            (pill) => pill.dataset.modelSupplier,
        ),
        ["4P Touch", "Wonlex"],
    );
});

test("escolher o fornecedor avança para as informações, com o rodapé do último passo", async () => {
    await openWizard();
    clickIn(els.modelWizardAsk, "[data-model-type=\"watch\"]");
    clickIn(els.modelWizardAsk, "[data-model-supplier=\"4P Touch\"]");

    assert.match(trailText(), /Passo 3 de 3 · Informações$/);
    assert.equal(els.modelWizardBackBtn.textContent.trim(), "Fornecedor");
    assert.equal(els.modelWizardSaveBtn.textContent.trim(), "Guardar modelo");
    // Sem os dois nomes não há modelo para gravar.
    assert.equal(els.modelWizardSaveBtn.disabled, true);
});

test("o último passo diz quantas capacidades o modelo herda", async () => {
    await openWizard();
    clickIn(els.modelWizardAsk, "[data-model-type=\"watch\"]");
    clickIn(els.modelWizardAsk, "[data-model-supplier=\"4P Touch\"]");
    await settled();

    assert.equal(
        els.modelWizardTemplateSummary.textContent,
        "3 capacidades predefinidas para 4P Touch (Relógio).",
    );
});

test("com os dois nomes escritos, guarda o tipo, o fornecedor e as capacidades do template", async () => {
    await openWizard();
    clickIn(els.modelWizardAsk, "[data-model-type=\"watch\"]");
    clickIn(els.modelWizardAsk, "[data-model-supplier=\"4P Touch\"]");
    await settled();
    typeIn(els.modelWizardAsk, "commercialName", "R03");
    typeIn(els.modelWizardAsk, "internalModel", "Y6S");

    assert.equal(els.modelWizardSaveBtn.disabled, false);
    await saveModel();

    const post = sent.find((request) => request.url === "/api/models");
    assert.ok(post, "o assistente não gravou");
    const body = post.options.body;
    assert.equal(body.get("supplier_id"), "3");
    assert.equal(body.get("deviceType"), "watch");
    assert.equal(body.get("commercialName"), "R03");
    assert.equal(body.get("internalModel"), "Y6S");
    assert.deepEqual(body.getAll("capabilities[]"), ["heart_rate", "battery", "sos"]);
});

test("voltar ao tipo e trocá-lo esquece o fornecedor que já não serve", async () => {
    await openWizard();
    clickIn(els.modelWizardAsk, "[data-model-type=\"watch\"]");
    clickIn(els.modelWizardAsk, "[data-model-supplier=\"4P Touch\"]");
    modelWizardBack();
    modelWizardBack();
    clickIn(els.modelWizardAsk, "[data-model-type=\"radar\"]");

    assert.match(trailText(), /Passo 2 de 3 · Fornecedor$/);
    assert.deepEqual(
        [...els.modelWizardAsk.querySelectorAll("[data-model-supplier]")].map(
            (pill) => pill.dataset.modelSupplier,
        ),
        ["Qinglanst"],
    );
});
