import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import { flush, installDeferredFetch } from "./support/deferred-fetch.js";
import { parseFragment } from "./support/dom.js";
import { cacheElements } from "../../src/Dashboard/dashboard/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { saveDeviceConfigurations } =
    await import("../../src/Dashboard/dashboard/devices/config/panel.js");

/** Um painel com uma definição editada: o valor no ecrã difere da fotografia. */
function editedPane() {
    return parseFragment(`
        <div data-config-pane="geral">
            <section data-config-section data-config-key="raw" data-config-input="json"
                     data-config-pristine='{"a":1}'>
                <textarea data-config-field="json">{"a":2}</textarea>
            </section>
        </div>`);
}

beforeEach(() => {
    cacheElements({});
    state.deviceModal.imei = "aaa111";
    state.deviceModal.configurations = { aaa111: {} };
});

test("a save's response does not write into the device opened in the meantime", async () => {
    const fetches = installDeferredFetch();

    const saving = saveDeviceConfigurations(editedPane());
    await flush();

    state.deviceModal.imei = "bbb222";
    state.deviceModal.configurations = { bbb222: {} };

    await fetches.respond("/configurations", { configurations: { aaa111: { a: 2 } } });
    await saving;

    assert.deepEqual(Object.keys(state.deviceModal.configurations), ["bbb222"]);
});
