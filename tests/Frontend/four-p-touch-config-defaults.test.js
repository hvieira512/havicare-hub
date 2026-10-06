import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import {
    renderConfigInputs,
    defaultConfigPayload,
} from "../../src/Dashboard/dashboard/devices/config/index.js";
import { parseFragment } from "./support/dom.js";

/** Um horário de silêncio pré-preenchido recusava chamadas a quem nunca o pediu. */
test("o «Não perturbar» que nunca foi guardado abre sem horários", () => {
    const entry = { key: "doNotDisturb", input: "timeRanges", fields: ["ranges"], limit: 4 };

    assert.deepEqual(defaultConfigPayload(entry, "four-p-touch"), { ranges: [] });
});

test("o intervalo da medição automática respeita o mínimo declarado", () => {
    const entry = {
        key: "healthAutoMeasurement",
        input: "intervalToggle",
        fields: ["enabled", "intervalMinutes"],
        options: { min: 5 },
    };
    const root = parseFragment(renderConfigInputs(entry, { enabled: true, intervalMinutes: 60 }));

    assert.equal(root.querySelector("[data-config-field='intervalMinutes']").getAttribute("min"), "5");
});
