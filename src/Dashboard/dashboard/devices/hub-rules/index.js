import {esc} from "../../format.js";
import {hubRulesFor} from "../../domain.js";
import * as diaperSensitivity from "./diaper-sensitivity.js";

/**
 * As regras do hub: configuracoes que nao viajam para o dispositivo.
 *
 * Ficam no mesmo separador das configuracoes por downlink, e nao num separador proprio.
 * Nomear separadores por "tem downlink ou nao" era nomear pela implementacao e obrigava a
 * pessoa a saber a arquitectura para escolher onde clicar -- ambas sao configuracao. O que
 * difere e se a alteracao tem de viajar, e isso e uma propriedade DE CADA regra: o bloco
 * de uma que viaja mostra estado de entrega e diz "Enviar"; o de uma que nao viaja diz
 * "Aplicada no hub" e "Guardar".
 *
 * Cada regra e um modulo com a mesma forma pequena -- `load`, `render`, `read`, `save` --
 * e este ficheiro nao sabe nada de fraldas. Os limiares da detecao de queda, quando
 * chegarem, sao um segundo ficheiro nesta pasta e uma entrada no `hubRules` da pulseira.
 */

const RULES = {
    [diaperSensitivity.key]: diaperSensitivity,
};

/** O estado de cada regra do dispositivo, carregado em paralelo. */
export async function loadHubRules(imei, deviceType) {
    const keys = hubRulesFor(deviceType).filter((key) => RULES[key]);
    const loaded = await Promise.all(
        keys.map(async (key) => [key, await RULES[key].load(imei)]),
    );

    return Object.fromEntries(loaded);
}

export function hasHubRules(deviceType) {
    return hubRulesFor(deviceType).some((key) => RULES[key]);
}

/**
 * Os blocos, para o painel de configuracoes os colocar antes dos downlinks.
 *
 * @param {Record<string, object>} loaded o que o loadHubRules devolveu
 * @param {Record<string, {message: string, tone: string}>} feedback por chave de regra
 */
export function renderHubRules(deviceType, loaded, feedback = {}) {
    return hubRulesFor(deviceType)
        .filter((key) => RULES[key] && loaded[key])
        .map((key) => renderRule(RULES[key], loaded[key], feedback[key]))
        .join("");
}

function renderRule(rule, data, feedback) {
    return `
        <section class="border rounded p-3 mb-3" data-hub-rule="${esc(rule.key)}">
            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                <span class="fw-semibold">${esc(rule.name)}</span>
                <span class="hub-rule-state">
                    <span class="hub-rule-dot"></span>Aplicada no hub
                </span>
            </div>
            ${rule.render(data)}
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-primary btn-sm" data-action="saveHubRule" data-hub-rule-key="${esc(rule.key)}">
                    <i class="fa-solid fa-floppy-disk me-2"></i>Guardar
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="resetHubRule"
                    data-hub-rule-key="${esc(rule.key)}" title="Repor" aria-label="Repor">
                    <i class="fa-solid fa-rotate-left"></i>
                </button>
            </div>
            ${feedback?.message
                ? `<div class="alert alert-${esc(feedback.tone)} fade show small mt-3 mb-0 py-2 px-3" role="alert">${esc(feedback.message)}</div>`
                : ""}
        </section>`;
}

/** Marca o nivel escolhido dentro de um bloco, sem redesenhar o painel todo. */
export function selectHubRuleValue(block, value) {
    for (const button of block.querySelectorAll("[data-hub-rule-value]")) {
        const active = button.dataset.hubRuleValue === value;
        button.classList.toggle("active", active);
        button.setAttribute("aria-pressed", active ? "true" : "false");
    }
    block
        .querySelector("[data-hub-rule-custom]")
        ?.classList.toggle("d-none", value !== "custom");
}

export function ruleFor(key) {
    return RULES[key] ?? null;
}
