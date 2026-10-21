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
        // A altura do cartão no modal, que o jsdom não calcula.
        Object.defineProperty(els[`${prefix}Chart`], "clientHeight", { value: 148 });
    }
    els.radarSleepState = document.createElement("div");
    els.radarSleepStateIcon = document.createElement("i");
    els.radarSleepStateLabel = document.createElement("span");
    return els;
}

const at = (second) => `2026-10-21T10:00:${String(second).padStart(2, "0")}Z`;
const heartRate = (second, bpm) => ({ type: "heart_rate", occurredAt: at(second), data: { bpm } });
const clock = (second) => new Date(at(second)).toLocaleTimeString("pt-PT");

/** O jsdom não tem disposição: a área do gráfico mede 300 por 100 a partir da origem. */
function hover(chart, x) {
    const plot = chart.querySelector(".radar-vital-plot");
    plot.getBoundingClientRect = () => ({ left: 0, top: 0, width: 300, height: 100, right: 300, bottom: 100 });
    plot.dispatchEvent(new window.MouseEvent("pointermove", { bubbles: true, clientX: x, clientY: 50 }));

    return chart.querySelector(".radar-vital-tooltip");
}

test("hovering the chart shows the time and value of the nearest reading", () => {
    const els = mountEls();
    renderVitals(els, [heartRate(30, 80), heartRate(0, 60), heartRate(10, 70)]);

    const tooltip = hover(els.radarHeartRateChart, 290);

    assert.equal(tooltip.hidden, false);
    assert.match(tooltip.textContent, new RegExp(clock(30)));
    assert.match(tooltip.textContent, /80 bpm/);
    assert.match(hover(els.radarHeartRateChart, 5).textContent, /60 bpm/);
});

test("leaving the chart hides the tooltip", () => {
    const els = mountEls();
    renderVitals(els, [heartRate(0, 60), heartRate(10, 70)]);
    const tooltip = hover(els.radarHeartRateChart, 10);

    els.radarHeartRateChart.querySelector(".radar-vital-plot").dispatchEvent(new window.MouseEvent("pointerleave"));

    assert.equal(tooltip.hidden, true);
});

/** O zero e a falta de leitura são o radar a dizer que não vê ninguém, e não um batimento. */
test("zero and missing readings are neither plotted nor offered by the tooltip", () => {
    const els = mountEls();
    renderVitals(els, [heartRate(0, 60), heartRate(10, 0), heartRate(15, null), heartRate(20, 70)]);

    assert.equal(els.radarHeartRateChart.querySelector(".radar-vital-line").getAttribute("d").match(/[MCL]/g).length, 2);
    assert.match(hover(els.radarHeartRateChart, 150).textContent, /(60|70) bpm/);
    assert.equal(els.radarHeartRateMin.textContent, "60");
});

test("the value axis is labelled in round steps around the readings", () => {
    const els = mountEls();
    renderVitals(els, [68, 70, 74, 77].map((bpm, i) => heartRate(i * 10, bpm)));

    const labels = [...els.radarHeartRateChart.querySelectorAll(".radar-vital-axis span")].map((label) => label.textContent);

    assert.deepEqual(labels, ["90", "80", "70", "60"]);
});

test("without readings there is no chart", () => {
    const els = mountEls();

    renderVitals(els, []);

    assert.equal(els.radarBreathRateChart.querySelector("path"), null);
    assert.equal(els.radarBreathRateChart.querySelector(".radar-vital-tooltip"), null);
});
