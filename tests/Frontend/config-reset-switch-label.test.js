import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { handleDeviceConfigReset } from "../../src/Dashboard/dashboard/devices/config/handlers.js";
import { configSection } from "./support/dom.js";

/**
 * O «Repor» é o `type="reset"` do formulário do bloco: o browser devolve os campos ao estado
 * inicial sem disparar `change`, e a etiqueta do interruptor ficava a dizer o contrário do
 * que ele mostra.
 */
const ENTRY = { input: "alarm_clock", key: "alarm_clock", fields: ["masterEnabled", "items"] };

function mounted() {
    const section = configSection(renderConfigInputs, ENTRY, { masterEnabled: true }, {});
    // O `configSection` monta a secção; o `reset` precisa de um formulário à volta do campo.
    const form = document.createElement("form");
    section.parentNode?.insertBefore(form, section);
    form.appendChild(section);
    form.addEventListener("reset", handleDeviceConfigReset);

    return { form, section };
}

test("depois do Repor a etiqueta do interruptor diz o que ele mostra", async () => {
    const { form, section } = mounted();
    const input = section.querySelector("[data-alarm-clock-field=\"masterEnabled\"]");
    const label = input.parentElement.querySelector("[data-switch-label]");

    input.checked = false;
    input.dispatchEvent(new window.Event("change", { bubbles: true }));

    form.reset();
    await Promise.resolve();

    assert.equal(input.checked, true, "o Repor devolveu o interruptor ao inicial");
    assert.equal(label.textContent, "Lembretes ligados", "e a etiqueta acompanhou");
});
