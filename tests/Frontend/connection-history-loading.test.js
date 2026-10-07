import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { setSelectedDetail, state } = await import("../../src/Dashboard/dashboard/state.js");
const { initDeviceDetailView, renderSelection } = await import(
    "../../src/Dashboard/dashboard/devices/detail.js",
);

/** Antes do primeiro `snapshot` do stream não se sabe nada das ligações, nem que não há. */

function detailEls() {
    const tags = { telemetryColumnTab: "button", detailFilterType: "select" };
    return new Proxy({}, {
        get: (cache, id) => {
            cache[id] ??= document.createElement(tags[id] || "div");
            return cache[id];
        },
    });
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

test("enquanto o histórico não chega, a secção das ligações não diz «sem registo»", () => {
    const els = detailEls();
    const section = connectionSection(els);
    initDeviceDetailView({ els });
    setSelectedDetail(detail());

    renderSelection();
    assert.equal(section.classList.contains("d-none"), true);

    state.selectedDetail.recent = {
        telemetry: [],
        events: [{ payload: { type: "device.connected", occurredAt: new Date().toISOString() } }],
        commands: [],
    };
    renderSelection();
    assert.equal(section.classList.contains("d-none"), false);
});
