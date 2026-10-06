import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { handleDeviceConfigClick } from "../../src/Dashboard/dashboard/devices/config/handlers.js";
import {
    readConfigPayload,
    renderConfigInputs,
} from "../../src/Dashboard/dashboard/devices/config/index.js";

/**
 * O botão que remove a linha sai do DOM com ela: um ouvinte que só depois procure a secção a
 * partir do alvo do evento já não a encontra.
 */
const sosSection = (numbers) => {
    document.body.innerHTML = `
        <section
            data-config-section
            data-config-key="sos_contacts"
            data-config-input="sos_contacts"
            data-config-protocol="four-p-touch"
            data-config-limit="3"
            data-config-stored="1">
            ${renderConfigInputs({ input: "sos_contacts", key: "sos_contacts", limit: 3 }, numbers)}
            <button data-action="saveConfig" data-config-phase="idle" disabled></button>
        </section>`;
    const section = document.body.querySelector("[data-config-section]");
    section.dataset.configPristine = JSON.stringify(readConfigPayload(section));
    return section;
};

test("removing an SOS row lights up the section's send button", () => {
    const section = sosSection(["+351965401976", "+351912345678"]);
    const button = section.querySelector("[data-action=\"saveConfig\"]");
    const [, second] = section.querySelectorAll("[data-action=\"removeRepeatRow\"]");

    handleDeviceConfigClick({ target: second, preventDefault() {} });

    assert.deepEqual(readConfigPayload(section), ["+351965401976"]);
    assert.equal(button.disabled, false);
});
