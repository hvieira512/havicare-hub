import test from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";
import { cacheElements } from "../../src/Dashboard/dashboard/dom.js";

const { setSelectedDetail, state } = await import("../../src/Dashboard/dashboard/state.js");
const { renderSelection } = await import("../../src/Dashboard/dashboard/devices/detail/detail.js");

/** Uma frame do stream que não muda os cartões não pode desligar as tooltips deles. */

const instances = new Map();
window.bootstrap = {
    Tooltip: {
        getOrCreateInstance: (element) => {
            if (!instances.has(element)) {
                instances.set(element, { dispose: () => instances.delete(element) });
            }
            return instances.get(element);
        },
        getInstance: (element) => instances.get(element) ?? null,
    },
};

test("an unchanged frame keeps the summary card tooltips alive", () => {
    const els = autoEls((name) => ({ telemetryColumnTab: "button", detailFilterType: "select" }[name] || "div"));
    cacheElements(els);
    state.protocols = [{ protocol: "test-watch", helpCallPressModes: ["single"] }];
    setSelectedDetail({
        device: { imei: "351266770073676", online: true },
        model: { supplier: "4P Touch", internalModel: "Y6M", deviceType: "watch", protocol: "test-watch" },
        capabilities: {},
    });
    state.selectedDetail.recent = {
        telemetry: [],
        events: [{ seq: 1, type: "help_call", severity: "danger", occurredAt: "2026-10-26T10:00:00Z", data: { pressType: "single" } }],
        commands: [],
    };

    renderSelection();
    renderSelection();

    const target = els.requestGrid.querySelector("[data-bs-toggle=\"tooltip\"]");
    assert.ok(target, "o cartão das chamadas de ajuda devia ter tooltip");
    assert.ok(instances.has(target), "a tooltip devia continuar ligada");
});
