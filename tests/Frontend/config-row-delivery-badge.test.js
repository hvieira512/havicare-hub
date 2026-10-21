import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";
import {
    configurationDeliveryMeta,
    patchConfigurationDeliveryStates,
} from "../../src/Dashboard/dashboard/devices/config/delivery.js";

/**
 * Os interruptores compactos são linhas e não secções, e a pastilha deles também tem de
 * acompanhar a entrega — senão o cartão diz «A aguardar» até alguém reabrir o modal.
 */

const confirmed = {
    entries: {
        settings_system: {
            key_tone: { status: "confirmed", operations: [] },
        },
    },
};

const block = (attr) => parseFragment(`
    <div ${attr} data-config-key="key_tone" data-capability-key="key_tone" data-config-stored="1">
        <span class="config-when-clean"><span class="state-badge badge-warning">A aguardar</span></span>
        <span class="config-when-changed"><span class="state-badge badge-warning">Alterado</span></span>
    </div>`);

test("a row's badge follows the delivery, like a section's", () => {
    const row = block("data-config-row");

    patchConfigurationDeliveryStates(row, confirmed);

    assert.match(row.querySelector(".config-when-clean").textContent, /Aplicado/);
});

test("a section's badge still follows it", () => {
    const section = block("data-config-section");

    patchConfigurationDeliveryStates(section, confirmed);

    assert.match(section.querySelector(".config-when-clean").textContent, /Aplicado/);
});

/** A linha é compacta de propósito: não leva o aviso de entrega que a secção leva. */
test("a row gets no delivery notice", () => {
    const row = block("data-config-row");

    patchConfigurationDeliveryStates(row, {
        entries: { settings_system: { key_tone: { status: "failed", error: "recusado", operations: [] } } },
    });

    assert.equal(row.querySelector("[role=\"status\"]"), null);
});

test("a delivery status the dashboard does not know is not shown as a failure", () => {
    const meta = configurationDeliveryMeta(true, { status: "rolled_back" });

    assert.notEqual(meta.label, "Falhou");
    assert.notEqual(meta.tone, "danger");
});
