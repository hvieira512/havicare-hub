import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";

const stateModule = await import("../../src/Dashboard/dashboard/state.js");
const {
    editWizardAnswered,
    initEditWizard,
    renderEditWizard,
    resetEditWizard,
} = await import("../../src/Dashboard/dashboard/devices/edit-wizard.js");

/**
 * A classificação no modal de editar: as respostas em etiquetas, e uma pergunta de cada vez
 * quando se toca numa delas.
 */

const LICENSES = [
    { company: "havicare", licenses: [{ licenseId: "1", name: "hc.dev" }] },
    { company: "hitcare", licenses: [{ licenseId: "1001", name: "gucc.dev" }] },
];

function harness({ company = "hitcare", licenseId = "1001" } = {}) {
    const root = parseFragment(`
        <form id="deviceForm" data-device-type="diaper_sensor" data-supplier="MONIT" data-model="MECS-PRO">
            <div class="wizard-trail" id="deviceTrail"></div>
            <div class="wizard-ask" id="deviceStep1">
                <div data-device-question="type"><div id="deviceTypeButtons"></div></div>
                <div data-device-question="model"><div id="deviceModelButtons"></div></div>
                <div data-device-question="owner"><div id="deviceLicensePicker"></div></div>
                <p data-device-question="none">Toque numa etiqueta.</p>
            </div>
            <input type="hidden" id="deviceCompany" value="${company}">
            <input type="hidden" id="deviceLicenseId" value="${licenseId}">
            <div class="wizard-ask" id="deviceStep2"></div>
            <button type="button" id="deviceBackBtn"></button>
            <button type="button" id="deviceNextBtn"></button>
            <button type="button" id="saveDeviceBtn"></button>
        </form>`);

    const els = {};
    for (const id of [
        "deviceForm", "deviceTrail", "deviceStep1", "deviceStep2",
        "deviceLicensePicker", "deviceCompany", "deviceLicenseId",
        "deviceBackBtn", "deviceNextBtn", "saveDeviceBtn",
    ]) {
        els[id] = root.querySelector(`#${id}`);
    }

    const changes = [];
    initEditWizard({ els, onLicenseChange: () => changes.push(true) });
    resetEditWizard(LICENSES);
    renderEditWizard();

    const openQuestion = () =>
        [...root.querySelectorAll("[data-device-question]")]
            .filter((block) => !block.classList.contains("d-none"))
            .map((block) => block.dataset.deviceQuestion);

    const classification = (selector = "[data-wizard-reopen]") =>
        root.querySelectorAll(selector);

    return { root, els, changes, openQuestion, classification };
}

test("opens on the device step, with the classification as labels", () => {
    // O que se vem alterar é o número de série ou os gateways: a classificação já está
    // feita, e um dispositivo registado não tem perguntas por responder.
    const { root, els, openQuestion, classification } = harness();

    assert.deepEqual(
        [...classification()].map((b) => b.dataset.wizardReopen),
        ["type", "model", "owner"],
    );
    // Sem contador de passos: um dispositivo que já existe não está a meio de uma sequência, e
    // são as etiquetas que dizem o que ele é.
    assert.equal(root.querySelector(".wizard-trail-step"), null);
    assert.equal(els.deviceStep1.classList.contains("d-none"), true);
    assert.equal(els.deviceStep2.classList.contains("d-none"), false);
    assert.deepEqual(openQuestion(), []);
    assert.equal(els.saveDeviceBtn.classList.contains("d-none"), false);
    assert.equal(els.deviceNextBtn.classList.contains("d-none"), true);
});

test("the rows say what is chosen", () => {
    const { classification } = harness();

    assert.deepEqual(
        [...classification()]
            .map((row) => row.textContent.replace(/\s+/g, " ").trim()),
        [
            "Tipo Medidor de fraldas",
            "Modelo MECS-PRO",
            "Licença gucc.dev (1001)",
        ],
    );
});

