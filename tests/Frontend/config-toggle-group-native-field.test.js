import assert from "node:assert/strict";
import test from "node:test";

import "./support/browser-env.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { parseFragment } from "./support/dom.js";

/**
 * O nome do campo na definição é o nativo; o valor guardado chega com o nome do contrato.
 *
 * A Wonlex declara `fields: ["switchState"]` e o `GenericCapability::wonlexFromNative`
 * entrega `{enabled: false}` -- o `switchState` já não existe no valor. A linha do grupo
 * procurava o nome nativo, não encontrava nada, e desenhava o interruptor ligado. Sete
 * definições Wonlex passam por aqui, e uma delas é a deteção de queda.
 */
const toggle = (key, label, order) => ({
    key,
    capabilityKey: key,
    command: "deviceConfig",
    label,
    input: "toggle",
    fields: ["switchState"],
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

test("um interruptor guardado como desligado desenha-se desligado", () => {
    const root = render({
        wonlexContinuousHRSwitch: { enabled: false },
        wonlexContinuousTempSwitch: { enabled: true },
    });

    const [heartRate, temperature] = rowsOf(root);
    assert.equal(heartRate.checked, false, "está desligado no hub");
    assert.equal(temperature.checked, true);
});

/** A fotografia tem de bater certo com o desenho, ou o rodapé conta alterações que não há. */
test("a fotografia do valor acompanha o que o interruptor mostra", () => {
    const [heartRate] = rowsOf(render({
        wonlexContinuousHRSwitch: { enabled: false },
    }));

    assert.equal(JSON.parse(heartRate.pristine).enabled, false);
});

/** Sem valor guardado o interruptor nasce ligado, que é o que o aparelho traz de fábrica. */
test("sem valor guardado o interruptor fica ligado", () => {
    const [heartRate] = rowsOf(render({}));

    assert.equal(heartRate.checked, true);
});

/** Um zero é desligado tanto como um `false` -- a Wonlex manda os dois. */
test("um zero guardado lê-se como desligado", () => {
    const [heartRate] = rowsOf(render({
        wonlexContinuousHRSwitch: { enabled: 0 },
    }));

    assert.equal(heartRate.checked, false);
});
