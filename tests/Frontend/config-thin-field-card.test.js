import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { defaultConfigPayload, renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";

/**
 * Uma definição de um campo estreito -- um número, uma lista curta -- gastava quatro linhas
 * para uma escolha: o título, o rótulo do campo, o campo, e uma linha só para os botões. O
 * rótulo repetia o título, e a linha de ajuda repetia-se de cartão para cartão.
 *
 * A unidade é a parte do rótulo que não se pode perder: de «Intervalo (min)» sobrevive o
 * «min», ao lado do controlo. E sem rótulo visível o campo tem de continuar a ter nome para
 * quem o ouve em vez de o ver.
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

test("o controlo e o envio ficam na linha do título", () => {
    const section = sectionOf(LOCATION_INTERVAL);
    const input = section.querySelector("input[type=\"number\"]");
    const button = section.querySelector("[data-action=\"saveConfig\"]");

    assert.ok(input, "o cartão devia desenhar o campo");
    const row = input.closest("div").parentElement;
    assert.ok(row.querySelector(".fw-semibold"), "o campo devia partilhar a linha com o nome");
    assert.ok(row.contains(button), "o envio devia estar na mesma linha");
});

test("o rótulo do campo desaparece", () => {
    assert.equal(sectionOf(LOCATION_INTERVAL).querySelectorAll("label").length, 0);
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

/** O verbo é das acções. Uma definição guarda-se, e o que o botão faz é enviá-la. */
test("o botão de uma definição continua a dizer «Enviar»", () => {
    const button = sectionOf(LOCATION_INTERVAL).querySelector("[data-action=\"saveConfig\"]");

    assert.equal(button.textContent.trim(), "Enviar");
});

test("um campo estreito não leva botão de repor", () => {
    const section = sectionOf(LOCATION_INTERVAL);

    assert.equal(section.querySelectorAll("button[type=\"reset\"]").length, 0);
});

/**
 * O tom de pele da Veepoo vai de 1 a 6. O valor por omissão era sempre zero, e o cartão
 * abria com um número que o aparelho recusa.
 */
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
