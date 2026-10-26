import test from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";
import { cacheElements } from "../../src/Dashboard/dashboard/core/dom.js";

const { setSelectedDetail, state } = await import("../../src/Dashboard/dashboard/core/state.js");
const { renderSelection } = await import(
    "../../src/Dashboard/dashboard/devices/detail/detail.js",
);

/** Antes do primeiro `snapshot` do stream não se sabe nada das ligações, nem que não há. */

function detailEls() {
    const tags = { telemetryColumnTab: "button", detailFilterType: "select" };
    return autoEls((name) => tags[name] || "div");
}

const detail = () => ({
    device: { imei: "351266770073676", online: true },
    model: { supplier: "4P Touch", internalModel: "Y6M", deviceType: "watch" },
    capabilities: {},
});

function connectionSection(els) {
    const section = document.createElement("section");
    section.appendChild(els.connectionHistory);
    return section;
}

test("while the history has not arrived, the connections section does not say «sem registo»", () => {
    const els = detailEls();
    const section = connectionSection(els);
    cacheElements(els);
    setSelectedDetail(detail());

    renderSelection();
    assert.equal(section.classList.contains("d-none"), true);

    state.selectedDetail.recent = {
        telemetry: [],
        events: [],
        connections: [{ type: "device.connected", occurredAt: new Date().toISOString() }],
        commands: [],
    };
    renderSelection();
    assert.equal(section.classList.contains("d-none"), false);
});

test("the band reads the connections from their own list", () => {
    const els = detailEls();
    const section = connectionSection(els);
    cacheElements(els);
    setSelectedDetail(detail());
    const at = (hoursAgo) => new Date(Date.now() - hoursAgo * 3600000).toISOString();

    state.selectedDetail.recent = {
        telemetry: [],
        events: [],
        connections: [
            { type: "device.connected", occurredAt: at(1) },
            { type: "device.disconnected", occurredAt: at(2) },
        ],
        commands: [],
    };
    renderSelection();

    assert.equal(section.classList.contains("d-none"), false);
    assert.equal(els.connectionHistory.querySelectorAll(".connection-band-gap").length, 1);
});

test("a device silent since before the history shows the gap since it last spoke", () => {
    const els = detailEls();
    const section = connectionSection(els);
    cacheElements(els);
    const lastSeenAt = new Date(Date.now() - 6 * 86400000).toISOString();
    setSelectedDetail({ ...detail(), device: { imei: "5948069B8E87", online: false, lastSeenAt } });
    state.selectedDetail.recent = { telemetry: [], events: [], connections: [], commands: [] };

    renderSelection();

    assert.equal(section.classList.contains("d-none"), false);
    assert.equal(els.connectionHistory.querySelectorAll(".connection-band-gap").length, 1);
    assert.match(els.connectionHistory.textContent, /0 %/);
});

test("an offline device whose last record is «connected» ends the band in a gap since it went silent", () => {
    const els = detailEls();
    connectionSection(els);
    cacheElements(els);
    const at = (hoursAgo) => new Date(Date.now() - hoursAgo * 3600000).toISOString();
    setSelectedDetail({ ...detail(), device: { imei: "c5e390f30bce", online: false, lastSeenAt: at(24) } });
    state.selectedDetail.recent = {
        telemetry: [],
        events: [],
        connections: [{ type: "device.connected", occurredAt: at(48) }],
        commands: [],
    };

    renderSelection();

    assert.equal(els.connectionHistory.querySelectorAll(".connection-band-gap").length, 1);
    assert.match(els.connectionHistory.textContent, /(49,9|50) %/);
});
