import test from "node:test";
import assert from "node:assert/strict";

const { state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceDetailView, renderDownlinkRequests } = await import(
    "../../src/Dashboard/dashboard/devices/detail.js",
);

/** Radares, gateways e medidores de fralda não recebem pedidos: o painel deles sai. */
function detailEls() {
    return {
        downlinkRequestCount: document.createElement("div"),
        downlinkTabCount: document.createElement("div"),
        deviceTabRequestsCount: document.createElement("div"),
        deviceTabRequests: document.createElement("button"),
        deviceTabReadings: document.createElement("button"),
        downlinkRequests: document.createElement("div"),
        activityTabs: document.createElement("div"),
        telemetryColumnTab: document.createElement("button"),
        downlinkPager: document.createElement("div"),
        downlinkPagerSummary: document.createElement("div"),
        downlinkPagerControls: document.createElement("ul"),
        telemetryColumn: document.createElement("div"),
        downlinkColumn: document.createElement("div"),
    };
}

const downlinkRequest = {
    feature: "heart_rate",
    status: "sent",
    requestedAt: "2026-08-25T11:04:00Z",
    occurredAt: "2026-08-25T11:04:00Z",
};

const hidden = (element) => element.classList.contains("d-none");

test("without requests the request column, ruler and tab go away", () => {
    const els = detailEls();
    initDeviceDetailView({ els });
    state.downlinkPage = 1;

    renderDownlinkRequests([]);

    assert.equal(hidden(els.downlinkColumn), true);
    assert.equal(hidden(els.activityTabs), true);
    assert.equal(hidden(els.deviceTabRequests), true);
});

test("with requests all three come back", () => {
    const els = detailEls();
    initDeviceDetailView({ els });
    state.downlinkPage = 1;

    renderDownlinkRequests([]);
    renderDownlinkRequests([downlinkRequest]);

    assert.equal(hidden(els.downlinkColumn), false);
    assert.equal(hidden(els.activityTabs), false);
    assert.equal(hidden(els.deviceTabRequests), false);
});
