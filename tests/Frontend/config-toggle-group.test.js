import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
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

test("os interruptores seguidos passam a linhas de um só cartão", () => {
    const root = render(CATALOG);
    const groups = root.querySelectorAll("[data-config-group]");

    assert.equal(groups.length, 1, "os três interruptores deviam dar um grupo");
    assert.equal(groups[0].querySelectorAll("[data-config-row]").length, 3);
});

test("o envio é um só, no rodapé da secção, e não um por definição", () => {
    const root = render(CATALOG);

    assert.equal(root.querySelectorAll("[data-action=\"saveConfigPane\"]").length, 1);
    assert.equal(root.querySelectorAll("[data-action=\"saveConfig\"]").length, 0);
});

test("o que não é interruptor continua a ser cartão", () => {
    const root = render(CATALOG);
    const cards = [...root.querySelectorAll("[data-config-section]")]
        .map((s) => s.dataset.configKey);

    assert.deepEqual(cards, ["auto_vitals_interval"]);
});

/** Uma acção dispara e uma definição guarda-se: não se misturam no mesmo grupo. */
test("uma acção transitória não entra no grupo", () => {
    const root = render([
        ...CATALOG.slice(0, 2),
        { ...toggle("find_device", "Encontrar dispositivo", 50), transient: true, category: "health" },
    ]);

    assert.equal(root.querySelectorAll("[data-config-group] [data-config-row]").length, 2);
    assert.ok(root.querySelector("[data-config-section][data-config-key=\"find_device\"]"));
});

/** Mandar as oito definições de cada vez seriam oito comandos para a pulseira executar. */
test("o grupo envia só as linhas alteradas", () => {
    const group = parseFragment(`
        <div data-config-group>
            <div data-config-row data-config-key="a" data-config-input="toggle" data-config-pristine='{"enabled":true}'>
                <input type="checkbox" data-config-field="enabled" checked>
            </div>
            <div data-config-row data-config-key="b" data-config-input="toggle" data-config-pristine='{"enabled":true}'>
                <input type="checkbox" data-config-field="enabled">
            </div>
        </div>`).firstElementChild;

    assert.deepEqual(changedConfigEntries(group), { b: { enabled: false } });
});

/** Um interruptor sozinho também é uma linha, para a lista não ter dois desenhos. */
test("um interruptor sozinho não volta a ser cartão", () => {
    const root = render([toggle("blood_oxygen_alert", "Alerta de oxigénio no sangue", 10)]);

    assert.equal(root.querySelectorAll("[data-config-group] [data-config-row]").length, 1);
    assert.equal(root.querySelectorAll("[data-config-section]").length, 0);
});
