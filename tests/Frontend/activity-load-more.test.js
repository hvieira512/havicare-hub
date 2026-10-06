import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceDetailView, renderTelemetryList } = await import(
    "../../src/Dashboard/dashboard/devices/detail.js",
);
const { handleTelemetryPagerClick } = await import(
    "../../src/Dashboard/dashboard/devices/detail-filters.js",
);

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

const pagerClick = (action) => {
    const button = document.createElement("button");
    button.dataset.action = action;
    return { target: button };
};

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
