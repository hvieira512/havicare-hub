import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceDetailView, renderDownlinkRequests } = await import(
    "../../src/Dashboard/dashboard/devices/detail.js",
);

/**
 * A divisão a meio do painel de atividade. Os radares, os gateways e os medidores de fralda
 * não recebem pedido nenhum, e metade do cartão dizia permanentemente que não havia pedidos
 * enquanto a lista ao lado cortava "Alarme de sinais vit…" numa coluna de 34%.
 *
 * O que estes testes prendem é que a divisão segue o conteúdo, e não o contrário.
 */
function column(...classes) {
    const element = document.createElement("div");
    element.className = ["col-12", ...classes].join(" ");
    return element;
}

function detailEls() {
    return {
        downlinkRequestCount: document.createElement("div"),
        downlinkRequests: document.createElement("div"),
        downlinkPager: document.createElement("div"),
        downlinkPagerSummary: document.createElement("div"),
        downlinkPagerControls: document.createElement("ul"),
        telemetryColumn: column("col-xl-6", "pe-xl-4"),
        downlinkColumn: column("col-xl-6", "ps-xl-4"),
    };
}

const downlinkRequest = {
    feature: "heart_rate",
    status: "sent",
    requestedAt: "2026-08-25T11:04:00Z",
    occurredAt: "2026-08-25T11:04:00Z",
};

test("sem pedidos, os eventos ficam com a linha toda", () => {
    const els = detailEls();
    initDeviceDetailView({ els });
    state.downlinkPage = 1;

    renderDownlinkRequests([]);

    assert.equal(els.downlinkColumn.classList.contains("d-none"), true);
    assert.equal(els.telemetryColumn.classList.contains("col-xl-6"), false);
    assert.equal(els.telemetryColumn.classList.contains("pe-xl-4"), false);
    // A coluna nunca deixa de ser uma coluna da linha: só deixa de ser metade dela.
    assert.equal(els.telemetryColumn.classList.contains("col-12"), true);
});

test("com pedidos, volta a divisão a meio", () => {
    const els = detailEls();
    initDeviceDetailView({ els });
    state.downlinkPage = 1;

    renderDownlinkRequests([downlinkRequest]);

    assert.equal(els.downlinkColumn.classList.contains("d-none"), false);
    assert.equal(els.telemetryColumn.classList.contains("col-xl-6"), true);
    assert.equal(els.telemetryColumn.classList.contains("pe-xl-4"), true);
});

test("desenhar duas vezes o mesmo não muda a largura", () => {
    // O detalhe redesenha-se a cada mensagem do stream, e o painel não pode saltar de
    // largura por isso.
    const els = detailEls();
    initDeviceDetailView({ els });
    state.downlinkPage = 1;

    renderDownlinkRequests([downlinkRequest]);
    const first = els.telemetryColumn.className;
    renderDownlinkRequests([downlinkRequest]);

    assert.equal(els.telemetryColumn.className, first);
});
