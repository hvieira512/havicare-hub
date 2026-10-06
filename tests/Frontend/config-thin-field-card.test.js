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

test("o rótulo do campo desaparece", () => {
    assert.equal(sectionOf(LOCATION_INTERVAL).querySelectorAll("label").length, 0);
});

/** O protocolo da Wonlex diz que o `intervalTime` é em segundos, e que zero desliga. */
test("o intervalo de localização da Wonlex diz a unidade", () => {
    const section = sectionOf(LOCATION_INTERVAL);

    assert.match(section.textContent, /\bs\b/);
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

/** Quem envia uma definição é o rodapé da secção. */
test("uma definição não leva botão de enviar", () => {
    const section = sectionOf(LOCATION_INTERVAL);

    assert.equal(section.querySelectorAll("[data-action=\"saveConfig\"]").length, 0);
});

test("um campo estreito não leva botão de repor", () => {
    const section = sectionOf(LOCATION_INTERVAL);

    assert.equal(section.querySelectorAll("button[type=\"reset\"]").length, 0);
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
