import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";

/**
 * O campo de escolha genérico.
 *
 * Cada fornecedor tinha o seu -- a sensibilidade de queda da Vivistar, a do 4P Touch -- e
 * todos faziam a mesma coisa: desenhar as opções que a definição declara. Sem um genérico,
 * uma definição com `options` e sem campo próprio caía num número solto, e o utilizador via
 * "2" sem saber que 2 é "Baixo".
 */
const select = CONFIG_INPUTS.select;

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

test("o campo de escolha existe como genérico", () => {
    assert.ok(select, "o `select` tem de estar registado nos campos genéricos");
    assert.equal(typeof select.render, "function");
    assert.equal(typeof select.read, "function");
    assert.equal(typeof select.defaults, "function");
});

test("desenha cada opção com o seu rótulo, e não o número cru", () => {
    const html = select.render(entry("volume", VOLUME), { volume: 2 });

    assert.match(html, /<select[^>]*data-config-field="volume"/);
    for (const { label } of VOLUME) {
        assert.match(html, new RegExp(`>${label}<`));
    }
});

test("a opção guardada vem escolhida", () => {
    const html = select.render(entry("volume", VOLUME), { volume: 2 });

    assert.match(html, /value="2"\s+selected/);
    assert.doesNotMatch(html, /value="0"\s+selected/);
});

test("sem valor guardado escolhe a primeira opção, que é o que o formulário mostra", () => {
    assert.deepEqual(select.defaults(entry("volume", VOLUME)), { volume: 0 });
});

test("a definição pode dizer de onde parte, e não a ponta da lista", () => {
    // Os fusos horários vão de −12 a +14 por ordem de valor: partir da primeira opção
    // punha toda a gente em UTC−12:00.
    const zones = entry("timeZone", [
        { value: -1200, label: "UTC−12:00" },
        { value: 0, label: "UTC+00:00" },
        { value: 100, label: "UTC+01:00" },
    ]);
    zones.options.default = 0;

    assert.deepEqual(select.defaults(zones), { timeZone: 0 });
    assert.match(select.render(zones, {}), /value="0"\s+selected/);
});

test("não repete o rótulo que o cartão já mostra", () => {
    const html = select.render(entry("volume", VOLUME), { volume: 1 });

    assert.doesNotMatch(html, /<label/);
});

test("uma definição sem opções não rebenta", () => {
    const html = select.render({ fields: ["volume"] }, {});

    assert.match(html, /<select/);
});
