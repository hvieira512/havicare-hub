import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { installDeferredFetch } from "./support/deferred-fetch.js";

const { selectImei, state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceList, loadDevice } =
    await import("../../src/Dashboard/dashboard/devices/list.js");

/** Cada nome pedido devolve um elemento a sério, para não haver aqui trinta `createElement`. */
const els = new Proxy({}, {
    get(target, name) {
        if (typeof name !== "string") return undefined;
        if (!(name in target)) {
            target[name] = document.createElement(
                name.endsWith("Search") || name.endsWith("Limit") ? "input" : "div",
            );
        }
        return target[name];
    },
});

const detailFor = (imei) => ({
    device: { imei, deviceType: "watch", company: "havicare", licenseId: 1 },
    model: { deviceType: "watch" },
    linkedDevices: [],
});

beforeEach(() => {
    initDeviceList({ els, ui: {}, onChange: () => {} });
    // O catálogo em cache tira do caminho um pedido que não é o desta corrida.
    state.capabilityCatalogByType.watch = [];
    state.selectedDetail = null;
});

/**
 * Escolher o dispositivo A e logo a seguir o B, com a resposta de A a chegar depois da de B,
 * escrevia o detalhe de A por baixo da identidade de B: telemetria clínica atribuída ao utente
 * errado.
 */
test("a resposta atrasada de um dispositivo não sobrepõe o que foi escolhido a seguir", async () => {
    const fetches = installDeferredFetch();

    selectImei("aaa111");
    const first = loadDevice("aaa111");
    selectImei("bbb222");
    const second = loadDevice("bbb222");

    await fetches.respond("/api/devices/bbb222", detailFor("bbb222"));
    await fetches.respond("/api/devices/aaa111", detailFor("aaa111"));
    await Promise.all([first, second]);

    assert.equal(state.selectedImei, "bbb222");
    assert.equal(
        state.selectedDetail.device.imei,
        "bbb222",
        "o detalhe no ecrã tem de ser o do dispositivo escolhido",
    );
});
