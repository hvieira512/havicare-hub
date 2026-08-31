import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const {
    changeDeviceFilter,
    refreshSelectedDetail,
    resetDetailFiltersDraft,
    setModelPreviewObjectUrl,
    setSelectedDetail,
    state,
} = await import("../../src/Dashboard/dashboard/state.js");

/**
 * Os mutadores do estado partilhado.
 *
 * O que se prende aqui não é o valor que cada um escreve -- isso lê-se no `state.js` --, mas
 * os pares que não se podem separar: o `recent` que sobrevive a uma releitura, o object URL
 * que se revoga antes de ser substituído, a página que volta a 1 quando o filtro muda, e o
 * rascunho que é cópia e não a mesma referência. São exactamente as quatro coisas que se
 * esqueciam quando cada sítio escrevia no `state` por sua conta.
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

test("reler o registo do dispositivo não apaga o histórico que só o stream traz", () => {
    setSelectedDetail({ device: { imei: "111" }, model: { deviceType: "watch" } });
    state.selectedDetail.recent = { telemetry: [{ id: 1 }], events: [], commands: [] };

    // A resposta do `GET /api/devices/{imei}` não traz `recent`, e é isso que se simula.
    refreshSelectedDetail({ device: { imei: "111", online: true }, model: {} });

    assert.deepEqual(state.selectedDetail.recent.telemetry, [{ id: 1 }]);
    assert.equal(state.selectedDetail.device.online, true);
});

test("escolher um dispositivo começa sem histórico, à espera do stream", () => {
    setSelectedDetail({ device: { imei: "111" } });

    assert.equal(state.selectedDetail.recent, null);
});

test("uma releitura sem nada escolhido antes fica com o histórico vazio", () => {
    refreshSelectedDetail({ device: { imei: "222" } });

    assert.equal(state.selectedDetail.recent, null);
});

test("trocar a pré-visualização revoga o object URL anterior", () => {
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

test("mudar um filtro volta a listagem à primeira página", () => {
    state.deviceListPage = 4;

    changeDeviceFilter("deviceType", ["watch"]);

    assert.deepEqual(state.deviceFilters.deviceType, ["watch"]);
    assert.equal(state.deviceListPage, 1);
});

test("mudar um filtro não mexe nos outros", () => {
    changeDeviceFilter("supplier", ["acme"]);
    changeDeviceFilter("online", true);

    assert.deepEqual(state.deviceFilters.supplier, ["acme"]);
    assert.equal(state.deviceFilters.online, true);
});

test("o rascunho é cópia do que está aplicado, e não a mesma referência", () => {
    state.detailFilters = { from: "2026-01-01", to: "", type: "all", q: "" };

    resetDetailFiltersDraft();
    state.detailFiltersDraft.from = "2026-02-02";

    assert.equal(state.detailFilters.from, "2026-01-01");
    assert.notEqual(state.detailFiltersDraft, state.detailFilters);
});

// O selo não impede escrever num campo que já existe: só apanha a gralha que criava uma
// chave nova e calada. Os objectos aninhados ficam de fora, e são mexidos em todo o lado.
test("o estado selado atira numa chave que não existe e deixa passar as aninhadas", () => {
    assert.throws(() => {
        state.selectedDetial = "gralha";
    }, TypeError);

    state.deviceModal.imei = "999";
    state.settingsModal.section = "models";

    assert.equal(state.deviceModal.imei, "999");
    assert.equal(state.settingsModal.section, "models");
});
