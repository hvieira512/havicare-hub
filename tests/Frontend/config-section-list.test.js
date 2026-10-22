import assert from "node:assert/strict";
import test from "node:test";

import { parseFragment } from "./support/dom.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import {
    captureConfigPristine,
    syncConfigCounts,
} from "../../src/Dashboard/dashboard/devices/config/panel.js";
import {
    handleDeviceConfigClick,
    selectConfigSection,
} from "../../src/Dashboard/dashboard/devices/config/handlers.js";
import { blankDeviceModal, state } from "../../src/Dashboard/dashboard/state.js";
import { cacheElements } from "../../src/Dashboard/dashboard/dom.js";

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

test("each row shows the label and how many settings it has", () => {
    const health = links(render())[0];

    assert.match(health.textContent, /Saúde/);
    assert.equal(health.querySelector("[data-config-section-total]").textContent.trim(), "2");
});

test("each section has its panel, and only the active one is open", () => {
    const root = render();
    const panes = [...root.querySelectorAll("[data-config-pane]")];

    assert.deepEqual(panes.map((pane) => pane.dataset.configPane), ["health", "contacts", "settings_system"]);
    assert.deepEqual(panes.map((pane) => pane.classList.contains("active")), [true, false, false]);
});

test("the section with an edited setting says so in its row, and the others stay quiet", () => {
    const root = render();
    cacheElements({});

    const edited = root.querySelector("[data-config-section][data-config-key=\"step_goal\"]");
    edited.querySelector("[data-config-field]").value = "12000";
    syncConfigCounts(root);

    const [health, contacts] = links(root);
    assert.match(health.textContent.replace(/\s+/g, " "), /2 · 1 alterada/);
    assert.doesNotMatch(contacts.textContent, /alterada/);
});

test("switching section keeps what is typed and unsent", () => {
    const root = render();
    const field = root.querySelector("[data-config-section][data-config-key=\"heart_rate\"] [data-config-field]");
    field.value = "30";

    selectConfigSection(root.querySelector("[data-config-root]"), "contacts");

    const [health, contacts] = [...root.querySelectorAll("[data-config-pane]")];
    assert.equal(health.classList.contains("active"), false);
    assert.equal(contacts.classList.contains("active"), true);
    assert.equal(field.value, "30");
});

test("the tab counts the unsent changes of every section", () => {
    const root = render();
    const badge = document.createElement("span");
    badge.className = "d-none";
    cacheElements({ deviceConfigCount: badge });

    syncConfigCounts(root);
    assert.ok(badge.classList.contains("d-none"), "sem alterações o separador não conta nada");

    root.querySelector("[data-config-section][data-config-key=\"heart_rate\"] [data-config-field]").value = "30";
    root.querySelector("[data-config-section][data-config-key=\"timezone\"] [data-config-field]").value = "60";
    syncConfigCounts(root);

    assert.equal(badge.textContent, "2");
    assert.equal(badge.classList.contains("d-none"), false);
});

const click = (target) => handleDeviceConfigClick({ target, preventDefault() {} });

/** No telemóvel a lista e as definições não cabem juntas: escolher uma secção troca uma pela outra. */
test("choosing a section opens it, and back returns to the list keeping what was typed", () => {
    state.deviceModal = blankDeviceModal();
    const root = render();
    const configRoot = root.querySelector("[data-config-root]");
    const field = root.querySelector("[data-config-section][data-config-key=\"phonebook\"] [data-config-field]");

    click(links(root)[1]);
    assert.equal(configRoot.hasAttribute("data-config-section-open"), true);
    assert.equal(state.deviceModal.configSectionOpen, true);

    field.value = "3";
    click(root.querySelector("[data-config-pane=\"contacts\"] [data-action=\"closeConfigSection\"]"));
    assert.equal(configRoot.hasAttribute("data-config-section-open"), false);
    assert.equal(state.deviceModal.configSectionOpen, false);
    assert.equal(field.value, "3");
});

test("a redraw keeps the open section open", () => {
    const html = renderDeviceConfigurationRoot({
        protocol: "veepoo-ble",
        catalog: [entry("heart_rate", "health", "interval")],
        capabilityCatalog: CAPABILITIES,
        configurations: { heart_rate: { interval: 60 } },
        sectionOpen: true,
    });

    assert.match(html, /data-config-section-open/);
});
