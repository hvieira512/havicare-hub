import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { flush, installDeferredFetch } from "./support/deferred-fetch.js";
import { parseFragment } from "./support/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceConfigPanel, saveDeviceConfigurations } =
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
    initDeviceConfigPanel({ els: {} });
    state.deviceModal.imei = "aaa111";
    state.deviceModal.configurations = { aaa111: {} };
});

/**
 * Gravar no aparelho A e trocar para o B antes de a resposta chegar: as configurações do A
 * ficavam escritas no modal do B.
 */
test("a resposta de uma gravação não escreve no dispositivo que entretanto se abriu", async () => {
    const fetches = installDeferredFetch();

    const saving = saveDeviceConfigurations(editedPane());
    await flush();

    state.deviceModal.imei = "bbb222";
    state.deviceModal.configurations = { bbb222: {} };

    await fetches.respond("/configurations", { configurations: { aaa111: { a: 2 } } });
    await saving;

    assert.deepEqual(Object.keys(state.deviceModal.configurations), ["bbb222"]);
});
