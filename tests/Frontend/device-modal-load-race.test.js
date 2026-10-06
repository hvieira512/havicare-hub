import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { flush, installDeferredFetch } from "./support/deferred-fetch.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { editDevice, initDeviceModal } =
    await import("../../src/Dashboard/dashboard/devices/device-modal.js");
const { initGatewayLinksUi } =
    await import("../../src/Dashboard/dashboard/devices/gateway-links-ui.js");

const els = new Proxy({}, {
    get(target, name) {
        if (typeof name !== "string") return undefined;
        if (!(name in target)) {
            // Tudo `input`: os divs servem na mesma para `innerHTML`, e só o input tem `value`.
            target[name] = document.createElement("input");
        }
        return target[name];
    },
});

const detailFor = (imei) => ({
    device: { imei, deviceType: "watch", company: "havicare", licenseId: 1, deviceId: imei },
    model: { deviceType: "watch", supplier: "wonlex", internalModel: "D41" },
    linkedDevices: [],
    configurations: { [imei]: {} },
});

beforeEach(() => {
    initDeviceModal({ els, deviceModal: { show() {}, hide() {} } });
    initGatewayLinksUi({ els });
    // Os dois catálogos em cache tiram do caminho pedidos que não são os desta corrida.
    state.deviceTypeSuppliersModels = [
        { deviceType: "watch", suppliers: [{ name: "wonlex", models: [{ internalModel: "D41" }] }] },
    ];
    state.licenses = [{ licenseId: 1, company: "havicare" }];
    state.capabilityCatalogByType.watch = [];
});

test("a resposta atrasada de um dispositivo não pinta o modal de outro", async () => {
    const fetches = installDeferredFetch();

    const first = editDevice("aaa111", "wonlex", "D41");
    await flush();
    const second = editDevice("bbb222", "wonlex", "D41");
    await flush();

    await fetches.respond("/api/devices/bbb222", detailFor("bbb222"));
    await fetches.respond("/api/devices/aaa111", detailFor("aaa111"));
    await Promise.all([first, second]);

    assert.equal(state.deviceModal.imei, "bbb222");
    assert.deepEqual(Object.keys(state.deviceModal.configurations), ["bbb222"]);
});
