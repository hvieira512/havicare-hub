import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const {state} = await import("../../src/Dashboard/dashboard/state.js");
const {
    initDeviceDetailView,
    renderTelemetryList,
    renderDownlinkRequests,
} = await import("../../src/Dashboard/dashboard/devices/detail.js");

/**
 * Caracteriza a marcação que os dois paginadores produzem HOJE.
 *
 * Existe para uma consolidação contra `renderPagination` poder ser provada como
 * mudança de sítio e não de comportamento: se estas cadeias mudarem, alguma coisa
 * mudou no ecrã. Repara nos nomes das acções -- `telemetryPageGo`, e não
 * `telemetryGo` -- porque é neles que os handlers em `app.js` estão
 * registados, e é a diferença que uma troca ingénua quebraria em silêncio.
 */
function fakeElement() {
    return document.createElement("div");
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
        device: {id: "868705080304889", supplier: "Wonlex", model: "HW20PRO"},
        data: {percent: 90 - index},
        source: {protocol: "wonlex-json", nativeType: "upBattery"},
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
    initDeviceDetailView({els});
    state.telemetryPage = 1;

    renderTelemetryList([telemetryEvent(0), telemetryEvent(1)]);

    assert.equal(els.telemetryPager.classList.contains("d-none"), true);
    assert.equal(els.telemetryPagerSummary.textContent, "");
    assert.equal(els.telemetryPagerControls.innerHTML, "");
});

test("a marcação do paginador de telemetria, exactamente como está hoje", () => {
    const els = telemetryEls();
    initDeviceDetailView({els});
    state.telemetryPage = 1;

    // 30 eventos com um tamanho de página de 12 dão três páginas.
    renderTelemetryList(Array.from({length: 30}, (_, index) => telemetryEvent(index)));

    assert.equal(els.telemetryPager.classList.contains("d-none"), false);
    assert.equal(els.telemetryPagerSummary.textContent, "1–12 de 30");
    assert.equal(
        els.telemetryPagerControls.innerHTML,
        [
            '<button type="button" class="btn btn-outline-secondary btn-sm" data-action="telemetryPrev" disabled="" aria-label="Página anterior"><i class="fa-solid fa-chevron-left"></i></button>',
            '<button type="button" class="btn btn-primary btn-sm" data-action="telemetryPageGo" data-page="1" aria-current="page">1</button>',
            '<button type="button" class="btn btn-outline-secondary btn-sm" data-action="telemetryPageGo" data-page="2">2</button>',
            '<button type="button" class="btn btn-outline-secondary btn-sm" data-action="telemetryPageGo" data-page="3">3</button>',
            '<button type="button" class="btn btn-outline-secondary btn-sm" data-action="telemetryNext" aria-label="Página seguinte"><i class="fa-solid fa-chevron-right"></i></button>',
        ].join(""),
    );
});

test("na última página o resumo conta só o que resta e o seguinte fica travado", () => {
    const els = telemetryEls();
    initDeviceDetailView({els});
    state.telemetryPage = 3;

    renderTelemetryList(Array.from({length: 30}, (_, index) => telemetryEvent(index)));

    assert.equal(els.telemetryPagerSummary.textContent, "25–30 de 30");
    assert.match(
        els.telemetryPagerControls.innerHTML,
        /data-action="telemetryNext" disabled=""/,
    );
    state.telemetryPage = 1;
});

test("o paginador dos pedidos tem a mesma marcação, com o seu prefixo", () => {
    const els = downlinkEls();
    initDeviceDetailView({els});
    state.downlinkPage = 1;

    renderDownlinkRequests(Array.from({length: 30}, (_, index) => downlinkRequest(index)));

    assert.equal(els.downlinkPagerSummary.textContent, "1–12 de 30");
    assert.match(
        els.downlinkPagerControls.innerHTML,
        /data-action="downlinkPageGo" data-page="2"/,
    );
    assert.match(
        els.downlinkPagerControls.innerHTML,
        /data-action="downlinkPrev" disabled=""/,
    );
});
