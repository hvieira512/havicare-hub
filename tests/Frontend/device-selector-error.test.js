import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import {
    initDeviceList,
    renderDeviceSelector,
} from "../../src/Dashboard/dashboard/devices/list.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

/**
 * Uma falha a carregar produzia três respostas que se contradiziam: a lista dizia «não foi
 * possível carregar», a coluna dos filtros dizia «não há licenças para mostrar» e as seis
 * pastilhas de tipo diziam «nenhum». Duas das três leem-se como ausência de dados e não como
 * falha. É a distinção entre «não há» e «não sei».
 */
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
        <button id="clearDeviceFiltersBtn"></button>`;

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
        "clearDeviceFiltersBtn",
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
