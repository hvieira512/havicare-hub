import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import {
    captureConfigPristine,
    changedConfigEntries,
    initDeviceConfigPanel,
    syncConfigCounts,
} from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { resetConfigPane } from "../../src/Dashboard/dashboard/devices/config/handlers.js";

/** O envio é da secção; só tem botão próprio o que dispara em vez de se guardar. */

const setting = (key, field) => ({
    key,
    capabilityKey: key,
    command: key,
    label: key,
    input: "number",
    fields: [field],
    category: "health",
});

const action = (key) => ({
    key,
    capabilityKey: key,
    command: key,
    label: key,
    input: "action",
    fields: [],
    transient: true,
    category: "health",
});

const CATALOG = [setting("heart_rate", "interval"), setting("step_goal", "steps"), action("find_device")];

const CAPABILITIES = [
    { key: "heart_rate", section: "health", sectionLabel: "Saúde", isConfigurable: true },
    { key: "step_goal", section: "health", sectionLabel: "Saúde", isConfigurable: true },
    { key: "find_device", section: "health", sectionLabel: "Saúde", isRequestable: true },
];

const render = () => {
    const host = document.createElement("div");
    host.appendChild(parseFragment(renderDeviceConfigurationRoot({
        protocol: "veepoo-ble",
        catalog: CATALOG,
        capabilityCatalog: CAPABILITIES,
        configurations: { heart_rate: { interval: 60 }, step_goal: { steps: 8000 } },
        capabilities: {},
    })));
    document.body.replaceChildren(host);
    captureConfigPristine(host);
    initDeviceConfigPanel({ els: {} });
    syncConfigCounts(host);
    return host;
};

const pane = (root) => root.querySelector("[data-config-pane=\"health\"]");
const fieldOf = (root, key) =>
    root.querySelector(`[data-config-section][data-config-key="${key}"] [data-config-field]`);

test("uma definição deixa de ter o seu próprio botão de enviar", () => {
    const root = render();

    assert.equal(
        root.querySelectorAll("[data-config-section][data-config-key=\"heart_rate\"] [data-action=\"saveConfig\"]").length,
        0,
    );
});

test("uma acção mantém o botão, que é o único sítio onde ela acontece", () => {
    const root = render();

    assert.equal(
        root.querySelectorAll("[data-config-section][data-config-key=\"find_device\"] [data-action=\"saveConfig\"]").length,
        1,
    );
});

test("a secção tem um rodapé só, com repor e enviar", () => {
    const footers = pane(render()).querySelectorAll(".config-section-footer");

    assert.equal(footers.length, 1);
    assert.ok(footers[0].querySelector("[data-action=\"resetConfigPane\"]"));
    assert.ok(footers[0].querySelector("[data-action=\"saveConfigPane\"]"));
});

test("o rodapé diz quantas alterações estão por enviar, e só acende com elas", () => {
    const root = render();
    const send = pane(root).querySelector("[data-action=\"saveConfigPane\"]");

    assert.equal(send.disabled, true);
    // Sem nada por enviar o rodapé cala-se: são os botões desligados que o dizem.
    assert.equal(pane(root).querySelector("[data-config-pane-status]").textContent, "");

    fieldOf(root, "heart_rate").value = "30";
    syncConfigCounts(root);

    assert.equal(send.disabled, false);
    assert.match(pane(root).querySelector("[data-config-pane-status]").textContent, /1 alteração por enviar/);
});

test("o envio da secção leva só o que mudou", () => {
    const root = render();
    fieldOf(root, "step_goal").value = "12000";

    assert.deepEqual(changedConfigEntries(pane(root)), { step_goal: { steps: 12000 } });
});

test("repor devolve os campos ao valor que lá estava", () => {
    const root = render();
    fieldOf(root, "heart_rate").value = "30";
    fieldOf(root, "step_goal").value = "12000";

    resetConfigPane(pane(root));

    assert.equal(fieldOf(root, "heart_rate").value, "60");
    assert.equal(fieldOf(root, "step_goal").value, "8000");
    assert.deepEqual(changedConfigEntries(pane(root)), {});
});
