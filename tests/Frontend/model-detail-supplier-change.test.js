import test from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initSettingsModels } = await import(
    "../../src/Dashboard/dashboard/settings/models/shell.js",
);
const {
    handleModelDetailImageChange,
    renderModelDetailInfo,
    saveModelDetail,
} = await import("../../src/Dashboard/dashboard/settings/models/detail.js");

/**
 * Trocar o fornecedor faz o servidor substituir as capacidades pelo template do novo, e a ficha
 * tem de recarregar para mostrar e gravar a selecção que existe.
 */
const MODEL = {
    id: 42,
    supplier: "Wonlex",
    supplierId: 5,
    internalModel: "MF91",
    commercialName: "MF91",
    deviceType: "bracelet",
    image: "",
};

const jsonResponse = (obj) => Response.json(obj);

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
        <div id="modelCapabilities"></div>
        <nav id="modelsBreadcrumb">
            <span id="modelsBreadcrumbModels"></span>
            <span id="modelsBreadcrumbNew"></span>
            <span id="modelsBreadcrumbCurrent"></span>
        </nav>
        <span id="modelDetailDeleteHint"></span>`;

    const found = {};
    for (const el of document.querySelectorAll("[id]")) found[el.id] = el;
    // O que a ficha toca e não está na marcação deste teste nasce aqui: o que se prende é o
    // recarregamento, e não quais os elementos que ela pinta pelo caminho.
    const els = autoEls(undefined, found);
    initSettingsModels({ els, ui: {} });
    state.modelModalSuppliers = [{ id: 5, name: "Wonlex" }, { id: 9, name: "4P Touch" }];
    state.settingsModal.currentCapabilitiesModel = MODEL;
    return els;
}

/** As leituras pedidas, pela ordem por que saíram: o PUT da gravação não conta. */
function recordReads(responses) {
    const urls = [];
    globalThis.fetch = async (url, options = {}) => {
        if ((options.method || "GET").toUpperCase() === "GET") urls.push(String(url));
        const match = Object.keys(responses).find((part) => String(url).includes(part));
        return jsonResponse(match ? responses[match] : { data: [] });
    };

    return urls;
}

test("changing the supplier reloads the sheet with the new template", async () => {
    const els = setupDom();
    renderModelDetailInfo(MODEL);
    const urls = recordReads({
        "/api/models/42": { data: { ...MODEL, supplierId: 9, supplier: "4P Touch" } },
    });

    els.modelDetailSupplierSelect.value = "4P Touch";
    await saveModelDetail();

    assert.ok(
        urls.some((url) => /\/api\/models\/42(\?|$)/.test(url)),
        `a ficha tinha de ser recarregada; pedidos: ${urls.join(", ")}`,
    );
});

test("saving without changing the supplier does not reload the sheet", async () => {
    const els = setupDom();
    renderModelDetailInfo(MODEL);
    els.modelDetailCommercialName.value = "MF91 Pro";
    const urls = recordReads({});

    await saveModelDetail();

    assert.equal(urls.filter((url) => /\/api\/models\/42(\?|$)/.test(url)).length, 0);
});

function pick(els, name = "images.jpeg") {
    const file = new File([new Uint8Array([1, 2, 3])], name, { type: "image/jpeg" });
    Object.defineProperty(els.modelDetailImageInput, "files", { value: [file], configurable: true });
    return file;
}

test("choosing an image on the model sheet shows Guardar", async () => {
    globalThis.fetch = async () => jsonResponse({ data: [], pagination: { total: 0 } });
    const els = setupDom();
    renderModelDetailInfo(MODEL);
    assert.ok(els.modelDetailSaveBtn.classList.contains("d-none"), "sem alterações não há Guardar");

    pick(els);
    handleModelDetailImageChange();

    assert.ok(!els.modelDetailSaveBtn.classList.contains("d-none"));
    assert.match(els.modelDetailImage.innerHTML, /<img/);
});

test("saving the sheet sends the chosen image", async () => {
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
