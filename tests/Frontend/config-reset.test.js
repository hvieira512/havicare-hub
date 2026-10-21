import test from "node:test";
import assert from "node:assert/strict";

import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { captureConfigPristine } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { resetConfigPane } from "../../src/Dashboard/dashboard/devices/config/handlers.js";
import { configSection, parseFragment } from "./support/dom.js";

/**
 * Num grupo de rádios o Repor devolve qual opção está marcada; escrever o valor guardado em
 * todas faria o grupo enviar sempre o mesmo.
 */
function radioPane(pristine) {
    return parseFragment(`
        <section data-config-section data-config-pristine='${pristine}'>
            <input type="radio" name="soundProfile" value="1" data-config-field="mode">
            <input type="radio" name="soundProfile" value="2" data-config-field="mode" checked>
            <input type="radio" name="soundProfile" value="3" data-config-field="mode">
        </section>`);
}

test("reset does not rewrite the value of a radio group's options", () => {
    const root = radioPane(JSON.stringify({ mode: "2" }));

    resetConfigPane(root);

    assert.deepEqual([...root.querySelectorAll("input")].map((input) => input.value), ["1", "2", "3"]);
});

test("reset puts the tick back on the stored option", () => {
    const root = radioPane(JSON.stringify({ mode: "3" }));
    root.querySelector("input[value=\"1\"]").checked = true;

    resetConfigPane(root);

    assert.equal(root.querySelector("input:checked")?.value, "3");
});

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

test("reset restores the hours of the oxygen window", () => {
    const { drawn, restored } = resetAfterEditing(
        { key: "blood_oxygen_window", input: "windowToggle", fields: ["enabled", "range"] },
        { enabled: true, range: "22:00-08:00" },
    );

    assert.deepEqual(restored, drawn);
});

test("reset restores the Wonlex sleep hours", () => {
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
