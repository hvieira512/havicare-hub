import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { patchConfigurationDeliveryStates } from "../../src/Dashboard/dashboard/devices/config/delivery.js";

/**
 * Os interruptores compactos são linhas e não secções, e a pastilha deles também tem de
 * acompanhar a entrega — senão o cartão diz «A aguardar» até alguém reabrir o modal.
 */

const confirmado = {
    entries: {
        settings_system: {
            key_tone: { status: "confirmed", operations: [] },
        },
    },
};

const bloco = (attr) => parseFragment(`
    <div ${attr} data-config-key="key_tone" data-capability-key="key_tone" data-config-stored="1">
        <span class="config-when-clean"><span class="state-badge badge-warning">A aguardar</span></span>
        <span class="config-when-changed"><span class="state-badge badge-warning">Alterado</span></span>
    </div>`);

test("a pastilha de uma linha acompanha a entrega, como a de uma secção", () => {
    const row = bloco("data-config-row");

    patchConfigurationDeliveryStates(row, confirmado);

    assert.match(row.querySelector(".config-when-clean").textContent, /Aplicado/);
});

test("a de uma secção continua a acompanhar", () => {
    const section = bloco("data-config-section");

    patchConfigurationDeliveryStates(section, confirmado);

    assert.match(section.querySelector(".config-when-clean").textContent, /Aplicado/);
});

/** A linha é compacta de propósito: não leva o aviso de entrega que a secção leva. */
test("uma linha não ganha aviso de entrega", () => {
    const row = bloco("data-config-row");

    patchConfigurationDeliveryStates(row, {
        entries: { settings_system: { key_tone: { status: "failed", error: "recusado", operations: [] } } },
    });

    assert.equal(row.querySelector("[role=\"status\"]"), null);
});
