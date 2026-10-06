import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceList, openDeviceSelector } =
    await import("../../src/Dashboard/dashboard/devices/list.js");

/**
 * O selector desenha-se inteiro e o detalhe sai pelo caminho vazio; cada nome pedido devolve um
 * elemento a sério.
 */
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

const ui = { deviceSelectorModal: { show() {} } };

beforeEach(() => {
    globalThis.fetch = async () => ({
        ok: true,
        status: 200,
        text: async () => JSON.stringify({ data: [], pagination: { page: 1, total_pages: 1, total: 0, limit: 20 } }),
        headers: { get: () => "application/json" },
    });
    initDeviceList({ els, ui, onChange: () => {} });
    state.selectedImei = "";
    state.selectedDetail = null;
});

/** Com a pesquisa anterior no campo, a frota parece ter desaparecido. */
test("abrir o selector limpa a pesquisa da abertura anterior", async () => {
    state.deviceSearchQuery = "868705080304889";
    els.deviceListSearch.value = "868705080304889";

    await openDeviceSelector();

    assert.equal(state.deviceSearchQuery, "", "o estado devia ficar sem pesquisa");
    assert.equal(els.deviceListSearch.value, "", "o campo devia ficar vazio");
});

test("abrir o selector volta à primeira página", async () => {
    state.deviceListPage = 4;

    await openDeviceSelector();

    assert.equal(state.deviceListPage, 1);
});

/** O pedido que sai na abertura não pode levar a pesquisa que se acabou de limpar. */
test("o pedido da abertura vai sem pesquisa", async () => {
    state.deviceSearchQuery = "VL17";
    const urls = [];
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async (url, options) => {
        urls.push(String(url));
        return originalFetch(url, options);
    };

    await openDeviceSelector();

    const listUrl = urls.find((url) => url.startsWith("/api/devices?") || url === "/api/devices");
    assert.ok(listUrl, "devia ter pedido a listagem");
    assert.doesNotMatch(listUrl, /VL17/);
});
