import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { state } from "../../src/Dashboard/dashboard/state.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { parseFragment } from "./support/dom.js";

/**
 * O modelo do catálogo: o que sobrevive, em que secção cai, com que rótulo e por que ordem.
 *
 * Entra-se pela porta pública -- o `renderDeviceConfigurationRoot` --, e não pelas funções do
 * modelo: é assim que o teste continua a valer depois de elas mudarem de ficheiro.
 */

const render = (context) => parseFragment(renderDeviceConfigurationRoot({
    configurations: {},
    capabilities: {},
    ...context,
}));

const sectionTabs = (root) =>
    [...root.querySelectorAll("[data-config-category]")].map((tab) => tab.dataset.configCategory);

test.afterEach(() => {
    state.protocols = [];
});

test("as capacidades agrupadas colapsam as chaves nativas numa entrada só", () => {
    state.protocols = [{
        protocol: "wonlex-json",
        dashboard: { groupedCapabilities: { phonebook: { label: "Contactos", limit: 10 } } },
    }];

    const catalog = ["contact1", "contact2", "contact3"].map((key) => ({
        key,
        capabilityKey: "phonebook",
        command: key,
        label: key,
        input: "json",
        category: "contacts",
    }));

    const root = render({
        protocol: "wonlex-json",
        catalog,
        // A terceira chave nativa é a que tem valor guardado: só chega ao cartão se o
        // `configKeys` tiver as três, e não apenas a primeira.
        configurations: { contact3: { name: "Ana", phone: "910000000" } },
        capabilityCatalog: [{
            key: "phonebook",
            section: "contacts",
            sectionLabel: "Contactos",
            isConfigurable: true,
        }],
    });

    const sections = root.querySelectorAll("[data-config-section]");
    assert.equal(sections.length, 1);
    assert.equal(sections[0].dataset.configKey, "phonebook");
    assert.equal(sections[0].dataset.configLimit, "10");
    assert.equal(sections[0].dataset.configStored, "1");
    assert.equal(sections[0].dataset.configSectionName, "contacts");
    assert.equal(
        root.querySelector("[data-config-category=\"contacts\"] .badge").textContent.trim(),
        "1",
    );
});

test("as secções saem pela ordem do catálogo de secções, e não pela do catálogo", () => {
    const entry = (key, section) => ({
        key,
        capabilityKey: key,
        command: key,
        label: key,
        input: "json",
        category: section,
    });

    const root = render({
        protocol: "veepoo-ble",
        catalog: [
            entry("timezone", "settings_system"),
            entry("heart_rate", "health"),
            entry("phonebook", "contacts"),
        ],
        capabilityCatalog: [
            { key: "timezone", section: "settings_system", isConfigurable: true },
            { key: "heart_rate", section: "health", sectionLabel: "Saúde", isConfigurable: true },
            { key: "phonebook", section: "contacts", sectionLabel: "Contactos", isConfigurable: true },
        ],
    });

    assert.deepEqual(sectionTabs(root), ["health", "contacts", "settings_system"]);
    // Sem `sectionLabel` na definição, o separador fica com o nome cru da secção.
    assert.match(
        root.querySelector("[data-config-category=\"settings_system\"]")
            .textContent.replace(/\s+/g, " ").trim(),
        /^settings_system 1$/,
    );
});

test("quem não é configurável nem pedível cai, e o pedível entra sem estado guardado", () => {
    const entry = (key) => ({
        key,
        capabilityKey: key,
        command: key,
        label: key,
        input: "json",
        category: "health",
    });

    const root = render({
        protocol: "veepoo-ble",
        catalog: [entry("heart_rate"), entry("firmware_version"), entry("find_device")],
        capabilityCatalog: [
            { key: "heart_rate", section: "health", sectionLabel: "Saúde", isConfigurable: true },
            { key: "firmware_version", section: "health", sectionLabel: "Saúde" },
            { key: "find_device", section: "health", sectionLabel: "Saúde", isRequestable: true },
        ],
    });

    const keys = [...root.querySelectorAll("[data-config-section]")]
        .map((section) => section.dataset.configKey);
    assert.deepEqual(keys, ["heart_rate", "find_device"]);

    // Uma acção não guarda valor: sem entrega por mostrar, não tem pastilha de estado.
    const action = root.querySelector("[data-config-section][data-config-key=\"find_device\"]");
    assert.equal(action.querySelectorAll(".state-badge").length, 0);
    assert.equal(
        root.querySelector("[data-config-section][data-config-key=\"heart_rate\"]")
            .querySelectorAll(".state-badge").length,
        1,
    );
});

test("o alarm_clock traz rótulo, espécie e secção próprios, seja qual for o catálogo", () => {
    const root = render({
        protocol: "veepoo-ble",
        catalog: [{
            key: "alarm1",
            capabilityKey: "alarm_clock",
            command: "SETALARM",
            label: "Despertador 1",
            input: "alarm_clock",
            category: "health",
        }],
        capabilityCatalog: [{
            key: "alarm_clock",
            section: "alarms",
            sectionLabel: "Alarmes",
            isConfigurable: true,
        }],
    });

    const section = root.querySelector("[data-config-section]");
    assert.equal(section.dataset.configKey, "alarm_clock");
    assert.equal(section.dataset.configKind, "capability");
    assert.equal(section.dataset.configInput, "alarm_clock");
    assert.equal(section.dataset.configSectionName, "alarms");
    assert.equal(section.querySelector(".fw-semibold").textContent.trim(), "Alarmes");
    assert.deepEqual(sectionTabs(root), ["alarms"]);
});
