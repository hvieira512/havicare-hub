import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { captureConfigPristine } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import {
    handleDeviceConfigReset,
    resetConfigPane,
} from "../../src/Dashboard/dashboard/devices/config/handlers.js";
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

test("o Repor não reescreve o valor das opções de um grupo de rádios", () => {
    const root = radioPane(JSON.stringify({ mode: "2" }));

    resetConfigPane(root);

    assert.deepEqual([...root.querySelectorAll("input")].map((input) => input.value), ["1", "2", "3"]);
});

test("o Repor devolve a marca à opção que estava guardada", () => {
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

/** O `type="reset"` devolve os campos sem disparar `change`, e a etiqueta tem de acompanhar. */
test("depois do Repor a etiqueta do interruptor diz o que ele mostra", async () => {
    const entry = { input: "alarm_clock", key: "alarm_clock", fields: ["masterEnabled", "items"] };
    const section = configSection(renderConfigInputs, entry, { masterEnabled: true }, {});
    const form = document.createElement("form");
    section.parentNode?.insertBefore(form, section);
    form.appendChild(section);
    form.addEventListener("reset", handleDeviceConfigReset);

    const input = section.querySelector("[data-alarm-clock-field=\"masterEnabled\"]");
    const label = input.parentElement.querySelector("[data-switch-label]");

    input.checked = false;
    input.dispatchEvent(new window.Event("change", { bubbles: true }));

    form.reset();
    await Promise.resolve();

    assert.equal(input.checked, true, "o Repor devolveu o interruptor ao inicial");
    assert.equal(label.textContent, "Lembretes ligados", "e a etiqueta acompanhou");
});
