import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";

const stateModule = await import("../../src/Dashboard/dashboard/state.js");
const {
    editWizardAnswered,
    initEditWizard,
    renderEditWizard,
    resetEditWizard,
} = await import("../../src/Dashboard/dashboard/devices/edit-wizard.js");

/**
 * A classificacao no modal de editar: as respostas em etiquetas, e uma pergunta de cada
 * vez quando se toca numa delas.
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

    return { root, els, changes, openQuestion };
}

test("abre no passo do aparelho, com a classificação em etiquetas", () => {
    // O que se vem alterar é o número de série ou os gateways: a classificação já está
    // feita, e um dispositivo registado não tem perguntas por responder.
    const { root, els, openQuestion } = harness();

    assert.deepEqual(
        [...root.querySelectorAll("[data-wizard-reopen]")].map((b) => b.dataset.wizardReopen),
        ["type", "model", "owner"],
    );
    assert.match(root.querySelector(".wizard-trail-step").textContent, /Passo 2 de 2/);
    assert.equal(els.deviceStep1.classList.contains("d-none"), true);
    assert.equal(els.deviceStep2.classList.contains("d-none"), false);
    assert.deepEqual(openQuestion(), []);
    assert.equal(els.saveDeviceBtn.classList.contains("d-none"), false);
    assert.equal(els.deviceNextBtn.classList.contains("d-none"), true);
});

test("as etiquetas dizem o que está escolhido", () => {
    const { root } = harness();

    assert.deepEqual(
        [...root.querySelectorAll("[data-wizard-reopen]")].map((badge) => [
            badge.querySelector(".wizard-badge-key").textContent,
            badge.textContent.replace(/\s+/g, " ").trim(),
        ]),
        [
            ["Tipo", "TipoMedidor de fraldas"],
            ["Modelo", "ModeloMECS-PRO"],
            ["Licença", "Licençagucc.dev (1001)"],
        ],
    );
});

test("tocar numa etiqueta abre aquela pergunta, e só aquela", () => {
    const { root, els, openQuestion } = harness();

    root.querySelector("[data-wizard-reopen=\"model\"]").click();

    assert.deepEqual(openQuestion(), ["model"]);
    assert.equal(els.deviceStep2.classList.contains("d-none"), true);
    assert.match(root.querySelector(".wizard-trail-step").textContent, /Passo 1 de 2/);
    // A pergunta aberta perde o valor da etiqueta: esta na grelha por baixo, marcada.
    assert.equal(root.querySelector("[data-wizard-reopen=\"model\"]"), null);
    assert.equal(root.querySelectorAll(".wizard-badge-now").length, 1);
    // E guardar só no passo do aparelho, que é onde há campos por validar.
    assert.equal(els.saveDeviceBtn.classList.contains("d-none"), true);
});

test("escolher o tipo leva ao modelo, porque o anterior deixou de existir", () => {
    const { openQuestion } = harness();

    editWizardAnswered("type");

    assert.deepEqual(openQuestion(), ["model"]);
});

test("escolher o modelo ou a licença fecha o passo", () => {
    const { els, openQuestion } = harness();

    editWizardAnswered("model");
    assert.deepEqual(openQuestion(), []);
    assert.equal(els.deviceStep2.classList.contains("d-none"), false);
});

test("a árvore abre com a licença actual marcada", () => {
    const { root } = harness({ company: "havicare", licenseId: "1" });

    root.querySelector("[data-wizard-reopen=\"owner\"]").click();

    const checked = [...root.querySelectorAll("#deviceLicensePicker [aria-checked=\"true\"]")];
    assert.equal(checked.length, 1);
    assert.equal(checked[0].dataset.licenseId, "1");
    assert.equal(checked[0].dataset.licenseCompany, "havicare");
});

test("escolher uma licença escreve a empresa e o número, e refaz os gateways", () => {
    // São duas colunas na base de dados e uma só escolha no ecrã; e a autorização de um
    // gateway é por empresa e licença, por isso os que estavam marcados eram de outro.
    const { root, els, changes } = harness();

    root.querySelector("[data-wizard-reopen=\"owner\"]").click();
    root.querySelector("#deviceLicensePicker [data-license-id=\"1\"]").click();

    assert.equal(els.deviceCompany.value, "havicare");
    assert.equal(els.deviceLicenseId.value, "1");
    assert.equal(changes.length, 1);
    assert.equal(els.deviceStep2.classList.contains("d-none"), false);
});

test("\"Sem licença\" limpa a empresa e não deixa o número anterior", () => {
    const { root, els } = harness();

    root.querySelector("[data-wizard-reopen=\"owner\"]").click();
    root.querySelector("#deviceLicensePicker [data-license-id=\"0\"]").click();

    assert.equal(els.deviceCompany.value, "");
    assert.equal(els.deviceLicenseId.value, "0");
});

test("o Anterior volta à classificação sem abrir pergunta nenhuma", () => {
    const { root, els, openQuestion } = harness();

    els.deviceBackBtn.click();

    assert.deepEqual(openQuestion(), ["none"]);
    assert.match(root.querySelector(".wizard-trail-step").textContent, /Passo 1 de 2/);
    // As três continuam respondidas: voltar atrás não apaga nada.
    assert.equal(root.querySelectorAll("[data-wizard-reopen]").length, 3);

    els.deviceNextBtn.click();
    assert.equal(els.deviceStep2.classList.contains("d-none"), false);
});

test("enquanto o dispositivo não chegou, a trilha não inventa uma classificação", () => {
    // O formulario ainda tem o que la estava por omissao -- Relogio, o primeiro modelo,
    // sem licença -- e isso é a classificação de outro aparelho.
    const { state } = stateModule;
    const { root } = harness();
    state.deviceModal.loading = true;
    renderEditWizard();

    assert.equal(root.querySelectorAll("[data-wizard-reopen]").length, 0);
    assert.equal(root.querySelectorAll(".wizard-badge").length, 3);

    state.deviceModal.loading = false;
    renderEditWizard();
    assert.equal(root.querySelectorAll("[data-wizard-reopen]").length, 3);
});
