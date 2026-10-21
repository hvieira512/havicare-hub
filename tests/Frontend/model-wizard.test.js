import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";

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
const tagFor = (name) => (name.endsWith("Btn") ? "button" : "div");

function openWizard() {
    els = autoEls(tagFor);
    els.modelsCarousel.innerHTML = "<div class=\"carousel-item\"></div><div class=\"carousel-item active\"></div>";
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

/** Em que passo está: o contador e a barra contam o mesmo. */
const stepNow = () => ({
    count: els.modelWizardStepCount.textContent,
    now: els.modelWizardTrail.querySelector(".wizard-badge-now")?.textContent.trim(),
});

beforeEach(() => {
    sent = [];
    // O carrossel dos três painéis é do Bootstrap, que não existe fora do browser.
    globalThis.bootstrap = { Carousel: { getOrCreateInstance: () => ({ to() {} }) } };
    state.settingsModal.modelFilters = FILTERS;
    state.settingsModal.sectionLoaded.modelFilters = true;
    state.settingsModal.modelCatalog = [];
    globalThis.fetch = async (url, options = {}) => {
        sent.push({ url: String(url), options });
        const body = String(url).startsWith("/api/models/template")
            ? TEMPLATE
            : { status: "ok" };
        return Response.json(body);
    };
});

test("opens on the first step, asking for the type", async () => {
    await openWizard();

    assert.deepEqual(stepNow(), { count: "1 de 3", now: "Tipo" });
    assert.ok(els.modelWizardAsk.querySelector("[data-model-type=\"watch\"]"));
    assert.equal(els.modelWizardSaveBtn.textContent.trim(), "Seguinte: Fornecedor");
    assert.equal(els.modelWizardBackBtn.classList.contains("d-none"), true);
});

test("choosing the type moves to the supplier and shows only that type's suppliers", async () => {
    await openWizard();
    clickIn(els.modelWizardAsk, "[data-model-type=\"watch\"]");

    assert.deepEqual(stepNow(), { count: "2 de 3", now: "Fornecedor" });
    assert.deepEqual(
        [...els.modelWizardAsk.querySelectorAll("[data-model-supplier]")].map(
            (pill) => pill.dataset.modelSupplier,
        ),
        ["4P Touch", "Wonlex"],
    );
});

test("choosing the supplier moves to the details, with the last step's footer", async () => {
    await openWizard();
    clickIn(els.modelWizardAsk, "[data-model-type=\"watch\"]");
    clickIn(els.modelWizardAsk, "[data-model-supplier=\"4P Touch\"]");

    assert.deepEqual(stepNow(), { count: "3 de 3", now: "Informações" });
    assert.equal(els.modelWizardBackBtn.textContent.trim(), "Fornecedor");
    assert.equal(els.modelWizardSaveBtn.textContent.trim(), "Guardar modelo");
    // Sem os dois nomes não há modelo para gravar.
    assert.equal(els.modelWizardSaveBtn.disabled, true);
});

test("the last step says how many capabilities the model inherits", async () => {
    await openWizard();
    clickIn(els.modelWizardAsk, "[data-model-type=\"watch\"]");
    clickIn(els.modelWizardAsk, "[data-model-supplier=\"4P Touch\"]");
    await settled();

    assert.equal(
        els.modelWizardTemplateSummary.textContent,
        "3 capacidades predefinidas para 4P Touch (Relógio).",
    );
});

test("with both names typed, saves the type, the supplier and the template capabilities", async () => {
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

test("going back and changing the type forgets the supplier that no longer fits", async () => {
    await openWizard();
    clickIn(els.modelWizardAsk, "[data-model-type=\"watch\"]");
    clickIn(els.modelWizardAsk, "[data-model-supplier=\"4P Touch\"]");
    modelWizardBack();
    modelWizardBack();
    clickIn(els.modelWizardAsk, "[data-model-type=\"radar\"]");

    assert.deepEqual(stepNow(), { count: "2 de 3", now: "Fornecedor" });
    assert.deepEqual(
        [...els.modelWizardAsk.querySelectorAll("[data-model-supplier]")].map(
            (pill) => pill.dataset.modelSupplier,
        ),
        ["Qinglanst"],
    );
});
