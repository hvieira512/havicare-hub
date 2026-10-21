import test from "node:test";
import assert from "node:assert/strict";

import {
    renderConfigInputs,
    defaultConfigPayload,
} from "../../src/Dashboard/dashboard/devices/config/index.js";
import { parseFragment } from "./support/dom.js";

/** Um horário de silêncio pré-preenchido recusaria chamadas a quem nunca o pediu. */
test("a «Não perturbar» never saved opens without time slots", () => {
    const entry = { key: "doNotDisturb", input: "timeRanges", fields: ["ranges"], limit: 4 };

    assert.deepEqual(defaultConfigPayload(entry, "four-p-touch"), { ranges: [] });
});

test("the automatic measurement interval respects the declared minimum", () => {
    const entry = {
        key: "healthAutoMeasurement",
        input: "intervalToggle",
        fields: ["enabled", "intervalMinutes"],
        options: { min: 5 },
    };
    const root = parseFragment(renderConfigInputs(entry, { enabled: true, intervalMinutes: 60 }));

    assert.equal(root.querySelector("[data-config-field='intervalMinutes']").getAttribute("min"), "5");
});
