import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import {
    captureConfigPristine,
    initDeviceConfigPanel,
    syncConfigCounts,
} from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { selectConfigSection } from "../../src/Dashboard/dashboard/devices/config/handlers.js";

/** As secções variam por fornecedor e chegam a seis: em tira horizontal não cabem a 342 px. */

const CAPABILITIES = [
    { key: "heart_rate", section: "health", sectionLabel: "Saúde", isConfigurable: true },
    { key: "step_goal", section: "health", sectionLabel: "Saúde", isConfigurable: true },
    { key: "phonebook", section: "contacts", sectionLabel: "Contactos", isConfigurable: true },
    { key: "timezone", section: "settings_system", sectionLabel: "Sistema", isConfigurable: true },
];

const entry = (key, category, fields) => ({
    key,
    capabilityKey: key,
    command: key,
    label: key,
    input: "number",
    fields: [fields],
    category,
});

const render = () => {
    const host = document.createElement("div");
    host.appendChild(parseFragment(renderDeviceConfigurationRoot({
        protocol: "veepoo-ble",
        catalog: [
            entry("heart_rate", "health", "interval"),
            entry("step_goal", "health", "steps"),
            entry("phonebook", "contacts", "slot"),
            entry("timezone", "settings_system", "offset"),
        ],
        capabilityCatalog: CAPABILITIES,
        configurations: {
            heart_rate: { interval: 60 },
            step_goal: { steps: 8000 },
            phonebook: { slot: 1 },
            timezone: { offset: 0 },
        },
        capabilities: {},
    })));
    document.body.replaceChildren(host);
    captureConfigPristine(host);
    return host;
};

const links = (root) => [...root.querySelectorAll("[data-config-section-link]")];

test("as secções são uma lista, e não uma tira que corta", () => {
    const root = render();

    assert.equal(root.querySelectorAll(".capability-section-chip").length, 0);
    assert.deepEqual(
        links(root).map((link) => link.dataset.section),
        ["health", "contacts", "settings_system"],
    );
});

test("cada linha diz a etiqueta e quantas definições tem", () => {
    const health = links(render())[0];

    assert.match(health.textContent, /Saúde/);
    assert.equal(health.querySelector("[data-config-section-total]").textContent.trim(), "2");
});

test("cada secção tem o seu painel, e só o da secção activa está aberto", () => {
    const root = render();
    const panes = [...root.querySelectorAll("[data-config-pane]")];

    assert.deepEqual(panes.map((pane) => pane.dataset.configPane), ["health", "contacts", "settings_system"]);
    assert.deepEqual(panes.map((pane) => pane.classList.contains("active")), [true, false, false]);
});

test("a secção com uma definição alterada di-lo na sua linha, e as outras calam-se", () => {
    const root = render();
    initDeviceConfigPanel({ els: {} });

    const edited = root.querySelector("[data-config-section][data-config-key=\"step_goal\"]");
    edited.querySelector("[data-config-field]").value = "12000";
    syncConfigCounts(root);

    const [health, contacts] = links(root);
    assert.match(health.textContent.replace(/\s+/g, " "), /2 · 1 alterada/);
    assert.doesNotMatch(contacts.textContent, /alterada/);
});

test("trocar de secção não perde o que está escrito e por enviar", () => {
    const root = render();
    const field = root.querySelector("[data-config-section][data-config-key=\"heart_rate\"] [data-config-field]");
    field.value = "30";

    selectConfigSection(root.querySelector("[data-config-root]"), "contacts");

    const [health, contacts] = [...root.querySelectorAll("[data-config-pane]")];
    assert.equal(health.classList.contains("active"), false);
    assert.equal(contacts.classList.contains("active"), true);
    assert.equal(field.value, "30");
});

test("o separador conta as alterações por enviar de todas as secções", () => {
    const root = render();
    const badge = document.createElement("span");
    badge.className = "d-none";
    initDeviceConfigPanel({ els: { deviceConfigCount: badge } });

    syncConfigCounts(root);
    assert.ok(badge.classList.contains("d-none"), "sem alterações o separador não conta nada");

    root.querySelector("[data-config-section][data-config-key=\"heart_rate\"] [data-config-field]").value = "30";
    root.querySelector("[data-config-section][data-config-key=\"timezone\"] [data-config-field]").value = "60";
    syncConfigCounts(root);

    assert.equal(badge.textContent, "2");
    assert.equal(badge.classList.contains("d-none"), false);
});
