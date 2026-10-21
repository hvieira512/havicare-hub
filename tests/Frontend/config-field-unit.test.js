import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";

const { numberField } =
    await import("../../src/Dashboard/dashboard/devices/config/inputs/shared.js");
const { renderConfigSection } =
    await import("../../src/Dashboard/dashboard/devices/config/index.js");

/** A unidade cola-se ao campo: `60` seguido de `min` é uma medida só. */

test("without a unit the field stays as it is, with no wrapper", () => {
    const root = parseFragment(numberField("level", 60));

    assert.equal(root.querySelector(".input-group"), null);
    assert.equal(root.querySelector("input").value, "60");
});

test("with a unit the field goes into a group with the unit beside it", () => {
    const root = parseFragment(numberField("intervalMinutes", 60, { unit: "min" }));

    const group = root.querySelector(".input-group");
    assert.ok(group, "o campo tem de ficar dentro de um `input-group`");
    assert.equal(group.querySelector("input").dataset.configField, "intervalMinutes");
    assert.equal(group.querySelector(".input-group-text").textContent, "min");
});

test("the unit is escaped: it comes from the catalog, not the code", () => {
    const root = parseFragment(numberField("x", 1, { unit: "<b>h</b>" }));

    assert.equal(root.querySelector(".input-group-text").textContent, "<b>h</b>");
});

const unitOf = (protocol, entry) => parseFragment(renderConfigSection(protocol, entry, null))
    .querySelector(".input-group-text")?.textContent.trim();

/** A unidade do dicionário ganha à que se adivinha pelo nome do campo. */
test("the dictionary's unit appears beside the field", () => {
    assert.equal(unitOf("zayata-m228", {
        key: "retrieval_warning",
        capabilityKey: "retrieval_warning",
        command: "retrievalWarning",
        input: "number",
        fields: ["minutes"],
        options: { min: 0, max: 1440 },
    }), "min");
});

test("without a declared unit the field name's unit applies", () => {
    assert.equal(unitOf("wonlex-json", {
        key: "location_reporting_interval",
        capabilityKey: "location_reporting_interval",
        command: "locationInterval",
        input: "number",
        fields: ["intervalSeconds"],
    }), "s");
});

/** Na balança, `unit` é o nome do campo e o que lá está são as escolhas do seletor. */
test("a field called unit does not pass its choices off as a unit", () => {
    assert.equal(unitOf("lefu-torre", {
        key: "weight_unit",
        capabilityKey: "weight_unit",
        command: "weightUnit",
        input: "select",
        fields: ["unit"],
        options: { unit: [{ value: "kg" }, { value: "lb" }], default: "kg" },
    }), undefined);
});
