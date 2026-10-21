import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import { installDeferredFetch } from "./support/deferred-fetch.js";
import { autoEls } from "./support/els.js";

const { selectImei, setDeviceListPage, state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceList, loadDevice, loadSummary, selectDevice } =
    await import("../../src/Dashboard/dashboard/devices/list.js");

const els = autoEls((name) => (name.endsWith("Search") || name.endsWith("Limit") ? "input" : "div"));

const detailFor = (imei) => ({
    device: { imei, deviceType: "watch", company: "havicare", licenseId: 1 },
    model: { deviceType: "watch" },
    linkedDevices: [],
});

const pageResponse = (page) => ({
    data: [{ imei: `dev-${page}`, deviceType: "watch" }],
    pagination: { page, totalPages: 9, total: 180, limit: 20 },
});

const streamRequests = (fetches) =>
    fetches.pending.filter((entry) => entry.url.includes("/stream")).length;

let hidden;

beforeEach(() => {
    hidden = 0;
    initDeviceList({
        els,
        ui: { deviceSelectorModal: { hide: () => { hidden += 1; }, show: () => {} } },
        onChange: () => {},
    });
    // Licenças e catálogo em cache: o único pedido em jogo é o de cada corrida.
    state.licenses = [{ licenseId: 1, company: "havicare" }];
    state.capabilityCatalogByType.watch = [];
    state.selectedImei = "";
    state.selectedDetail = null;
});

/** A lista e o paginador vêm da mesma resposta, e a última a chegar não pode ganhar. */
test("a late page response does not replace the page requested after it", async () => {
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

/** O detalhe de A por baixo da identidade de B é telemetria clínica no utente errado. */
test("a late device response does not overwrite the one chosen after it", async () => {
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

/**
 * Cada filtro e cada página da lista recarregam o dispositivo escolhido. Rasgar o SSE para o
 * reabrir a seguir perde o que ele já entregou, e o `recent` só chega por ali.
 */
test("reloading the same device does not reopen the stream", async () => {
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

/** Abrir o selector relê o escolhido; com o stream aberto, o histórico não volta a chegar. */
test("reloading the same device keeps the readings and requests the stream delivered", async () => {
    const fetches = installDeferredFetch();

    selectImei("aaa111");
    const first = loadDevice("aaa111");
    await fetches.respond("/api/devices/aaa111", detailFor("aaa111"));
    await first;
    const recent = { telemetry: [{ type: "battery", data: { percent: 80 } }], events: [], commands: [] };
    state.selectedDetail.recent = recent;

    const again = loadDevice("aaa111");
    await fetches.respond("/api/devices/aaa111", detailFor("aaa111"));
    await again;

    assert.equal(state.selectedDetail.recent, recent);
});

test("choosing another device opens its stream", async () => {
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

test("choosing a device closes the selector", async () => {
    const fetches = installDeferredFetch();

    const chosen = selectDevice("aaa111");
    await fetches.respond("/api/devices/aaa111", detailFor("aaa111"));
    await chosen;

    assert.equal(hidden, 1, "o selector devia fechar-se");
});

/**
 * A listagem também relê o dispositivo escolhido quando a sua resposta chega, e essa leitura
 * pode ultrapassar a do clique.
 */
test("the selector closes even when another read of the same device overtakes the click's", async () => {
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
test("if the read fails the selector stays open", async () => {
    const fetches = installDeferredFetch();

    const chosen = selectDevice("zzz999");
    await fetches.respond("/api/devices/zzz999", { error: { code: "not_found" } });
    await chosen;

    assert.equal(hidden, 0);
});
