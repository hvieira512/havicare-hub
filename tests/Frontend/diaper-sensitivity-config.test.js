import test from "node:test";
import assert from "node:assert/strict";
import {readFileSync} from "node:fs";
import {JSDOM} from "jsdom";

// Tem de vir antes dos modulos do dashboard: o api/http.js toca em window ao carregar.
import "./support/browser-env.js";
import {
    readConfigPayload,
    renderConfigInputs,
} from "../../src/Dashboard/dashboard/devices/config/index.js";

/**
 * A sensibilidade dos alertas de um medidor de fraldas, no painel de configuração.
 *
 * Teve tabela, três rotas e um módulo de frontend só para si, com um vocabulário próprio
 * -- "Aplicada no hub" -- para dizer o que a via genérica já diz com "Aplicado". Não era
 * uma capacidade porque o pipeline não sabia exprimir uma configuração que não viaja.
 *
 * Agora é um bloco de configuração como os outros. O que estes testes prendem é o que a
 * mudança tinha de preservar: os três presets, os dois inteiros, e as gamas vindas do
 * servidor em vez de escritas aqui.
 */

const MODAL = readFileSync(
    new URL("../../src/Dashboard/components/modals/device.php", import.meta.url),
    "utf8",
);

const ENTRY = {
    key: "diaper_sensitivity",
    input: "diaperSensitivity",
    fields: ["pollutionRange", "pollutionValue"],
    label: "Sensibilidade dos alertas",
};

const META = {
    presets: {
        low: {pollutionRange: 7, pollutionValue: 15},
        normal: {pollutionRange: 4, pollutionValue: 12},
        high: {pollutionRange: 3, pollutionValue: 7},
    },
    bounds: {pollutionRange: [2, 10], pollutionValue: [5, 25]},
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

test("não há separador nem marcação estática de regras do hub", () => {
    assert.doesNotMatch(MODAL, /Regras do hub/);
    assert.doesNotMatch(MODAL, /deviceHubRulesTabBtn/);
    assert.doesNotMatch(MODAL, /deviceDiaperSensitivityRow/);
});

test("os três presets aparecem, e o que bate certo com os valores fica aceso", () => {
    const {section} = sectionFor({pollutionRange: 4, pollutionValue: 12});
    const buttons = [...section.querySelectorAll("[data-config-preset]")];

    assert.equal(buttons.length, 3);
    const active = buttons.filter((button) => button.classList.contains("active"));
    assert.equal(active.length, 1);
    assert.deepEqual(JSON.parse(active[0].dataset.configPreset), META.presets.normal);
});

test("valores que não são de nenhum preset não acendem nenhum botão", () => {
    // O quarto botão "Personalizado" do bloco anterior deixa de ser preciso: o estado
    // lê-se dos números, e nenhum preset activo já diz que são à medida.
    const {section} = sectionFor({pollutionRange: 6, pollutionValue: 20});

    assert.equal(section.querySelectorAll("[data-config-preset].active").length, 0);
});

test("as gamas vêm do servidor e não de uma cópia no frontend", () => {
    const {section} = sectionFor({pollutionRange: 4, pollutionValue: 12});
    const range = section.querySelector('[data-config-field="pollutionRange"]');
    const value = section.querySelector('[data-config-field="pollutionValue"]');

    assert.equal(range.getAttribute("min"), "2");
    assert.equal(range.getAttribute("max"), "10");
    assert.equal(value.getAttribute("min"), "5");
    assert.equal(value.getAttribute("max"), "25");
});

test("o bloco lê-se de volta como o par que a API espera", () => {
    const {section} = sectionFor({pollutionRange: 3, pollutionValue: 7});

    assert.deepEqual(readConfigPayload(section), {
        pollutionRange: 3,
        pollutionValue: 7,
    });
});
