import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import {
    initDeviceList,
    renderDeviceSelector,
} from "../../src/Dashboard/dashboard/devices/list.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

/** Uma falha a carregar diz «não sei» na lista, nos filtros e nos tipos, e não «não há». */
function setUpSelector(summary) {
    document.body.innerHTML = `
        <div id="deviceList" class="device-card-list"></div>
        <div id="deviceTypeFilter"></div>
        <div id="deviceSupplierFilter"></div>
        <div id="deviceLicenseFilter"></div>
        <div id="deviceListPagination">
            <div id="deviceListPaginationSummary"></div>
            <div id="deviceListPaginationControls"></div>
        </div>
        <div id="deviceSelectorSummary"></div>
        <div id="deviceActiveFilters"></div>
        <button id="clearDeviceFiltersBtn"></button>
        <button id="applyDeviceFiltersBtn"></button>`;

    const byId = (id) => document.getElementById(id);
    const els = {};
    for (const id of [
        "deviceList",
        "deviceTypeFilter",
        "deviceSupplierFilter",
        "deviceLicenseFilter",
        "deviceListPagination",
        "deviceListPaginationSummary",
        "deviceListPaginationControls",
        "deviceSelectorSummary",
        "deviceActiveFilters",
        "clearDeviceFiltersBtn",
        "applyDeviceFiltersBtn",
    ]) {
        els[id] = byId(id);
    }

    globalThis.fetch = () => new Promise(() => {});
    initDeviceList({ els, ui: { deviceSelectorModal: { show: () => {} } }, services: {} });

    state.summary = {
        devices: [],
        models: [],
        devicePagination: { limit: 20, page: 1, total_pages: 1, total: 0 },
        deviceFiltersAvailable: { deviceType: [], licenseId: [], company: [], supplier: [], model: [] },
        deviceFilterCounts: { deviceType: [], supplier: [], model: [], license: { companies: [], none: 0 } },
        deviceTotals: { total: 0, online: 0 },
        ...summary,
    };
    renderDeviceSelector();

    return els;
}

const columns = (els) => [els.deviceLicenseFilter, els.deviceSupplierFilter, els.deviceTypeFilter];

test("a falha diz-se uma vez, na lista", () => {
    const els = setUpSelector({ devicesError: { code: "unavailable" } });

    assert.match(els.deviceList.textContent, /Não foi possível carregar/);
    assert.ok(els.deviceList.querySelector("[data-action=\"retryDeviceList\"]"));
});

test("as colunas que dependem da mesma resposta não afirmam um vazio que ninguém mediu", () => {
    const els = setUpSelector({ devicesError: { code: "unavailable" } });

    for (const column of columns(els)) {
        assert.doesNotMatch(column.textContent, /Não há|nenhum/);
        assert.ok(column.querySelectorAll(".placeholder").length > 0);
    }
});

test("o cabeçalho não afirma uma contagem que não recebeu", () => {
    const els = setUpSelector({
        devicesError: { code: "unavailable" },
        deviceTotals: { total: 49, online: 27 },
    });

    assert.equal(els.deviceSelectorSummary.textContent, "");
});

test("sem falha, o vazio continua a poder dizer-se vazio", () => {
    const els = setUpSelector({ devicesError: null });

    assert.match(els.deviceLicenseFilter.textContent, /Não há licenças/);
    for (const column of columns(els)) {
        assert.equal(column.querySelectorAll(".placeholder").length, 0);
    }
});
