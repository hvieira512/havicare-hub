import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

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
    globalThis.fetch = async () => Response.json({ data: [], pagination: {} });
    state.deviceTypeSuppliersModels = MODELS;
    state.licenses = LICENSES;
    harness();
    await openWizard();
});

test("the wizard opens on the first of five steps", () => {
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

test("the supplier is a step of its own, and counts towards the five", () => {
    pick("[data-wizard-type=\"watch\"]");

    assert.equal(text(els.wizardStepCount), "2 de 5");
    assert.deepEqual(crumbs(), ["Tipo · Relógio", "Fornecedor", "Modelo", "Licença", "Identificação"]);
    assert.equal(text(els.wizardBackBtn), "Tipo");
    assert.equal(text(els.wizardNextBtn), "Seguinte: Modelo");
    assert.equal(root.querySelectorAll("#wizardAsk [data-wizard-supplier]").length, 2);
});

test("each choice leads to the next step, up to the create verb", () => {
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

test("an answered breadcrumb goes back to that step, not just the previous one", () => {
    pick("[data-wizard-type=\"watch\"]");
    pick("[data-wizard-supplier=\"Wonlex\"]");
    pick("[data-wizard-model=\"KT26\"]");

    const crumb = root.querySelector("#wizardTrail [data-wizard-reopen=\"type\"]");
    assert.equal(crumb.tagName, "BUTTON");
    crumb.click();

    assert.equal(text(els.wizardStepCount), "1 de 5");
    assert.notEqual(root.querySelector("#wizardAsk [data-wizard-type]"), null);
});

test("going back to a done step shows the question with the answer marked", () => {
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
test("a type with a single supplier answers its step on its own", () => {
    pick("[data-wizard-type=\"diaper_sensor\"]");

    assert.equal(text(els.wizardStepCount), "3 de 5");
    assert.deepEqual(crumbs().slice(0, 3), [
        "Tipo · Medidor de fraldas", "Fornecedor · MONIT", "Modelo",
    ]);
});

function walkToDiaperIdentity() {
    pick("[data-wizard-type=\"diaper_sensor\"]");
    pick("[data-wizard-model=\"MECS-PRO\"]");
    pick("[data-license-id=\"1001\"]");
}

test("with no gateways in the licence, the notice shows as text, not markup", () => {
    state.wizardGateways = [];
    walkToDiaperIdentity();

    const empty = root.querySelector("#wizardAsk .gateway-picker-empty");
    assert.notEqual(empty, null);
    assert.equal(text(empty), "Nenhum gateway nesta empresa e licença.");
    assert.doesNotMatch(text(els.wizardAsk), /<div/);
});

test("a gateway of the same licence appears as a card to choose", () => {
    state.wizardGateways = [
        { imei: "d48c49f7909c", model: "MKGW3", company: "hitcare", licenseId: "1001" },
    ];
    walkToDiaperIdentity();

    const check = root.querySelector("#wizardAsk .gateway-card [data-gateway-key=\"d48c49f7909c\"]");
    assert.notEqual(check, null);
    assert.doesNotMatch(text(els.wizardAsk), /<label/);
});

test("the supplier and model names reach the picture as text, not markup", () => {
    state.deviceTypeSuppliersModels = [
        { supplier: "Evil<b>Co</b>", internal_model: "M<b>1</b>", commercial_name: "M1", device_type: "watch" },
    ];
    pick("[data-wizard-type=\"watch\"]");
    [...root.querySelectorAll("#wizardAsk [data-wizard-model]")][0].click();

    assert.equal(els.wizardArt.querySelector("b"), null);
    assert.match(text(els.wizardArt), /Evil<b>Co<\/b> M<b>1<\/b>/);
});

test("a Tuya bracelet asks for its Tuya id and offers no gateways", () => {
    state.deviceTypeSuppliersModels = [
        { supplier: "PST", internal_model: "SOS-WIFI", commercial_name: "SOS Wi-Fi", device_type: "bracelet", protocol: "tuya-cloud" },
    ];
    state.wizardGateways = [];
    pick("[data-wizard-type=\"bracelet\"]");
    pick("[data-wizard-model=\"SOS-WIFI\"]");
    pick("[data-license-id=\"1001\"]");

    assert.match(text(els.wizardAsk), /ID do dispositivo na Tuya/);
    assert.equal(root.querySelector("#wizardAsk .gateway-picker-empty"), null);
});
