import test, { beforeEach, describe } from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const {
    handleDownlinkPagerClick,
    handleTelemetryPagerClick,
    initDetailFilters,
} = await import("../../src/Dashboard/dashboard/devices/detail-filters.js");
const { initDeviceDetailView, renderTelemetryList } = await import(
    "../../src/Dashboard/dashboard/devices/detail.js",
);

const pagerClick = (action) => {
    const button = document.createElement("button");
    button.dataset.action = action;
    document.body.appendChild(button);

    return { target: button };
};

describe("os dois paginadores", () => {
    /**
     * Os dois paginadores correm sobre o mesmo algoritmo, cada um com a sua lista, tamanho, página e
     * acção; trocar dois desses valores não se vê num render, e por isso afirma-se o clique.
     */
    const els = autoEls();

    let drawn;

    const commandRow = (id) => ({ payload: { type: "command", feature: "battery", requestedAt: `2026-09-2${id}T10:00:00Z` } });
    const telemetryRow = (id) => ({ payload: { type: "battery", occurredAt: `2026-09-2${id}T10:00:00Z`, data: { percent: id } } });

    beforeEach(() => {
        drawn = { downlink: null, telemetry: null };
        initDetailFilters({
            els,
            onChange: () => {},
            renderDownlinkRequests: (rows) => { drawn.downlink = rows; },
            renderTelemetryList: (rows) => { drawn.telemetry = rows; },
        });
        state.detailFilters = { from: "", to: "", type: "all", q: "" };
        state.selectedDetail = {
            model: { deviceType: "watch" },
            recent: {
                commands: [commandRow(1), commandRow(2), commandRow(3), commandRow(4)],
                telemetry: [telemetryRow(1), telemetryRow(2), telemetryRow(3), telemetryRow(4)],
                events: [],
            },
        };
        // Tamanhos diferentes de propósito: com os dois iguais, uma troca passaria despercebida.
        state.downlinkPageSize = 2;
        state.telemetryPageSize = 3;
        state.downlinkPage = 1;
        state.telemetryPage = 1;
    });

    test("o paginador dos pedidos avança a página dos pedidos, e só essa", () => {
        handleDownlinkPagerClick(pagerClick("downlinkNext"));

        assert.equal(state.downlinkPage, 2);
        assert.equal(state.telemetryPage, 1, "a telemetria não se mexe");
        assert.equal(drawn.telemetry, null, "só o painel dos pedidos é redesenhado");
    });

    test("o paginador da telemetria avança a página da telemetria, e só essa", () => {
        handleTelemetryPagerClick(pagerClick("telemetryNext"));

        assert.equal(state.telemetryPage, 2);
        assert.equal(state.downlinkPage, 1);
        assert.equal(drawn.downlink, null);
    });

    /** Cada painel conta as suas linhas: quatro pedidos a dois por página dão duas páginas. */
    test("cada painel usa o seu tamanho de página para saber onde pára", () => {
        handleDownlinkPagerClick(pagerClick("downlinkNext"));
        handleDownlinkPagerClick(pagerClick("downlinkNext"));

        assert.equal(state.downlinkPage, 2, "quatro pedidos a dois por página são duas páginas");

        handleTelemetryPagerClick(pagerClick("telemetryNext"));
        handleTelemetryPagerClick(pagerClick("telemetryNext"));

        assert.equal(state.telemetryPage, 2, "quatro leituras a três por página são duas páginas");
    });

    test("cada painel recebe as linhas do seu tipo, e não as do outro", () => {
        handleDownlinkPagerClick(pagerClick("downlinkNext"));
        assert.equal(drawn.downlink.length, 4, "os quatro pedidos, que a paginação corta a seguir");

        handleTelemetryPagerClick(pagerClick("telemetryNext"));
        assert.equal(drawn.telemetry.length, 4);
    });

    test("um clique que não é do paginador é ignorado", () => {
        handleDownlinkPagerClick(pagerClick("outraCoisa"));

        assert.equal(state.downlinkPage, 1);
        assert.equal(drawn.downlink, null);
    });
});

describe("o «Carregar mais» do telemóvel", () => {
    /**
     * O «Carregar mais» do telemóvel acrescenta a página seguinte e a paginação do desktop
     * substitui-a; as duas andam sobre a mesma página.
     */

    const PAGE_SIZE = 4;

    function fakeElement() {
        return document.createElement("div");
    }

    function detailEls() {
        return {
            telemetryCount: fakeElement(),
            telemetryTabCount: fakeElement(),
            deviceTabReadingsCount: fakeElement(),
            telemetryList: fakeElement(),
            telemetryPager: fakeElement(),
            telemetryPagerSummary: fakeElement(),
            telemetryPagerControls: fakeElement(),
            telemetryLoadMore: fakeElement(),
        };
    }

    const reading = (index) => ({
        payload: {
            type: "battery",
            seq: index,
            occurredAt: `2026-09-29T10:${String(index).padStart(2, "0")}:00Z`,
            data: { percent: 100 - index },
        },
    });

    const readings = Array.from({ length: PAGE_SIZE * 3 }, (_, index) => reading(index));

    const listedRows = (view) =>
        view.telemetryList.querySelectorAll(
            "tbody tr:not([data-day-header]):not(.telemetry-row-panel)",
        ).length;

    let view;

    beforeEach(() => {
        view = detailEls();
        initDeviceDetailView({ els: view });
        state.telemetryPageSize = PAGE_SIZE;
        state.telemetryPage = 1;
        state.telemetryCumulative = false;
        state.detailFilters = { from: "", to: "", type: "all", q: "" };
        state.selectedImei = "351266770073676";
        state.selectedDetail = {
            model: { deviceType: "watch" },
            recent: { telemetry: readings, events: [], commands: [] },
        };
    });

    test("o «Carregar mais» junta a página seguinte ao fim da lista", () => {
        handleTelemetryPagerClick(pagerClick("telemetryMore"));

        assert.equal(state.telemetryPage, 2);
        assert.equal(listedRows(view), PAGE_SIZE * 2, "as duas páginas, e não só a segunda");
    });

    test("a paginação numerada substitui a página em vez de a juntar", () => {
        handleTelemetryPagerClick(pagerClick("telemetryMore"));
        handleTelemetryPagerClick(pagerClick("telemetryNext"));

        assert.equal(state.telemetryPage, 3);
        assert.equal(listedRows(view), PAGE_SIZE, "a terceira página sozinha");
    });

    test("enquanto houver páginas por trazer, o botão está lá", () => {
        renderTelemetryList(readings);

        const button = view.telemetryLoadMore.querySelector("[data-action=\"telemetryMore\"]");
        assert.ok(button, "esperava-se o botão do telemóvel");
        assert.equal(button.textContent.trim(), "Carregar mais");
    });

    test("na última página não há mais nada para trazer, e o botão sai", () => {
        handleTelemetryPagerClick(pagerClick("telemetryMore"));
        handleTelemetryPagerClick(pagerClick("telemetryMore"));

        assert.equal(state.telemetryPage, 3);
        assert.equal(listedRows(view), PAGE_SIZE * 3);
        assert.equal(view.telemetryLoadMore.innerHTML, "");
    });
});
