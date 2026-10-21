import assert from "node:assert/strict";
import test from "node:test";

import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { changedConfigEntries } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { parseFragment } from "./support/dom.js";

/**
 * Um interruptor não justifica um cartão: as dez definições da MF91 são um bit cada. O cartão
 * fica para o que é rico, como alarmes, agendas e listas.
 */
const toggle = (key, label, order) => ({
    key,
    capabilityKey: key,
    command: `config:${key}`,
    label,
    input: "toggle",
    fields: ["enabled"],
    category: "health",
    order,
});

const CATALOG = [
    toggle("heart_rate_continuous", "Frequência cardíaca contínua", 10),
    toggle("blood_pressure_trend", "Tendência da pressão arterial", 20),
    toggle("temperature_continuous", "Temperatura contínua", 30),
    {
        key: "auto_vitals_interval",
        capabilityKey: "auto_vitals_interval",
        command: "HEALTHAUTOSET",
        label: "Medição automática de saúde",
        input: "intervalToggle",
        fields: ["enabled", "minutes"],
        category: "health",
        order: 40,
    },
];

// O catálogo de capacidades é o que dá secção a cada definição; sem ele nenhuma é desenhada.
const capabilityCatalog = (catalog) => catalog.map((entry) => ({
    key: entry.capabilityKey,
    section: entry.category,
    sectionLabel: "Saúde",
    isConfigurable: entry.transient !== true,
    isRequestable: entry.transient === true,
}));

const render = (catalog) => parseFragment(renderDeviceConfigurationRoot({
    protocol: "veepoo-ble",
    catalog,
    configurations: {},
    capabilities: {},
    capabilityCatalog: capabilityCatalog(catalog),
}));

test("consecutive switches become rows of one card", () => {
    const root = render(CATALOG);
    const groups = root.querySelectorAll("[data-config-group]");

    assert.equal(groups.length, 1, "os três interruptores deviam dar um grupo");
    assert.equal(groups[0].querySelectorAll("[data-config-row]").length, 3);
});

test("there is one send, in the section footer, not one per setting", () => {
    const root = render(CATALOG);

    assert.equal(root.querySelectorAll("[data-action=\"saveConfigPane\"]").length, 1);
    assert.equal(root.querySelectorAll("[data-action=\"saveConfig\"]").length, 0);
});

test("what is not a switch stays a card", () => {
    const root = render(CATALOG);
    const cards = [...root.querySelectorAll("[data-config-section]")]
        .map((s) => s.dataset.configKey);

    assert.deepEqual(cards, ["auto_vitals_interval"]);
});

/** Uma acção dispara e uma definição guarda-se: não se misturam no mesmo grupo. */
test("a transient action does not join the group", () => {
    const root = render([
        ...CATALOG.slice(0, 2),
        { ...toggle("find_device", "Encontrar dispositivo", 50), transient: true, category: "health" },
    ]);

    assert.equal(root.querySelectorAll("[data-config-group] [data-config-row]").length, 2);
    assert.ok(root.querySelector("[data-config-section][data-config-key=\"find_device\"]"));
});

/** Um interruptor sozinho também é uma linha, para a lista não ter dois desenhos. */
test("a lone switch does not become a card again", () => {
    const root = render([toggle("blood_oxygen_alert", "Alerta de oxigénio no sangue", 10)]);

    assert.equal(root.querySelectorAll("[data-config-group] [data-config-row]").length, 1);
    assert.equal(root.querySelectorAll("[data-config-section]").length, 0);
});

/**
 * A toma antecipada e o bloqueio de criança do dispensador declaram ambos o campo `enabled` e
 * partilham um «Enviar»: cada linha tem de enviar o **seu** valor.
 */

const toggleGroup = (rows) => parseFragment(`
    <section data-config-group data-config-protocol="zayata-m228">
        ${rows.map(({ key, on, stored }) => `
            <div data-config-row data-config-key="${key}" data-config-input="toggle"
                 data-config-stored="${stored ? "1" : "0"}"
                 data-config-pristine='${JSON.stringify({ enabled: stored })}'>
                <input type="checkbox" role="switch" data-config-field="enabled" ${on ? "checked" : ""}>
            </div>`).join("")}
    </section>`).querySelector("[data-config-group]");

test("turning one switch off sends that one, not its neighbour", () => {
    const group = toggleGroup([
        { key: "early_dispense", on: true, stored: true },
        { key: "child_lock", on: false, stored: true },
    ]);

    assert.deepEqual(changedConfigEntries(group), {
        child_lock: { enabled: false },
    });
});

test("with both changed, each carries its own value", () => {
    const group = toggleGroup([
        { key: "early_dispense", on: false, stored: true },
        { key: "child_lock", on: true, stored: false },
    ]);

    assert.deepEqual(changedConfigEntries(group), {
        early_dispense: { enabled: false },
        child_lock: { enabled: true },
    });
});

test("without changes nothing is sent", () => {
    const group = toggleGroup([
        { key: "early_dispense", on: true, stored: true },
        { key: "child_lock", on: true, stored: true },
    ]);

    assert.deepEqual(changedConfigEntries(group), {});
});
