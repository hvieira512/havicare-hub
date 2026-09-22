import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { installDeferredFetch } from "./support/deferred-fetch.js";

const { setDeviceListPage, state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceList, loadSummary } =
    await import("../../src/Dashboard/dashboard/devices/list.js");

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

const pageResponse = (page) => ({
    data: [{ imei: `dev-${page}`, deviceType: "watch" }],
    pagination: { page, total_pages: 9, total: 180, limit: 20 },
});

beforeEach(() => {
    initDeviceList({ els, ui: {}, onChange: () => {} });
    // Com as licenças em cache, o único pedido em jogo é o da listagem.
    state.settingsModal.licenses = [{ licenseId: 1, company: "havicare" }];
    state.selectedImei = "";
    state.selectedDetail = null;
});

/**
 * Carregar na página 3 e logo a seguir na 4 deixava as duas respostas em corrida. A que
 * chegasse por último ficava no ecrã, e levava o paginador consigo: a lista mostrava uma
 * página e o paginador dizia outra.
 */
test("a resposta atrasada de uma página não substitui a página pedida a seguir", async () => {
    const fetches = installDeferredFetch();

    setDeviceListPage(3);
    const first = loadSummary();
    setDeviceListPage(4);
    const second = loadSummary();

    await fetches.respond("page=4", pageResponse(4));
    await fetches.respond("page=3", pageResponse(3));
    await Promise.all([first, second]);

    assert.equal(state.deviceListPage, 4, "o paginador tem de ficar na página pedida");
    assert.deepEqual(
        state.summary.devices.map((device) => device.imei),
        ["dev-4"],
        "a lista tem de ser a da página pedida",
    );
});
