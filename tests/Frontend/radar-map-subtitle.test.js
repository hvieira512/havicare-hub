import test from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";

const { initRadarMapModal, openRadarMap } =
    await import("../../src/Dashboard/dashboard/devices/radar-map-modal.js");

test("the radar map subtitle shows the identifier once escaped, not twice", () => {
    const els = autoEls();
    initRadarMapModal({ els, modals: { radarMap: { show() {} } } });

    void openRadarMap("AD8A&613B").catch(() => {});

    assert.equal(els.radarMapSubtitle.textContent, "AD8A&613B");
});
