import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { defaultConfigPayload, renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";

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
    fields: ["intervalTime"],
};

const sectionOf = (entry) => parseFragment(renderConfigSection("wonlex-json", entry, null));

test("uma definição de campo estreito desenha o campo e mais nada", () => {
    const section = sectionOf(LOCATION_INTERVAL);

    assert.ok(section.querySelector("input[type=\"number\"]"), "a definição devia desenhar o campo");
    assert.equal(section.querySelectorAll("button").length, 0);
});

test("a unidade que o rótulo carregava fica ao lado do controlo", () => {
    const section = sectionOf({
        key: "wonlexHeartRateInterval",
        capabilityKey: "heart_rate_measurement_interval",
        label: "Intervalo de frequência cardíaca",
        input: "number",
        fields: ["interval"],
    });

    assert.match(section.textContent, /\bmin\b/);
});

test("o campo continua a ter nome para quem não o vê", () => {
    const input = sectionOf(LOCATION_INTERVAL).querySelector("input[type=\"number\"]");

    assert.equal(input.getAttribute("aria-label"), "Intervalo de localização");
});

/** O tom de pele da Veepoo vai de 1 a 6, e o aparelho recusa o zero. */
test("o valor por omissão respeita o mínimo que a definição declara", () => {
    const skinTone = {
        key: "skin_tone",
        label: "Tom de pele",
        input: "number",
        fields: ["level"],
        options: { min: 1, max: 6 },
    };

    assert.deepEqual(defaultConfigPayload(skinTone, "veepoo-ble"), { level: 1 });
});
