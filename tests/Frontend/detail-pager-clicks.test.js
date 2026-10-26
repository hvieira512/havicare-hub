import test, { beforeEach, describe } from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";
import { cacheElements } from "../../src/Dashboard/dashboard/dom.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const {
    handleDownlinkPagerClick,
    handleTelemetryPagerClick,
} = await import("../../src/Dashboard/dashboard/devices/detail/detail-filters.js");
const { renderTelemetryList } = await import(
    "../../src/Dashboard/dashboard/devices/detail/detail.js",
);

const pagerClick = (action, page = "") => {
    const button = document.createElement("button");
    button.dataset.action = action;
    button.dataset.page = page;
    document.body.appendChild(button);

    return { target: button };
};

describe("both pagers", () => {
    /**
     * Os dois paginadores correm sobre o mesmo algoritmo, cada um com a sua lista, tamanho, página e
     * acção; trocar dois desses valores não se vê num render, e por isso afirma-se o clique.
     */
    let els;

    const commandRow = (id) => ({ payload: { type: "command", feature: "battery", requestedAt: `2026-09-2${id}T10:00:00Z` } });
    const telemetryRow = (id) => ({ payload: { type: "battery", occurredAt: `2026-09-2${id}T10:00:00Z`, data: { percent: id } } });

    beforeEach(() => {
        els = autoEls();
        cacheElements(els);
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

    test("the requests pager advances the requests page, and only that one", () => {
        handleDownlinkPagerClick(pagerClick("downlinkNext", 2));

        assert.equal(state.downlinkPage, 2);
        assert.equal(state.telemetryPage, 1, "a telemetria não se mexe");
        assert.equal(els.telemetryList.innerHTML, "", "só o painel dos pedidos é redesenhado");
    });

    test("the telemetry pager advances the telemetry page, and only that one", () => {
        handleTelemetryPagerClick(pagerClick("telemetryNext", 2));

        assert.equal(state.telemetryPage, 2);
        assert.equal(state.downlinkPage, 1);
        assert.equal(els.downlinkRequests.innerHTML, "");
    });

    /** Cada painel conta as suas linhas: quatro pedidos a dois por página dão duas páginas. */
    test("each panel uses its own page size to know where to stop", () => {
        handleDownlinkPagerClick(pagerClick("downlinkNext", 2));
        handleDownlinkPagerClick(pagerClick("downlinkNext", 3));

        assert.equal(state.downlinkPage, 2, "quatro pedidos a dois por página são duas páginas");

        handleTelemetryPagerClick(pagerClick("telemetryNext", 2));
        handleTelemetryPagerClick(pagerClick("telemetryNext", 3));

        assert.equal(state.telemetryPage, 2, "quatro leituras a três por página são duas páginas");
    });

    test("each panel gets the rows of its own kind, not the other's", () => {
        handleDownlinkPagerClick(pagerClick("downlinkNext", 2));
        assert.equal(els.downlinkRequestCount.textContent, "4", "os quatro pedidos, que a paginação corta a seguir");

        handleTelemetryPagerClick(pagerClick("telemetryNext", 2));
        assert.equal(els.telemetryCount.textContent, "4");
    });

    test("a click that is not from the pager is ignored", () => {
        handleDownlinkPagerClick(pagerClick("outraCoisa"));

        assert.equal(state.downlinkPage, 1);
        assert.equal(els.downlinkRequests.innerHTML, "");
    });
});

describe("the phone's «Carregar mais»", () => {
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
        cacheElements(view);
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

    test("«Carregar mais» appends the next page to the end of the list", () => {
        handleTelemetryPagerClick(pagerClick("telemetryMore"));

        assert.equal(state.telemetryPage, 2);
        assert.equal(listedRows(view), PAGE_SIZE * 2, "as duas páginas, e não só a segunda");
    });

    test("numbered pagination replaces the page instead of appending it", () => {
        handleTelemetryPagerClick(pagerClick("telemetryMore"));
        handleTelemetryPagerClick(pagerClick("telemetryNext", 3));

        assert.equal(state.telemetryPage, 3);
        assert.equal(listedRows(view), PAGE_SIZE, "a terceira página sozinha");
    });

    test("while there are pages left the button is there", () => {
        renderTelemetryList(readings);

        const button = view.telemetryLoadMore.querySelector("[data-action=\"telemetryMore\"]");
        assert.ok(button, "esperava-se o botão do telemóvel");
        assert.equal(button.textContent.trim(), "Carregar mais");
    });

    test("on the last page there is nothing more to fetch, and the button goes", () => {
        handleTelemetryPagerClick(pagerClick("telemetryMore"));
        handleTelemetryPagerClick(pagerClick("telemetryMore"));

        assert.equal(state.telemetryPage, 3);
        assert.equal(listedRows(view), PAGE_SIZE * 3);
        assert.equal(view.telemetryLoadMore.innerHTML, "");
    });
});
