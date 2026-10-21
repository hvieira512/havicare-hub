import assert from "node:assert/strict";
import test from "node:test";

import { parseFragment } from "./support/dom.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";

/**
 * Um campo estreito ocupa uma linha só, e do rótulo sobra a unidade ao lado do controlo; sem
 * rótulo visível, o campo continua a ter nome para quem o ouve.
 */
const LOCATION_INTERVAL = {
    key: "locationInterval",
    capabilityKey: "location_reporting_interval",
    command: "locationInterval",
    label: "Intervalo de localização",
    input: "number",
    fields: ["intervalSeconds"],
};

const sectionOf = (entry) => parseFragment(renderConfigSection("wonlex-json", entry, null));

test("a narrow-field setting draws the field and nothing else", () => {
    const section = sectionOf(LOCATION_INTERVAL);

    assert.ok(section.querySelector("input[type=\"number\"]"), "a definição devia desenhar o campo");
    assert.equal(section.querySelectorAll("button").length, 0);
});

test("the unit the label carried sits beside the control", () => {
    const section = sectionOf({
        key: "wonlexHeartRateInterval",
        capabilityKey: "heart_rate_measurement_interval",
        label: "Intervalo de frequência cardíaca",
        input: "number",
        fields: ["intervalMinutes"],
    });

    assert.match(section.textContent, /\bmin\b/);
});

test("the field still has a name for screen readers", () => {
    const input = sectionOf(LOCATION_INTERVAL).querySelector("input[type=\"number\"]");

    assert.equal(input.getAttribute("aria-label"), "Intervalo de localização");
});
