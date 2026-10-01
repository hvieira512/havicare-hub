import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

import "./support/browser-env.js";
import { fieldLabel, fieldUnit, fieldValue } from "../../src/Dashboard/dashboard/format.js";

/**
 * A unidade de um campo diz-se num sítio só.
 *
 * O `FIELD_UNIT` cola-a ao valor e serve a telemetria; a unidade entre parênteses na etiqueta
 * serve os campos de configuração, cujo rótulo é escondido e de onde o `fieldUnit` a tira.
 * Um campo nas duas convenções escreve-a duas vezes; um campo em nenhuma obriga quem lê a
 * adivinhar se são metros ou quilómetros.
 */
const source = readFileSync(
    new URL("../../src/Dashboard/dashboard/format.js", import.meta.url),
    "utf8",
);

/** As chaves declaradas no `FIELD_UNIT`, lidas do próprio ficheiro. */
function unitKeys() {
    const block = source.slice(source.indexOf("const FIELD_UNIT = {"));
    return new Set([...block.slice(0, block.indexOf("};")).matchAll(/(\w+):/g)].map((m) => m[1]));
}

/** Cada etiqueta declarada, chave e texto. */
function labelEntries() {
    return [...source.matchAll(/^\s+(\w+): "([^"]+)",$/gm)]
        .map((m) => [m[1], m[2]])
        .filter(([key]) => fieldLabel(key) !== key);
}

/** As chaves cujo nome já traz a unidade, pela convenção do contrato MQTT. */
const UNIT_SUFFIX =
    /(Seconds|Minutes|Hours|Meters|Km|Kg|Percent|Celsius|Dbm|MmHg|Milliseconds|Hz|Bpm|Mg|Kcal|TimeS)$/;

test("nenhum campo declara a unidade nas duas convenções", () => {
    const units = unitKeys();
    const doubled = labelEntries()
        .filter(([key, label]) => units.has(key) && /\([^)]+\)\s*$/.test(label))
        .map(([key, label]) => `${key} ("${label}" + "${fieldValue(key, 1)}")`);

    assert.deepEqual(doubled, []);
});

test("um campo cuja chave traz a unidade diz a unidade", () => {
    const units = unitKeys();
    const silent = labelEntries()
        .filter(([key]) => UNIT_SUFFIX.test(key))
        .filter(([key]) => !units.has(key) && fieldUnit(key) === "")
        .map(([key, label]) => `${key} ("${label}")`);

    assert.deepEqual(silent, []);
});

test("o exercício e o tempo em pé dizem a unidade uma vez", () => {
    assert.equal(fieldLabel("exerciseSeconds"), "Exercício");
    assert.equal(fieldValue("exerciseSeconds", 30), "30 s");
    assert.equal(fieldLabel("standMinutes"), "Tempo em pé");
    assert.equal(fieldValue("standMinutes", 12), "12 min");
});

test("os eixos do movimento e os tempos do radar dizem a unidade", () => {
    assert.equal(fieldValue("xMg", -12), "-12 mg");
    assert.equal(fieldValue("walkingTimeS", 240), "240 s");
});

/** Os campos de configuração guardam a unidade na etiqueta, que é de onde o painel a tira. */
test("os campos de configuração mantêm a unidade entre parênteses", () => {
    assert.equal(fieldUnit("intervalTime"), "s");
    assert.equal(fieldUnit("sleepTarget"), "min");
});
