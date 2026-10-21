import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderVitals } from "../../src/Dashboard/dashboard/devices/radar-vitals.js";

const PREFIXES = ["radarHeartRate", "radarBreathRate"];

function mountEls() {
    const els = {};
    for (const prefix of PREFIXES) {
        for (const suffix of ["Chart", "Value", "Min", "Max", "Avg"]) {
            els[`${prefix}${suffix}`] = document.createElement("div");
        }
    }
    els.radarSleepState = document.createElement("div");
    els.radarSleepStateIcon = document.createElement("i");
    els.radarSleepStateLabel = document.createElement("span");
    return els;
}

const heartRate = (second, bpm) => ({
    type: "heart_rate",
    occurredAt: `2026-10-21T10:00:${String(second).padStart(2, "0")}Z`,
    data: { bpm },
});

test("the heart rate is drawn as a line through every reading", () => {
    const els = mountEls();

    renderVitals(els, [heartRate(30, 80), heartRate(0, 60), heartRate(10, 70)]);

    const line = els.radarHeartRateChart.querySelector("svg path:last-of-type");
    assert.ok(line, "o gráfico tem de ter a linha");
    assert.equal(line.getAttribute("d").match(/[MC]/g).length, 3);
    assert.match(els.radarHeartRateChart.querySelector("svg title").textContent, /60.*80 bpm/);
});

/** O zero é o radar a dizer que não vê ninguém, e não um batimento. */
test("a zero reading is left out of the line", () => {
    const els = mountEls();

    renderVitals(els, [heartRate(0, 60), heartRate(10, 0), heartRate(20, 70)]);

    assert.equal(els.radarHeartRateChart.querySelector("svg path:last-of-type").getAttribute("d").match(/[MC]/g).length, 2);
});

test("without readings there is no line", () => {
    const els = mountEls();

    renderVitals(els, []);

    assert.equal(els.radarBreathRateChart.querySelector("path"), null);
});
