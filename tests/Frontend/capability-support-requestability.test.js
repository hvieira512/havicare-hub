import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initSettingsModels } =
    await import("../../src/Dashboard/dashboard/settings/models/shell.js");
const { handleCapabilityGroupsChange } =
    await import("../../src/Dashboard/dashboard/settings/models/capabilities-editor.js");

/**
 * Duas afirmações sobre um modelo, e uma depende da outra: «este modelo tem esta capacidade»
 * e «esta capacidade pode ser pedida ao aparelho». Pedir uma leitura que o modelo não oferece
 * não é estado que se possa guardar -- o hub mandaria um comando que nunca tem resposta.
 *
 * O invariante vive num ouvinte só, delegado na raiz das secções, e não tinha rede nenhuma.
 */
const els = new Proxy({}, {
    get(target, name) {
        if (typeof name !== "string") return undefined;
        if (!(name in target)) target[name] = document.createElement("div");
        return target[name];
    },
});

const toggle = (action, feature, checked) => {
    const checkbox = document.createElement("input");
    checkbox.type = "checkbox";
    checkbox.dataset.action = action;
    checkbox.dataset.feature = feature;
    checkbox.checked = checked;
    document.body.appendChild(checkbox);

    return { target: checkbox };
};

const support = (feature, checked) => toggle("toggleCapabilitySupport", feature, checked);
const requestable = (feature, checked) => toggle("toggleCapabilityRequestability", feature, checked);

const enabled = () => state.settingsModal.capabilityEnabledCapabilities;
const requested = () => state.settingsModal.capabilityRequestableCapabilities;

beforeEach(() => {
    document.body.innerHTML = "";
    initSettingsModels({ els, ui: {} });
    state.settingsModal.capabilityEnabledCapabilities = ["heart_rate"];
    state.settingsModal.capabilityRequestableCapabilities = [];
    // Com template do fornecedor, desligar não tira a linha e o ouvinte acerta no sítio em
    // vez de redesenhar a secção inteira.
    state.settingsModal.capabilityModelTemplateKeys = ["heart_rate", "battery"];
    state.settingsModal.activeCapabilitySection = "telemetry";
});

test("ligar o suporte acrescenta a capacidade", () => {
    handleCapabilityGroupsChange(support("battery", true));

    assert.ok(enabled().includes("battery"));
});

test("uma capacidade suportada pode passar a ser pedida", () => {
    handleCapabilityGroupsChange(requestable("heart_rate", true));

    assert.ok(requested().includes("heart_rate"));
});

/** É o invariante: sem suporte não há pedido que se possa guardar. */
test("pedir uma capacidade que o modelo não suporta não guarda nada", () => {
    handleCapabilityGroupsChange(requestable("battery", true));

    assert.ok(!requested().includes("battery"), "não se guarda um pedido sem suporte");
});

test("desligar o suporte leva o pedido com ele", () => {
    handleCapabilityGroupsChange(requestable("heart_rate", true));
    assert.ok(requested().includes("heart_rate"), "o cenário começa com o pedido ligado");

    handleCapabilityGroupsChange(support("heart_rate", false));

    assert.ok(!enabled().includes("heart_rate"));
    assert.ok(!requested().includes("heart_rate"), "o pedido não pode sobreviver ao suporte");
});

test("desmarcar o pedido deixa o suporte onde estava", () => {
    handleCapabilityGroupsChange(requestable("heart_rate", true));
    handleCapabilityGroupsChange(requestable("heart_rate", false));

    assert.ok(!requested().includes("heart_rate"));
    assert.ok(enabled().includes("heart_rate"), "desligar o pedido não desliga o suporte");
});

test("um clique fora dos dois interruptores não mexe em nada", () => {
    handleCapabilityGroupsChange(toggle("outraCoisa", "heart_rate", true));

    assert.deepEqual(enabled(), ["heart_rate"]);
    assert.deepEqual(requested(), []);
});
