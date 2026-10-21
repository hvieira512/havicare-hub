import assert from "node:assert/strict";
import test from "node:test";

import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { changedConfigEntries } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { parseFragment } from "./support/dom.js";

/** Os interruptores Wonlex em linhas de um cartão: a definição e o valor falam `enabled`. */
const toggle = (key, label, order) => ({
    key,
    capabilityKey: key,
    command: "deviceConfig",
    label,
    input: "toggle",
    fields: ["enabled"],
    category: "health",
    order,
});

const CATALOG = [
    toggle("wonlexContinuousHRSwitch", "Frequência cardíaca contínua", 30),
    toggle("wonlexContinuousTempSwitch", "Temperatura automática", 50),
];

const capabilityCatalog = (catalog) => catalog.map((entry) => ({
    key: entry.capabilityKey,
    section: entry.category,
    sectionLabel: "Saúde",
    isConfigurable: true,
    isRequestable: false,
}));

const render = (configurations) => parseFragment(renderDeviceConfigurationRoot({
    protocol: "wonlex-json",
    catalog: CATALOG,
    configurations,
    capabilities: {},
    capabilityCatalog: capabilityCatalog(CATALOG),
}));

const rowsOf = (root) => [...root.querySelectorAll("[data-config-row]")].map((row) => ({
    key: row.dataset.configKey,
    checked: row.querySelector("input[type=checkbox]").checked,
    pristine: row.dataset.configPristine,
}));

test("a switch stored as off is drawn off", () => {
    const root = render({
        wonlexContinuousHRSwitch: { enabled: false },
        wonlexContinuousTempSwitch: { enabled: true },
    });

    const [heartRate, temperature] = rowsOf(root);
    assert.equal(heartRate.checked, false, "está desligado no hub");
    assert.equal(temperature.checked, true);
});

/** A fotografia tem de bater certo com o desenho, ou o rodapé conta alterações que não há. */
test("the value snapshot follows what the switch shows", () => {
    const [heartRate] = rowsOf(render({
        wonlexContinuousHRSwitch: { enabled: false },
    }));

    assert.equal(JSON.parse(heartRate.pristine).enabled, false);
});

/** Sem valor guardado o interruptor nasce ligado, que é o que o aparelho traz de fábrica. */
test("without a stored value the switch is on", () => {
    const [heartRate] = rowsOf(render({}));

    assert.equal(heartRate.checked, true);
});

/** «Restringir chamadas recebidas» é um interruptor como os outros: uma linha, sem legenda de recurso. */
test("the call restriction switch fits on one line and reads back", () => {
    const entry = {
        ...toggle("whitelist_enabled", "Restringir chamadas recebidas", 10),
        category: "contacts",
    };
    const root = parseFragment(renderDeviceConfigurationRoot({
        protocol: "wonlex-json",
        catalog: [entry],
        configurations: { whitelist_enabled: { enabled: true } },
        capabilities: {},
        capabilityCatalog: capabilityCatalog([entry]),
    }));

    assert.equal(root.querySelectorAll("[data-config-row]").length, 1);
    assert.equal(root.querySelectorAll("[data-config-section]").length, 0);
    assert.doesNotMatch(root.textContent, /ativa ou desativa/);

    root.querySelector("[data-config-row] input[type=checkbox]").checked = false;
    assert.deepEqual(changedConfigEntries(root.querySelector("[data-config-group]")), {
        whitelist_enabled: { enabled: false },
    });
});

/** Um zero é desligado tanto como um `false` -- a Wonlex manda os dois. */
test("a stored zero reads as off", () => {
    const [heartRate] = rowsOf(render({
        wonlexContinuousHRSwitch: { enabled: 0 },
    }));

    assert.equal(heartRate.checked, false);
});
