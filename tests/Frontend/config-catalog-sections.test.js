import assert from "node:assert/strict";
import test from "node:test";

import { state } from "../../src/Dashboard/dashboard/core/state.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { parseFragment } from "./support/dom.js";

/**
 * O que sobrevive, em que secção cai, com que rótulo e por que ordem; entra-se pelo
 * `renderDeviceConfigurationRoot` para o teste valer quando as funções mudarem de ficheiro.
 */

const render = (context) => parseFragment(renderDeviceConfigurationRoot({
    configurations: {},
    capabilities: {},
    ...context,
}));

const sectionTabs = (root) =>
    [...root.querySelectorAll("[data-config-section-link]")].map((link) => link.dataset.section);

test.afterEach(() => {
    state.protocols = [];
});

test("native entries of one capability collapse into one card, with their limits added", () => {
    const catalog = ["contact1", "contact2", "contact3"].map((key) => ({
        key,
        capabilityKey: "phonebook",
        command: key,
        input: "json",
        category: "contacts",
        limit: 5,
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
    assert.equal(sections[0].dataset.configLimit, "15");
    assert.equal(sections[0].dataset.configStored, "1");

    assert.equal(
        root.querySelector("[data-config-section-link][data-section=\"contacts\"] [data-config-section-total]").textContent.trim(),
        "1",
    );
});

test("sections come out in the order of the section catalog, not the catalog", () => {
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
    assert.match(
        root.querySelector("[data-config-section-link][data-section=\"settings_system\"]")
            .textContent.replace(/\s+/g, " ").trim(),
        /^Sistema 1\b/,
    );
});

test("what is neither configurable nor requestable drops out, and the requestable enters with no stored state", () => {
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
    // Uma configuração tem as duas leituras desenhadas -- a entrega e o «Alterado» --, e o
    // que se vê enquanto ninguém lhe toca é a da entrega.
    assert.equal(
        root.querySelector("[data-config-section][data-config-key=\"heart_rate\"]")
            .querySelectorAll(".config-when-clean .state-badge").length,
        1,
    );
});

test("a card takes its name from the dictionary, whatever the native key", () => {
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
            section: "reminders",
            sectionLabel: "Lembretes",
            isConfigurable: true,
        }],
    });

    const section = root.querySelector("[data-config-section]");
    assert.equal(section.dataset.configKey, "alarm_clock");

    assert.equal(section.dataset.configInput, "alarm_clock");

    assert.equal(section.querySelector(".fw-semibold").textContent.trim(), "Despertadores");
    assert.deepEqual(sectionTabs(root), ["reminders"]);
});
