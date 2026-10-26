import assert from "node:assert/strict";
import test from "node:test";

import { parseFragment } from "./support/dom.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import {
    captureConfigPristine,
    changedConfigEntries,
    syncConfigCounts,
} from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { resetConfigPane } from "../../src/Dashboard/dashboard/devices/config/handlers.js";
import { cacheElements } from "../../src/Dashboard/dashboard/core/dom.js";

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
    cacheElements({});
    syncConfigCounts(host);
    return host;
};

const pane = (root) => root.querySelector("[data-config-pane=\"health\"]");
const fieldOf = (root, key) =>
    root.querySelector(`[data-config-section][data-config-key="${key}"] [data-config-field]`);

test("a setting no longer has its own send button", () => {
    const root = render();

    assert.equal(
        root.querySelectorAll("[data-config-section][data-config-key=\"heart_rate\"] [data-action=\"saveConfig\"]").length,
        0,
    );
});

test("an action keeps its button, the only place it happens", () => {
    const root = render();

    assert.equal(
        root.querySelectorAll("[data-config-section][data-config-key=\"find_device\"] [data-action=\"saveConfig\"]").length,
        1,
    );
});

test("the section has one footer, with reset and send", () => {
    const footers = pane(render()).querySelectorAll(".config-section-footer");

    assert.equal(footers.length, 1);
    assert.ok(footers[0].querySelector("[data-action=\"resetConfigPane\"]"));
    assert.ok(footers[0].querySelector("[data-action=\"saveConfigPane\"]"));
});

test("the footer says how many changes are unsent, and only lights up with them", () => {
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

test("the section's send carries only what changed", () => {
    const root = render();
    fieldOf(root, "step_goal").value = "12000";

    assert.deepEqual(changedConfigEntries(pane(root)), { step_goal: { steps: 12000 } });
});

test("reset returns the fields to the value they had", () => {
    const root = render();
    fieldOf(root, "heart_rate").value = "30";
    fieldOf(root, "step_goal").value = "12000";

    resetConfigPane(pane(root));

    assert.equal(fieldOf(root, "heart_rate").value, "60");
    assert.equal(fieldOf(root, "step_goal").value, "8000");
    assert.deepEqual(changedConfigEntries(pane(root)), {});
});

test("a section the hub applies by itself saves instead of sending", () => {
    const host = document.createElement("div");
    host.appendChild(parseFragment(renderDeviceConfigurationRoot({
        protocol: "monit-mecs-pro-ble",
        catalog: [{ ...setting("diaper_sensitivity", "pollutionRange"), command: "" }],
        capabilityCatalog: [{ key: "diaper_sensitivity", section: "health", sectionLabel: "Saúde", isConfigurable: true }],
        configurations: { diaper_sensitivity: { pollutionRange: 4 } },
        capabilities: {},
    })));
    document.body.replaceChildren(host);
    captureConfigPristine(host);
    syncConfigCounts(host);

    assert.match(pane(host).querySelector("[data-action=\"saveConfigPane\"]").textContent, /Guardar/);
    assert.doesNotMatch(pane(host).querySelector("[data-action=\"saveConfigPane\"]").textContent, /dispositivo/);

    fieldOf(host, "diaper_sensitivity").value = "5";
    syncConfigCounts(host);

    assert.match(pane(host).querySelector("[data-config-pane-status]").textContent, /1 alteração por guardar/);
});
