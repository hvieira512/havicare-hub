import test, { beforeEach, describe } from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { cacheElements } = await import("../../src/Dashboard/dashboard/dom.js");
const {
    initDeviceList,
    openDeviceSelector,
    renderDeviceSelector,
    restoreSelectedDevice,
} = await import("../../src/Dashboard/dashboard/devices/list.js");

// Um pedido pendurado prova que o que se afirma acontece antes de a resposta chegar.
const hangingFetch = () => new Promise(() => {});

describe("pesquisa", () => {
    /**
     * O selector desenha-se inteiro e o detalhe sai pelo caminho vazio.
     */
    const els = autoEls((name) => (name.endsWith("Search") || name.endsWith("Limit") ? "input" : "div"));

    const ui = { deviceSelectorModal: { show() {} } };

    beforeEach(() => {
        globalThis.fetch = async () => Response.json({ data: [], pagination: { page: 1, total_pages: 1, total: 0, limit: 20 } });
        initDeviceList({ els, ui, onChange: () => {} });
        // Licenças já carregadas: a promessa partilhada pode ter ficado pendurada noutro bloco.
        state.licenses = [{ id: 1 }];
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
});

/** Sem dispositivo escolhido não há atividade para mostrar, e o selector abre-se sozinho. */
describe("abertura automática", () => {
    const IDS = [
        "deviceList",
        "deviceListSearch",
        "deviceSelectionEmptyState",
        "selectedDevicePanel",
        "deviceDetail",
        "detailColumn",
        "deviceColumn",
        "requestCardsCard",
        "deviceBand",
        "deviceTabs",
        "requestGrid",
        "ncsEventGrid",
        "ncsEventSection",
    ];

    function boot() {
        // A lista já com uma linha dentro: cheia, o selector não desenha o esqueleto, que puxa
        // os filtros todos atrás dele.
        document.body.innerHTML = IDS.map(
            (id) => `<div id="${id}">${id === "deviceList" ? "<div></div>" : ""}</div>`,
        ).join("");
        state.selectedDetail = null;
        state.selectedImei = null;
        globalThis.fetch = hangingFetch;
        const opened = [];
        initDeviceList({
            els: cacheElements(),
            ui: { deviceSelectorModal: { show: () => opened.push("show") } },
        });
        return opened;
    }

    test("sem dispositivo guardado o selector abre-se sozinho", () => {
        const opened = boot();

        restoreSelectedDevice("");

        assert.deepEqual(opened, ["show"], "o arranque sem escolha tem de abrir o selector");
    });

    test("com dispositivo guardado o selector fica fechado", () => {
        const opened = boot();

        restoreSelectedDevice("351266770073676");

        assert.deepEqual(opened, [], "quem já tem escolha não leva um modal na cara");
        assert.equal(state.selectedImei, "351266770073676");
    });
});

describe("falha a carregar", () => {
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

        globalThis.fetch = hangingFetch;
        initDeviceList({ els, ui: { deviceSelectorModal: { show: () => {} } }, services: {} });

        state.summary = {
            devices: [],
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
});

describe("abrir", () => {
    /** O modal abre antes de a resposta chegar, e o pedido fica pendurado para o provar. */
    function setUpSelector() {
        document.body.innerHTML = `
            <div id="deviceList" class="device-card-list"></div>
            <div id="deviceTypeFilter"></div>
            <div id="deviceSupplierFilter"></div>
            <div id="deviceModelFilter"></div>
            <div id="deviceLicenseFilter"></div>`;

        const byId = (id) => document.getElementById(id);
        const els = {
            deviceList: byId("deviceList"),
            deviceTypeFilter: byId("deviceTypeFilter"),
            deviceSupplierFilter: byId("deviceSupplierFilter"),
            deviceModelFilter: byId("deviceModelFilter"),
            deviceLicenseFilter: byId("deviceLicenseFilter"),
        };

        let shown = false;
        globalThis.fetch = hangingFetch;
        initDeviceList({
            els,
            ui: {
                deviceSelectorModal: {
                    show: () => {
                        shown = true;
                    },
                },
            },
            services: {},
        });

        return { els, wasShown: () => shown };
    }

    test("o modal aparece antes de a lista chegar, com esqueleto no lugar dela", async () => {
        const { els, wasShown } = setUpSelector();

        void openDeviceSelector();

        assert.equal(wasShown(), true);
        assert.ok(
            els.deviceList.querySelectorAll(".device-card-skeleton").length > 0,
        );
        assert.equal(els.deviceList.getAttribute("aria-busy"), "true");
        // Os filtros não ficam uma coluna vazia enquanto se espera.
        assert.ok(
            els.deviceSupplierFilter.querySelectorAll(".placeholder").length > 0,
        );
    });
});
