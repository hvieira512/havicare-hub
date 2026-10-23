import test from "node:test";
import assert from "node:assert/strict";
import { JSDOM } from "jsdom";

import {
    readConfigPayload,
    renderConfigInputs,
} from "../../src/Dashboard/dashboard/devices/config/index.js";

/** O alarme de proximidade do gateway: modo, quem dispara, o limiar e os tempos. */

const ENTRY = {
    key: "proximity_alert",
    capabilityKey: "proximity_alert",
    input: "proximityAlarm",
    fields: ["mode", "devices", "triggerDbm", "minSamples", "cooldownSeconds"],
    options: { mode: [{ value: "off" }, { value: "calibrating" }, { value: "active" }] },
    label: "Alarme de proximidade",
};

const META = {
    protocol: "moko-mkgw4",
    bounds: { triggerDbm: [-100, -30], minSamples: [1, 10], cooldownSeconds: [0, 3600] },
    linkedDevices: [
        { deviceKey: "fbd87c59ba8b", model: "W6B", deviceType: "bracelet", image: "https://hub/model-images/w6.jpg" },
        { deviceKey: "eec5000202f9", model: "MECS-PRO", deviceType: "diaper_sensor", image: "" },
    ],
};

const DESIRED = { mode: "active", devices: ["fbd87c59ba8b"], triggerDbm: -55, minSamples: 3, cooldownSeconds: 60 };

function sectionFor(desired, meta = META) {
    const dom = new JSDOM(
        `<!doctype html><body><div data-config-section data-config-input="proximityAlarm">${renderConfigInputs(ENTRY, desired, meta)}</div></body>`,
    );

    return dom.window.document.querySelector("[data-config-section]");
}

test("the stored mode is the one lit", () => {
    const section = sectionFor(DESIRED);

    assert.equal(section.querySelector("[data-config-field=\"mode\"]:checked").value, "active");
});

test("each linked device is a card with its photo, id and model, chosen ones ticked", () => {
    const section = sectionFor(DESIRED);
    const cards = [...section.querySelectorAll("[data-config-field=\"devices\"]")];

    assert.deepEqual(cards.map((card) => card.value), ["fbd87c59ba8b", "eec5000202f9"]);
    assert.deepEqual(cards.map((card) => card.checked), [true, false]);
    assert.equal(section.querySelector("img").getAttribute("src"), "https://hub/model-images/w6.jpg");
    assert.match(section.textContent, /MECS-PRO/);
});

test("the threshold slider and its number share the field and the server's bounds", () => {
    const section = sectionFor(DESIRED);
    const twins = [...section.querySelectorAll("[data-config-field=\"triggerDbm\"]")];

    assert.deepEqual(twins.map((input) => input.type).sort(), ["number", "range"]);
    assert.ok(twins.every((input) => input.getAttribute("min") === "-100" && input.getAttribute("max") === "-30"));
    assert.ok(twins.every((input) => input.value === "-55"));
});

test("a chosen list reads back as the ids ticked", () => {
    assert.deepEqual(readConfigPayload(sectionFor(DESIRED)), DESIRED);
});

test("all reads back as all, whatever is ticked", () => {
    const section = sectionFor({ ...DESIRED, devices: "all" });

    assert.equal(section.querySelector("[data-config-field=\"devicesScope\"]:checked").value, "all");
    assert.equal(readConfigPayload(section).devices, "all");
});

test("a gateway with nothing linked says so instead of an empty list", () => {
    const section = sectionFor(DESIRED, { ...META, linkedDevices: [] });

    assert.equal(section.querySelectorAll("[data-config-field=\"devices\"]").length, 0);
    assert.match(section.textContent, /Nenhum dispositivo ligado a este gateway/);
});
