import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initCreateWizard, openWizard } =
    await import("../../src/Dashboard/dashboard/devices/create-wizard.js");

/**
 * Os cinco passos do assistente de adicionar, percorridos no DOM: o contador, a barra, a
 * migalha e o que os dois botões do rodapé dizem em cada um.
 */

const MODELS = [
    { supplier: "4P Touch", internal_model: "D41", commercial_name: "D41", device_type: "watch" },
    { supplier: "Wonlex", internal_model: "KT26", commercial_name: "KT26", device_type: "watch" },
    { supplier: "MONIT", internal_model: "MECS-PRO", commercial_name: "MECS Pro", device_type: "diaper_sensor" },
];

const LICENSES = [
    { company_name: "hitcare", license_id: "1001", name: "gucc.dev" },
];

const IDS = [
    "wizardTrail", "wizardProgress", "wizardStepCount", "wizardAsk",
    "wizardArt", "wizardError", "wizardBackBtn", "wizardNextBtn",
];

let root;
let els;

function harness() {
    root = parseFragment(`
        <span id="wizardStepCount"></span>
        <div id="wizardProgress"></div>
        <div class="wizard-trail" id="wizardTrail"></div>
        <div class="wizard-ask" id="wizardAsk"></div>
        <div class="wizard-art d-none" id="wizardArt"></div>
        <div class="d-none" id="wizardError"></div>
        <button type="button" id="wizardBackBtn"></button>
        <button type="button" id="wizardNextBtn"></button>`);

    els = {};
    for (const id of IDS) els[id] = root.querySelector(`#${id}`);
    initCreateWizard({ els, wizardModal: { show: () => {}, hide: () => {} } });
}

const text = (node) => (node?.textContent || "").replace(/\s+/g, " ").trim();
const crumbs = () => [...root.querySelectorAll("#wizardTrail .wizard-badge")].map(text);
const pick = (selector) => root.querySelector(selector).click();

beforeEach(async () => {
    // Sem pedidos: com o estado já preenchido, o `ensure*` devolve o que lá está.
    globalThis.fetch = async () => ({
        ok: true,
        status: 200,
        text: async () => JSON.stringify({ data: [], pagination: {} }),
        headers: { get: () => "application/json" },
    });
    state.deviceTypeSuppliersModels = MODELS;
    state.licenses = LICENSES;
    harness();
    await openWizard();
});

test("o assistente abre no primeiro dos cinco passos", () => {
    assert.equal(text(els.wizardStepCount), "1 de 5");
    const bar = els.wizardProgress.firstElementChild;
    assert.equal(bar.getAttribute("aria-valuenow"), "1");
    assert.equal(bar.getAttribute("aria-valuemax"), "5");
    assert.equal(bar.children.length, 5);
    assert.deepEqual(crumbs(), ["Tipo", "Fornecedor", "Modelo", "Licença", "Identificação"]);
    assert.equal(els.wizardBackBtn.classList.contains("d-none"), true, "nao ha passo atras");
    assert.equal(text(els.wizardNextBtn), "Seguinte: Fornecedor");
    assert.equal(els.wizardNextBtn.disabled, true, "sem tipo escolhido nao avanca");
});

test("o fornecedor é um passo com nome próprio, e conta para os cinco", () => {
    pick("[data-wizard-type=\"watch\"]");

    assert.equal(text(els.wizardStepCount), "2 de 5");
    assert.deepEqual(crumbs(), ["Tipo · Relógio", "Fornecedor", "Modelo", "Licença", "Identificação"]);
    assert.equal(text(els.wizardBackBtn), "Tipo");
    assert.equal(text(els.wizardNextBtn), "Seguinte: Modelo");
    assert.equal(root.querySelectorAll("#wizardAsk [data-wizard-supplier]").length, 2);
});

test("cada escolha leva ao passo seguinte, até ao verbo de criar", () => {
    pick("[data-wizard-type=\"watch\"]");
    pick("[data-wizard-supplier=\"Wonlex\"]");
    assert.equal(text(els.wizardStepCount), "3 de 5");
    assert.equal(text(els.wizardNextBtn), "Seguinte: Licença");

    pick("[data-wizard-model=\"KT26\"]");
    assert.equal(text(els.wizardStepCount), "4 de 5");
    assert.equal(text(els.wizardNextBtn), "Seguinte: Identificação");

    pick("[data-license-id=\"1001\"]");
    assert.equal(text(els.wizardStepCount), "5 de 5");
    assert.equal(text(els.wizardNextBtn), "Criar dispositivo");
    assert.equal(els.wizardNextBtn.disabled, true, "falta a identidade");
    assert.deepEqual(crumbs(), [
        "Tipo · Relógio", "Fornecedor · Wonlex", "Modelo · KT26",
        "Licença · gucc.dev (1001)", "Identificação",
    ]);
});

test("uma migalha já respondida volta àquele passo, e não só ao anterior", () => {
    pick("[data-wizard-type=\"watch\"]");
    pick("[data-wizard-supplier=\"Wonlex\"]");
    pick("[data-wizard-model=\"KT26\"]");

    const crumb = root.querySelector("#wizardTrail [data-wizard-reopen=\"type\"]");
    assert.equal(crumb.tagName, "BUTTON");
    crumb.click();

    assert.equal(text(els.wizardStepCount), "1 de 5");
    assert.notEqual(root.querySelector("#wizardAsk [data-wizard-type]"), null);
});

test("voltar a um passo dado mostra a pergunta com a resposta marcada", () => {
    pick("[data-wizard-type=\"watch\"]");
    pick("[data-wizard-supplier=\"Wonlex\"]");
    els.wizardBackBtn.click();

    assert.equal(text(els.wizardStepCount), "2 de 5");
    assert.equal(
        root.querySelector("#wizardAsk [data-wizard-supplier=\"Wonlex\"]").getAttribute("aria-pressed"),
        "true",
    );
});

/** Um tipo com um fornecedor só não faz pergunta nenhuma: a resposta é a única que há. */
test("um tipo com um único fornecedor responde ao passo sozinho", () => {
    pick("[data-wizard-type=\"diaper_sensor\"]");

    assert.equal(text(els.wizardStepCount), "3 de 5");
    assert.deepEqual(crumbs().slice(0, 3), [
        "Tipo · Medidor de fraldas", "Fornecedor · MONIT", "Modelo",
    ]);
});

test("num telemóvel fica só o passo actual, e o contador diz em que ponto se está", () => {
    // Cinco nomes numa linha não cabem em 342px: os outros quatro só aparecem a partir de
    // `md`, e a barra e o contador continuam a dizer quanto falta.
    pick("[data-wizard-type=\"watch\"]");

    const visible = [...root.querySelectorAll("#wizardTrail .wizard-badge")]
        .filter((crumb) => !crumb.classList.contains("d-none"));

    assert.deepEqual(visible.map(text), ["Fornecedor"]);
    assert.equal(els.wizardProgress.classList.contains("d-none"), false);
});
