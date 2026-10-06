import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initSettingsModels } = await import(
    "../../src/Dashboard/dashboard/settings/models/shell.js",
);
const { renderModelDetailInfo, saveModelDetail } = await import(
    "../../src/Dashboard/dashboard/settings/models/detail.js",
);

/**
 * Trocar o fornecedor faz o servidor substituir as capacidades pelo template do novo, e a ficha
 * tem de recarregar para mostrar e gravar a selecção que existe.
 */
const MODEL = {
    id: 42,
    supplier: "Wonlex",
    supplier_id: 5,
    internal_model: "MF91",
    commercial_name: "MF91",
    device_type: "bracelet",
    image: "",
};

const jsonResponse = (obj) => ({
    ok: true,
    status: 200,
    text: async () => JSON.stringify(obj),
    headers: { get: () => "application/json" },
});

// A ficha acaba a mandar o carrossel para o slide dela; aqui só interessa que não rebente.
class FakeCarousel {
    to() {}
}
FakeCarousel.getOrCreateInstance = () => new FakeCarousel();
globalThis.bootstrap = { Carousel: FakeCarousel };

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
    const els = new Proxy(found, {
        get(target, name) {
            if (typeof name !== "string") return undefined;
            if (!(name in target)) target[name] = document.createElement("div");
            return target[name];
        },
    });
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

test("trocar o fornecedor volta a carregar a ficha, com o template novo", async () => {
    const els = setupDom();
    renderModelDetailInfo(MODEL);
    const urls = recordReads({
        "/api/models/42": { data: { ...MODEL, supplier_id: 9, supplier: "4P Touch" } },
    });

    els.modelDetailSupplierSelect.value = "4P Touch";
    await saveModelDetail();

    assert.ok(
        urls.some((url) => /\/api\/models\/42(\?|$)/.test(url)),
        `a ficha tinha de ser recarregada; pedidos: ${urls.join(", ")}`,
    );
});

test("gravar sem trocar o fornecedor não recarrega a ficha", async () => {
    const els = setupDom();
    renderModelDetailInfo(MODEL);
    els.modelDetailCommercialName.value = "MF91 Pro";
    const urls = recordReads({});

    await saveModelDetail();

    assert.equal(urls.filter((url) => /\/api\/models\/42(\?|$)/.test(url)).length, 0);
});
