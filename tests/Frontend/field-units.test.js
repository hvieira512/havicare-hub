import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

import { fieldLabel, fieldUnit, fieldValue } from "../../src/Dashboard/dashboard/format.js";

/**
 * A unidade diz-se num sítio só: o `FIELD_UNIT` cola-a ao valor na telemetria, e os parênteses
 * na etiqueta servem os campos de configuração, cujo rótulo é escondido.
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

function labelEntries() {
    return [...source.matchAll(/^\s+(\w+): "([^"]+)",$/gm)]
        .map((m) => [m[1], m[2]])
        .filter(([key]) => fieldLabel(key) !== key);
}

/** As chaves cujo nome já traz a unidade, pela convenção do contrato MQTT. */
const UNIT_SUFFIX =
    /(Seconds|Minutes|Hours|Meters|Km|Kg|Percent|Celsius|Dbm|MmHg|Milliseconds|Hz|Bpm|Mg|Kcal|TimeS)$/;

test("no field declares its unit in both conventions", () => {
    const units = unitKeys();
    const doubled = labelEntries()
        .filter(([key, label]) => units.has(key) && /\([^)]+\)\s*$/.test(label))
        .map(([key, label]) => `${key} ("${label}" + "${fieldValue(key, 1)}")`);

    assert.deepEqual(doubled, []);
});

test("a field whose key carries the unit shows the unit", () => {
    const units = unitKeys();
    const silent = labelEntries()
        .filter(([key]) => UNIT_SUFFIX.test(key))
        .filter(([key]) => !units.has(key) && fieldUnit(key) === "")
        .map(([key, label]) => `${key} ("${label}")`);

    assert.deepEqual(silent, []);
});

/** Os campos de configuração dizem a unidade no nome, e o painel tira-a de lá. */
test("configuration fields carry their unit in the name", () => {
    assert.equal(fieldUnit("intervalSeconds"), "s");
    assert.equal(fieldUnit("targetMinutes"), "min");
});

test("a card's main value writes decimals with the Portuguese comma", async () => {
    const { uplinkCardContent } = await import("../../src/Dashboard/dashboard/components/cards/telemetry.js");

    assert.equal(String(uplinkCardContent("weight", { weightKg: 96.1 }).value), "96,1 kg");
    assert.equal(String(uplinkCardContent("temperature", { bodyCelsius: 36.5 }).value), "36,5 °C");
    assert.equal(String(uplinkCardContent("body_impedance", { impedance50KhzOhm: 425.2 }).value), "425,2 Ω");
});
