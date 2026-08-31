import test, { beforeEach } from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const {
    initDeviceDetailView,
    renderTelemetryList,
    renderDownlinkRequests,
} = await import("../../src/Dashboard/dashboard/devices/detail.js");

/**
 * Os dois paginadores do detalhe do dispositivo: a telemetria e os pedidos.
 *
 * Saem os dois do `renderPagination`, e o que se prende aqui é o que essa partilha tem de
 * respeitar -- que cada um leva o seu prefixo nas acções, porque é por esse nome que os
 * handlers do `app.js` estão registados: `telemetryPageGo`, e não `telemetryGo`. Uma troca
 * ingénua deixava os botões a não fazer nada, sem erro nenhum.
 *
 * O aspecto dos botões não é assunto destes testes: classes e ícones são do CSS e mudam sem
 * que nada se parta.
 */
function fakeElement() {
    return document.createElement("div");
}

beforeEach(() => {
    state.telemetryPage = 1;
    state.downlinkPage = 1;
});

/**
 * Quantas entradas fazem `pages` páginas, ao tamanho de página que estiver em vigor.
 *
 * Estava escrito 30 à mão, com "30 eventos a 12 por página dão três páginas" ao lado. Ao
 * subir o tamanho da página os números deixaram de bater certo e três testes partiram-se
 * sem que nada do que eles afirmam tivesse mudado. O tamanho da página é uma afinação, não
 * é o que estes testes prendem.
 */
function entriesForPages(pageSize, pages, extra = 6) {
    return pageSize * (pages - 1) + extra;
}

function pagerButton(controls, action) {
    const button = controls.querySelector(`[data-action="${action}"]`);
    assert.ok(button, `nenhum botão com a acção ${action}`);
    return button;
}

function pagerEls(prefix) {
    return {
        [`${prefix}Pager`]: fakeElement(),
        [`${prefix}PagerSummary`]: fakeElement(),
        [`${prefix}PagerControls`]: fakeElement(),
    };
}

function telemetryEls() {
    return {
        telemetryCount: fakeElement(),
        telemetryList: fakeElement(),
        ...pagerEls("telemetry"),
    };
}

function downlinkEls() {
    return {
        downlinkRequestCount: fakeElement(),
        downlinkRequests: fakeElement(),
        ...pagerEls("downlink"),
    };
}

// A forma que o DeviceEventStore guarda, tal como a vi em produção.
function telemetryEvent(index) {
    const minute = String(index % 60).padStart(2, "0");
    return {
        schemaVersion: 2,
        type: "battery",
        occurredAt: `2026-08-25T10:${minute}:00Z`,
        device: { id: "868705080304889", supplier: "Wonlex", model: "HW20PRO" },
        data: { percent: 90 - index },
        source: { protocol: "wonlex-json", nativeType: "upBattery" },
    };
}

function downlinkRequest(index) {
    const minute = String(index % 60).padStart(2, "0");
    return {
        feature: "heart_rate",
        status: "sent",
        requestedAt: `2026-08-25T11:${minute}:00Z`,
        occurredAt: `2026-08-25T11:${minute}:00Z`,
    };
}

test("o paginador de telemetria esconde-se quando tudo cabe numa página", () => {
    const els = telemetryEls();
    initDeviceDetailView({ els });

    renderTelemetryList([telemetryEvent(0), telemetryEvent(1)]);

    assert.equal(els.telemetryPager.classList.contains("d-none"), true);
    assert.equal(els.telemetryPagerSummary.textContent, "");
    assert.equal(els.telemetryPagerControls.innerHTML, "");
});

test("três páginas dão três botões, com a actual marcada e o anterior travado", () => {
    const els = telemetryEls();
    initDeviceDetailView({ els });

    const size = state.telemetryPageSize;
    const total = entriesForPages(size, 3);
    renderTelemetryList(Array.from({ length: total }, (_, index) => telemetryEvent(index)));

    assert.equal(els.telemetryPager.classList.contains("d-none"), false);
    assert.equal(els.telemetryPagerSummary.textContent, `1–${size} de ${total}`);

    const buttons = [...els.telemetryPagerControls.querySelectorAll("button")];
    assert.deepEqual(
        buttons.map((button) => button.dataset.action),
        ["telemetryPrev", "telemetryPageGo", "telemetryPageGo", "telemetryPageGo", "telemetryNext"],
    );
    assert.deepEqual(
        buttons.map((button) => button.dataset.page).filter(Boolean),
        ["1", "2", "3"],
    );

    // A página actual distingue-se para quem vê e para quem ouve.
    assert.equal(buttons[1].getAttribute("aria-current"), "page");
    assert.equal(buttons[2].hasAttribute("aria-current"), false);

    assert.equal(buttons[0].disabled, true);
    assert.equal(buttons.at(-1).disabled, false);
    // As setas são ícones, e por isso o nome delas só existe no rótulo acessível.
    assert.equal(buttons[0].getAttribute("aria-label"), "Página anterior");
    assert.equal(buttons.at(-1).getAttribute("aria-label"), "Página seguinte");
});

test("na última página o resumo conta só o que resta e o seguinte fica travado", () => {
    const els = telemetryEls();
    initDeviceDetailView({ els });
    state.telemetryPage = 3;

    const size = state.telemetryPageSize;
    const total = entriesForPages(size, 3);
    renderTelemetryList(Array.from({ length: total }, (_, index) => telemetryEvent(index)));

    assert.equal(els.telemetryPagerSummary.textContent, `${size * 2 + 1}–${total} de ${total}`);
    assert.equal(pagerButton(els.telemetryPagerControls, "telemetryNext").disabled, true);
    assert.equal(pagerButton(els.telemetryPagerControls, "telemetryPrev").disabled, false);
});

test("o paginador dos pedidos leva o seu prefixo nas acções", () => {
    const els = downlinkEls();
    initDeviceDetailView({ els });

    const size = state.downlinkPageSize;
    const total = entriesForPages(size, 3);
    renderDownlinkRequests(Array.from({ length: total }, (_, index) => downlinkRequest(index)));

    assert.equal(els.downlinkPagerSummary.textContent, `1–${size} de ${total}`);
    assert.equal(pagerButton(els.downlinkPagerControls, "downlinkPrev").disabled, true);
    assert.deepEqual(
        [...els.downlinkPagerControls.querySelectorAll("[data-action='downlinkPageGo']")]
            .map((button) => button.dataset.page),
        ["1", "2", "3"],
    );
});
