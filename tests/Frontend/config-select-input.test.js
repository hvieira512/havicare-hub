import test from "node:test";
import assert from "node:assert/strict";

import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";
import { asText } from "./support/dom.js";

/**
 * O campo de escolha genérico desenha as opções que a definição declara, para o utilizador ver
 * «Baixo» e não `2`.
 */
const select = {
    ...CONFIG_INPUTS.select,
    control: asText(CONFIG_INPUTS.select.control),
};

const entry = (field, options, extra = {}) => ({
    fields: [field],
    options: { [field]: options },
    ...extra,
});

const VOLUME = [
    { value: 0, label: "Alto" },
    { value: 1, label: "Médio" },
    { value: 2, label: "Baixo" },
    { value: 3, label: "Silêncio" },
];

test("draws each option with its label, not the raw number", () => {
    const html = select.control(entry("volume", VOLUME), { volume: 2 });

    assert.match(html, /<select[^>]*data-config-field="volume"/);
    for (const { label } of VOLUME) {
        assert.match(html, new RegExp(`>${label}<`));
    }
});

test("the stored option comes selected", () => {
    const html = select.control(entry("volume", VOLUME), { volume: 2 });

    assert.match(html, /value="2"\s+selected/);
    assert.doesNotMatch(html, /value="0"\s+selected/);
});

test("without a stored value the first option is chosen, which is what the form shows", () => {
    assert.deepEqual(select.defaults(entry("volume", VOLUME)), { volume: 0 });
});

test("the setting can say where it starts, rather than the end of the list", () => {
    // Os fusos vão de −12 a +14 por ordem de valor: partir da primeira opção daria UTC−12:00.
    const zones = entry("timeZone", [
        { value: -1200, label: "UTC−12:00" },
        { value: 0, label: "UTC+00:00" },
        { value: 100, label: "UTC+01:00" },
    ]);
    zones.options.default = 0;

    assert.deepEqual(select.defaults(zones), { timeZone: 0 });
    assert.match(select.control(zones, {}), /value="0"\s+selected/);
});

test("a setting without options does not throw", () => {
    const html = select.control({ fields: ["volume"] }, {});

    assert.match(html, /<select/);
});
