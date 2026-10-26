import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

const {
    FILTERS_STORAGE_KEY,
    SELECTED_DEVICE_STORAGE_KEY,
} = await import("../../src/Dashboard/dashboard/core/storage.js");
const {
    changeDeviceFilter,
    clearSelection,
    refreshSelectedDetail,
    resetDetailFiltersDraft,
    selectImei,
    setDeviceFilters,
    setModelPreviewObjectUrl,
    setSelectedDetail,
    state,
} = await import("../../src/Dashboard/dashboard/core/state.js");

/**
 * Os pares inseparáveis: o `recent` que sobrevive à releitura, o object URL revogado ao ser
 * substituído, a página que volta a 1 com o filtro, e o rascunho que é cópia e não referência.
 */
beforeEach(() => {
    state.selectedDetail = null;
    state.modelPreviewObjectUrl = null;
    state.deviceListPage = 1;
    state.detailFilters = { from: "", to: "", type: "all", q: "" };
    state.detailFiltersDraft = { from: "", to: "", type: "all", q: "" };
    state.deviceFilters = {
        deviceType: [],
        supplier: [],
        model: [],
        license: [],
        online: null,
    };
});

test("rereading the device record does not erase the history only the stream brings", () => {
    setSelectedDetail({ device: { imei: "111" }, model: { deviceType: "watch" } });
    state.selectedDetail.recent = { telemetry: [{ id: 1 }], events: [], commands: [] };

    // A resposta do `GET /api/devices/{imei}` não traz `recent`, e é isso que se simula.
    refreshSelectedDetail({ device: { imei: "111", online: true }, model: {} });

    assert.deepEqual(state.selectedDetail.recent.telemetry, [{ id: 1 }]);
    assert.equal(state.selectedDetail.device.online, true);
});

test("selecting a device starts without history, waiting for the stream", () => {
    setSelectedDetail({ device: { imei: "111" } });

    assert.equal(state.selectedDetail.recent, null);
});

test("a reread with nothing selected before keeps an empty history", () => {
    refreshSelectedDetail({ device: { imei: "222" } });

    assert.equal(state.selectedDetail.recent, null);
});

test("replacing the preview revokes the previous object URL", () => {
    const revoked = [];
    const original = URL.revokeObjectURL;
    URL.revokeObjectURL = (url) => revoked.push(url);

    try {
        setModelPreviewObjectUrl("blob:antigo");
        setModelPreviewObjectUrl("blob:novo");

        assert.deepEqual(revoked, ["blob:antigo"]);
        assert.equal(state.modelPreviewObjectUrl, "blob:novo");

        // Sem argumento limpa, e limpar também revoga.
        setModelPreviewObjectUrl();

        assert.deepEqual(revoked, ["blob:antigo", "blob:novo"]);
        assert.equal(state.modelPreviewObjectUrl, null);

        // Nada guardado não é nada para revogar.
        setModelPreviewObjectUrl();

        assert.equal(revoked.length, 2);
    } finally {
        URL.revokeObjectURL = original;
    }
});

test("changing a filter takes the list back to page one", () => {
    state.deviceListPage = 4;

    changeDeviceFilter("deviceType", ["watch"]);

    assert.deepEqual(state.deviceFilters.deviceType, ["watch"]);
    assert.equal(state.deviceListPage, 1);
});

test("changing one filter leaves the others alone", () => {
    changeDeviceFilter("supplier", ["acme"]);
    changeDeviceFilter("online", true);

    assert.deepEqual(state.deviceFilters.supplier, ["acme"]);
    assert.equal(state.deviceFilters.online, true);
});

test("the draft is a copy of what is applied, not the same reference", () => {
    state.detailFilters = { from: "2026-01-01", to: "", type: "all", q: "" };

    resetDetailFiltersDraft();
    state.detailFiltersDraft.from = "2026-02-02";

    assert.equal(state.detailFilters.from, "2026-01-01");
    assert.notEqual(state.detailFiltersDraft, state.detailFilters);
});

// O selo não impede escrever num campo que já existe: só apanha a gralha que criaria uma
// chave nova e calada. Os objectos aninhados ficam de fora, e são mexidos em todo o lado.
test("the sealed state throws on a missing key and lets nested ones through", () => {
    assert.throws(() => {
        state.selectedDetial = "gralha";
    }, TypeError);

    state.deviceModal.imei = "999";
    state.settingsModal.section = "models";

    assert.equal(state.deviceModal.imei, "999");
    assert.equal(state.settingsModal.section, "models");
});

/** O aparelho escolhido e os filtros sobrevivem a recarregar a página. */
test("the chosen device is remembered, and forgotten when the selection clears", () => {
    selectImei("351266770073676");
    assert.equal(localStorage.getItem(SELECTED_DEVICE_STORAGE_KEY), "351266770073676");

    clearSelection();
    assert.equal(localStorage.getItem(SELECTED_DEVICE_STORAGE_KEY), null);
});

test("a changed filter is remembered, and so is clearing them all", () => {
    changeDeviceFilter("deviceType", ["watch"]);
    assert.deepEqual(JSON.parse(localStorage.getItem(FILTERS_STORAGE_KEY)).deviceType, ["watch"]);

    setDeviceFilters({ deviceType: [], supplier: [], model: [], license: [], online: null });
    assert.deepEqual(JSON.parse(localStorage.getItem(FILTERS_STORAGE_KEY)).deviceType, []);
});