test("tapping a row opens that question and only that one", () => {
    const { els, openQuestion, classification } = harness();

    classification("[data-wizard-reopen=\"model\"]")[0].click();

    assert.deepEqual(openQuestion(), ["model"]);
    assert.equal(els.deviceStep2.classList.contains("d-none"), true);
    // A pergunta aberta perde o valor da linha: está na grelha por baixo, marcada.
    const open = classification("[data-wizard-reopen=\"model\"]")[0];
    assert.equal(open.getAttribute("aria-expanded"), "true");
    assert.doesNotMatch(open.textContent, /MECS-PRO/);
    assert.equal(
        classification("[aria-expanded=\"true\"]").length,
        1,
    );
    // O `Guardar` fica no rodapé mesmo com uma pergunta aberta, e guardar fecha-a primeiro
    // para a validação se ver.
    assert.equal(els.saveDeviceBtn.classList.contains("d-none"), false);
});

test("choosing the type leads to the model, because the previous one no longer exists", () => {
    const { openQuestion } = harness();

    editWizardAnswered("type");

    assert.deepEqual(openQuestion(), ["model"]);
});

test("choosing the model or the licence closes the step", () => {
    const { els, openQuestion } = harness();

    editWizardAnswered("model");
    assert.deepEqual(openQuestion(), []);
    assert.equal(els.deviceStep2.classList.contains("d-none"), false);
});

test("the tree opens with the current licence marked", () => {
    const { root, classification } = harness({ company: "havicare", licenseId: "1" });

    classification("[data-wizard-reopen=\"owner\"]")[0].click();

    const checked = [...root.querySelectorAll("#deviceLicensePicker [aria-checked=\"true\"]")];
    assert.equal(checked.length, 1);
    assert.equal(checked[0].dataset.licenseId, "1");
    assert.equal(checked[0].dataset.licenseCompany, "havicare");
});

test("choosing a licence writes the company and number, and redraws the gateways", () => {
    // São duas colunas na base de dados e uma só escolha no ecrã; e a autorização de um
    // gateway é por empresa e licença, por isso os que estavam marcados eram de outro.
    const { root, els, changes, classification } = harness();

    classification("[data-wizard-reopen=\"owner\"]")[0].click();
    root.querySelector("#deviceLicensePicker [data-license-id=\"1\"]").click();

    assert.equal(els.deviceCompany.value, "havicare");
    assert.equal(els.deviceLicenseId.value, "1");
    assert.equal(changes.length, 1);
    assert.equal(els.deviceStep2.classList.contains("d-none"), false);
});

test("«Sem licença» clears the company and leaves no previous number", () => {
    const { root, els, classification } = harness();

    classification("[data-wizard-reopen=\"owner\"]")[0].click();
    root.querySelector("#deviceLicensePicker [data-license-id=\"0\"]").click();

    assert.equal(els.deviceCompany.value, "");
    assert.equal(els.deviceLicenseId.value, "0");
});

/**
 * Num dispositivo que existe não há passo atrás: este botão serve para sair de uma pergunta
 * aberta por engano.
 */
test("leaving an open question brings back the device fields without erasing answers", () => {
    const { els, openQuestion, classification } = harness();

    classification("[data-wizard-reopen=\"model\"]")[0].click();
    assert.deepEqual(openQuestion(), ["model"]);

    els.deviceNextBtn.click();

    assert.deepEqual(openQuestion(), []);
    assert.equal(els.deviceStep2.classList.contains("d-none"), false);
    // As três continuam respondidas: sair não apaga nada.
    assert.equal(classification().length, 3);
});

test("until the device has loaded, the rows do not make up a classification", () => {
    // O formulário ainda tem o que lá estava por omissão -- Relógio, o primeiro modelo, sem
    // licença -- e isso é a classificação de outro aparelho.
    const { state } = stateModule;
    const { classification } = harness();
    state.deviceModal.loading = true;
    renderEditWizard();

    const values = () => [...classification()]
        .map((row) => row.textContent.replace(/\s+/g, " ").trim());
    assert.deepEqual(values(), ["Tipo", "Modelo", "Licença"]);

    state.deviceModal.loading = false;
    renderEditWizard();
    assert.deepEqual(values(), [
        "Tipo Medidor de fraldas",
        "Modelo MECS-PRO",
        "Licença gucc.dev (1001)",
    ]);
});
