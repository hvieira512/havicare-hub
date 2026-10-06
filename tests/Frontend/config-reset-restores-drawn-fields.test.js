import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { captureConfigPristine } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { resetConfigPane } from "../../src/Dashboard/dashboard/devices/config/handlers.js";
import { configSection } from "./support/dom.js";

/** Os campos que se desenham com outro nome ou outro formato que o valor guardado. */
function resetAfterEditing(entry, desired, meta = {}) {
    const section = configSection(renderConfigInputs, entry, desired, meta);
    section.setAttribute("data-config-section", "");
    const root = section.parentElement;
    const fields = () => [...section.querySelectorAll("[data-config-field]")]
        .map((input) => (input.type === "checkbox" ? input.checked : input.value));
    captureConfigPristine(root);
    const drawn = fields();

    for (const input of section.querySelectorAll("[data-config-field]")) {
        if (input.type === "checkbox") input.checked = !input.checked;
        else input.value = "";
    }
    resetConfigPane(root);

    return { drawn, restored: fields() };
}

test("o Repor devolve as horas da janela de oxigénio", () => {
    const { drawn, restored } = resetAfterEditing(
        { key: "blood_oxygen_window", input: "windowToggle", fields: ["enabled", "range"] },
        { enabled: true, range: "22:00-08:00" },
    );

    assert.deepEqual(restored, drawn);
});

test("o Repor devolve as horas do sono da Wonlex", () => {
    const { drawn, restored } = resetAfterEditing(
        {
            key: "wonlexSleepIntervalOrSwitch",
            input: "wonlexSleepSettings",
            fields: ["switchState", "sleepStartTime", "sleepEndTime", "sleepTarget"],
        },
        { switchState: true, sleepStartTime: "220000", sleepEndTime: "070000", sleepTarget: 480 },
        { protocol: "wonlex-json" },
    );

    assert.deepEqual(restored, drawn);
});
