import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";
import { cacheElements } from "../../src/Dashboard/dashboard/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { handleCapabilityGroupsChange } =
    await import("../../src/Dashboard/dashboard/settings/models/capabilities-editor.js");

/**
 * «Pode ser pedida» depende de «o modelo tem»: pedir uma leitura que o modelo não oferece
 * mandaria um comando que nunca tem resposta.
 */
const els = autoEls();

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
    cacheElements(els);
    state.settingsModal.capabilityEnabledCapabilities = ["heart_rate"];
    state.settingsModal.capabilityRequestableCapabilities = [];
    // Com template do fornecedor, desligar não tira a linha e o ouvinte acerta no sítio em
    // vez de redesenhar a secção inteira.
    state.settingsModal.capabilityModelTemplateKeys = ["heart_rate", "battery"];
    state.settingsModal.activeCapabilitySection = "telemetry";
});

test("turning support on adds the capability", () => {
    handleCapabilityGroupsChange(support("battery", true));

    assert.ok(enabled().includes("battery"));
});

test("a supported capability can become requestable", () => {
    handleCapabilityGroupsChange(requestable("heart_rate", true));

    assert.ok(requested().includes("heart_rate"));
});

test("requesting a capability the model does not support saves nothing", () => {
    handleCapabilityGroupsChange(requestable("battery", true));

    assert.ok(!requested().includes("battery"), "não se guarda um pedido sem suporte");
});

test("turning support off takes the request with it", () => {
    handleCapabilityGroupsChange(requestable("heart_rate", true));
    assert.ok(requested().includes("heart_rate"), "o cenário começa com o pedido ligado");

    handleCapabilityGroupsChange(support("heart_rate", false));

    assert.ok(!enabled().includes("heart_rate"));
    assert.ok(!requested().includes("heart_rate"), "o pedido não pode sobreviver ao suporte");
});

test("unticking the request leaves the support where it was", () => {
    handleCapabilityGroupsChange(requestable("heart_rate", true));
    handleCapabilityGroupsChange(requestable("heart_rate", false));

    assert.ok(!requested().includes("heart_rate"));
    assert.ok(enabled().includes("heart_rate"), "desligar o pedido não desliga o suporte");
});

test("a click outside both switches changes nothing", () => {
    handleCapabilityGroupsChange(toggle("outraCoisa", "heart_rate", true));

    assert.deepEqual(enabled(), ["heart_rate"]);
    assert.deepEqual(requested(), []);
});
