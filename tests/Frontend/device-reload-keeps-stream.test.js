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

const streamRequests = (fetches) =>
    fetches.pending.filter((entry) => entry.url.includes("/stream")).length;

beforeEach(() => {
    initDeviceList({ els, ui: {}, onChange: () => {} });
    state.capabilityCatalogByType.watch = [];
    state.selectedDetail = null;
});

/**
 * Cada filtro e cada página da lista recarregam o dispositivo escolhido. Rasgar o SSE para o
 * reabrir a seguir perde o que ele já entregou, e o `recent` só chega por ali.
 */
test("recarregar o mesmo dispositivo não volta a abrir o stream", async () => {
    const fetches = installDeferredFetch();

    selectImei("aaa111");
    const first = loadDevice("aaa111");
    await fetches.respond("/api/devices/aaa111", detailFor("aaa111"));
    await first;
    assert.equal(streamRequests(fetches), 1, "o primeiro carregamento abre o stream");

    const again = loadDevice("aaa111");
    await fetches.respond("/api/devices/aaa111", detailFor("aaa111"));
    await again;

    assert.equal(streamRequests(fetches), 1);
});

test("escolher outro dispositivo abre o stream dele", async () => {
    const fetches = installDeferredFetch();

    selectImei("aaa111");
    const first = loadDevice("aaa111");
    await fetches.respond("/api/devices/aaa111", detailFor("aaa111"));
    await first;

    selectImei("bbb222");
    const second = loadDevice("bbb222");
    await fetches.respond("/api/devices/bbb222", detailFor("bbb222"));
    await second;

    assert.ok(
        fetches.pending.some((entry) => entry.url.includes("bbb222/stream")),
        "o stream tem de seguir o dispositivo escolhido",
    );
});
