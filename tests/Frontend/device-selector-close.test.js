import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { installDeferredFetch } from "./support/deferred-fetch.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceList, loadDevice, selectDevice } =
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

let hidden;

beforeEach(() => {
    hidden = 0;
    initDeviceList({
        els,
        ui: { deviceSelectorModal: { hide: () => { hidden += 1; }, show: () => {} } },
        onChange: () => {},
    });
    state.capabilityCatalogByType.watch = [];
    state.selectedDetail = null;
});

test("escolher um dispositivo fecha o selector", async () => {
    const fetches = installDeferredFetch();

    const chosen = selectDevice("aaa111");
    await fetches.respond("/api/devices/aaa111", detailFor("aaa111"));
    await chosen;

    assert.equal(hidden, 1, "o selector devia fechar-se");
});

/**
 * A listagem também relê o dispositivo escolhido quando a sua resposta chega, e essa leitura
 * ultrapassa a do clique. O selector ficava aberto por cima do detalhe já pintado: a escolha
 * vingou, só ninguém fechou a janela.
 */
test("o selector fecha-se mesmo quando outra leitura do mesmo dispositivo ultrapassa a do clique", async () => {
    const fetches = installDeferredFetch();

    const chosen = selectDevice("aaa111");
    const overtaking = loadDevice("aaa111");

    await fetches.respond("/api/devices/aaa111", detailFor("aaa111"));
    await fetches.respond("/api/devices/aaa111", detailFor("aaa111"));
    await Promise.all([chosen, overtaking]);

    assert.equal(state.selectedImei, "aaa111");
    assert.equal(hidden, 1, "a escolha vingou, o selector tem de fechar");
});

/** Uma leitura que falha tira a selecção, e aí o selector fica aberto para se escolher outro. */
test("se a leitura falhar, o selector fica aberto", async () => {
    const fetches = installDeferredFetch();

    const chosen = selectDevice("zzz999");
    await fetches.respond("/api/devices/zzz999", { error: { code: "not_found" } });
    await chosen;

    assert.equal(hidden, 0);
});
