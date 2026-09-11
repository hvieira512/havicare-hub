import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initSettingsModels } = await import(
    "../../src/Dashboard/dashboard/settings/models/shell.js",
);
const {
    handleModelDetailImageChange,
    renderModelDetailInfo,
    saveModelDetail,
} = await import("../../src/Dashboard/dashboard/settings/models/detail.js");

const jsonResponse = (obj) => ({
    ok: true,
    status: 200,
    text: async () => JSON.stringify(obj),
});

const MODEL = {
    id: 42,
    supplier: "Wonlex",
    supplier_id: 5,
    internal_model: "MF91",
    commercial_name: "MF91",
    device_type: "bracelet",
    image: "",
};

function setupDom() {
    document.body.innerHTML = `
        <div id="modelDetailFields">
            <input id="modelDetailCommercialName">
            <input id="modelDetailInternalModel">
            <select id="modelDetailSupplierSelect"></select>
            <select id="modelDetailDeviceType"></select>
            <span id="modelDetailDirtyState"></span>
            <button id="modelDetailSaveBtn" class="d-none"></button>
            <button id="modelDetailResetBtn" class="d-none"></button>
        </div>
        <div>
            <input type="file" id="modelDetailImageInput">
            <div id="modelDetailImage"><div id="modelDetailName"></div></div>
        </div>
        <span id="modelDetailDeleteHint"></span>`;

    const els = {};
    for (const el of document.querySelectorAll("[id]")) els[el.id] = el;
    initSettingsModels({ els, ui: {} });
    state.modelModalSuppliers = [{ id: 5, name: "Wonlex" }];
    state.settingsModal.currentCapabilitiesModel = MODEL;
    return els;
}

function pick(els, name = "images.jpeg") {
    const file = new File([new Uint8Array([1, 2, 3])], name, { type: "image/jpeg" });
    Object.defineProperty(els.modelDetailImageInput, "files", { value: [file], configurable: true });
    return file;
}

test("escolher uma imagem na ficha do modelo deixa o Guardar à vista", async () => {
    globalThis.fetch = async () => jsonResponse({ data: [], pagination: { total: 0 } });
    const els = setupDom();
    renderModelDetailInfo(MODEL);
    assert.ok(els.modelDetailSaveBtn.classList.contains("d-none"), "sem alterações não há Guardar");

    pick(els);
    handleModelDetailImageChange();

    assert.ok(!els.modelDetailSaveBtn.classList.contains("d-none"));
    assert.match(els.modelDetailImage.innerHTML, /<img/);
});

test("gravar a ficha envia a imagem escolhida", async () => {
    const calls = [];
    globalThis.fetch = async (url, options = {}) => {
        calls.push({ url, method: options.method || "GET", body: options.body });
        return jsonResponse({ status: "ok" });
    };
    const els = setupDom();
    renderModelDetailInfo(MODEL);
    const file = pick(els);
    handleModelDetailImageChange();

    await saveModelDetail();

    const put = calls.find((call) => call.method === "PUT");
    assert.ok(put, "gravou a ficha por PUT");
    assert.match(put.url, /\/api\/models\/42/);
    assert.equal(put.body.get("image"), file);
});
