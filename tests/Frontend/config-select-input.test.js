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

test("desenha cada opção com o seu rótulo, e não o número cru", () => {
    const html = select.control(entry("volume", VOLUME), { volume: 2 });

    assert.match(html, /<select[^>]*data-config-field="volume"/);
    for (const { label } of VOLUME) {
        assert.match(html, new RegExp(`>${label}<`));
    }
});

test("a opção guardada vem escolhida", () => {
    const html = select.control(entry("volume", VOLUME), { volume: 2 });

    assert.match(html, /value="2"\s+selected/);
    assert.doesNotMatch(html, /value="0"\s+selected/);
});

test("sem valor guardado escolhe a primeira opção, que é o que o formulário mostra", () => {
    assert.deepEqual(select.defaults(entry("volume", VOLUME)), { volume: 0 });
});

test("a definição pode dizer de onde parte, e não a ponta da lista", () => {
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

test("uma definição sem opções não rebenta", () => {
    const html = select.control({ fields: ["volume"] }, {});

    assert.match(html, /<select/);
});
