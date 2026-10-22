import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import { flush } from "./support/deferred-fetch.js";
import { parseFragment } from "./support/dom.js";
import { cacheElements } from "../../src/Dashboard/dashboard/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { requestRadarStateOnOpen } =
    await import("../../src/Dashboard/dashboard/devices/config/panel.js");

/** Abrir as configurações de um radar pede-lhe o estado, pelo mesmo caminho do botão «Ler». */

let requests;

beforeEach(() => {
    requests = [];
    globalThis.fetch = async (url, init = {}) => {
        requests.push({ url: String(url), body: JSON.parse(init.body ?? "null") });
        return Response.json({ commands: [{ id: "c1", status: "queued" }] });
    };
    const root = parseFragment(`
        <section data-config-section data-config-key="read_device_state" data-capability-key="read_device_state"
                 data-config-input="action" data-config-transient="1"></section>`);
    cacheElements({ deviceConfigRoot: root });
    state.deviceModal.imei = "AD8A613B0493";
    state.deviceModal.protocol = "qinglanst-radar";
    state.deviceModal.actionDeliveries = {};
});

test("opening a radar's settings requests its state once", async () => {
    requestRadarStateOnOpen();
    await flush();
    requestRadarStateOnOpen();
    await flush();

    assert.equal(requests.length, 1);
    assert.match(requests[0].url, /\/api\/devices\/AD8A613B0493\/requests$/);
    assert.equal(requests[0].body.capability, "read_device_state");
});

test("a device without a state reading requests nothing", async () => {
    cacheElements({ deviceConfigRoot: parseFragment("<section data-config-section data-config-key=\"find_device\"></section>") });

    requestRadarStateOnOpen();
    await flush();

    assert.equal(requests.length, 0);
});
