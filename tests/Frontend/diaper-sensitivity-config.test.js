import test from "node:test";
import assert from "node:assert/strict";
import { JSDOM } from "jsdom";

import {
    readConfigPayload,
    renderConfigInputs,
} from "../../src/Dashboard/dashboard/devices/config/index.js";

/** Os três presets, os dois inteiros, e as gamas vindas do servidor em vez de escritas aqui. */

const ENTRY = {
    key: "diaper_sensitivity",
    capabilityKey: "diaper_sensitivity",
    input: "diaperSensitivity",
    fields: ["pollutionRange", "pollutionValue"],
    label: "Sensibilidade dos alertas",
};

const META = {
    presets: {
        low: { pollutionRange: 7, pollutionValue: 15 },
        normal: { pollutionRange: 4, pollutionValue: 12 },
        high: { pollutionRange: 3, pollutionValue: 7 },
    },
    bounds: { pollutionRange: [2, 10], pollutionValue: [5, 25] },
};

function sectionFor(desired) {
    const html = renderConfigInputs(ENTRY, desired, META);
    const dom = new JSDOM(
        `<!doctype html><body><div data-config-section data-config-input="diaperSensitivity">${html}</div></body>`,
    );

    return {
        section: dom.window.document.querySelector("[data-config-section]"),
        html,
    };
}

test("the three presets show, and the one matching the values is lit", () => {
    const { section } = sectionFor({ pollutionRange: 4, pollutionValue: 12 });
    const buttons = [...section.querySelectorAll("[data-config-preset]")];

    assert.equal(buttons.length, 3);
    const active = buttons.filter((button) => button.checked);
    assert.equal(active.length, 1);
    assert.deepEqual(JSON.parse(active[0].dataset.configPreset), META.presets.normal);
});

test("values that match no preset light no button", () => {
    // Sem botão «Personalizado»: nenhum preset activo já diz que os números são à medida.
    const { section } = sectionFor({ pollutionRange: 6, pollutionValue: 20 });

    assert.equal(section.querySelectorAll("[data-config-preset]:checked").length, 0);
});

test("the ranges come from the server, not from a frontend copy", () => {
    const { section } = sectionFor({ pollutionRange: 4, pollutionValue: 12 });
    const range = section.querySelector("[data-config-field=\"pollutionRange\"]");
    const value = section.querySelector("[data-config-field=\"pollutionValue\"]");

    assert.equal(range.getAttribute("min"), "2");
    assert.equal(range.getAttribute("max"), "10");
    assert.equal(value.getAttribute("min"), "5");
    assert.equal(value.getAttribute("max"), "25");
});

test("the block reads back as the pair the API expects", () => {
    const { section } = sectionFor({ pollutionRange: 3, pollutionValue: 7 });

    assert.deepEqual(readConfigPayload(section), {
        pollutionRange: 3,
        pollutionValue: 7,
    });
});
