import {esc} from "../../format.js";
import {
    getDiaperSensitivity as apiGet,
    resetDiaperSensitivity as apiReset,
    saveDiaperSensitivity as apiSave,
} from "../../api/index.js";

/**
 * A sensibilidade dos alertas de um medidor de fraldas.
 *
 * A primeira regra do hub: nao viaja para o dispositivo, que e um beacon BLE que so
 * transmite. O que muda e a regra com que o hub deriva o estado da fralda a partir da
 * mesma leitura.
 *
 * Os dois inteiros sao a unica fonte de verdade e o nome do perfil e derivado deles, aqui
 * como no servidor: guardar perfil E valores permitia que discordassem.
 */

const NORMAL = {pollutionRange: 4, pollutionValue: 12};

const LEVELS = [
    {profile: "low", label: "Baixa", icon: "fa-feather-pointed", variant: "outline-success"},
    {profile: "normal", label: "Normal", icon: "fa-shield-heart", variant: "outline-warning"},
    {profile: "high", label: "Alta", icon: "fa-triangle-exclamation", variant: "outline-danger"},
    {profile: "custom", label: "Personalizado", icon: "fa-sliders", variant: "outline-secondary"},
];

export const key = "diaper_sensitivity";
export const name = "Sensibilidade dos alertas";

/** O estado que o bloco mostra: os valores em vigor, os presets e as gamas. */
export async function load(imei) {
    const result = await apiGet(imei);
    const data = result?.data;
    if (!data) {
        return {...NORMAL, profile: "normal", presets: {normal: NORMAL}, bounds: {}};
    }
    return data;
}

export function render(data) {
    const profile = data.profile || "custom";
    const bounds = data.bounds || {};
    const [rangeMin, rangeMax] = bounds.pollutionRange || [2, 10];
    const [valueMin, valueMax] = bounds.pollutionValue || [5, 25];

    return `
        <div class="btn-group w-100" role="group" aria-label="${esc(name)}" data-hub-rule-choice>
            ${LEVELS.map(
                (level) => `
                <button type="button" class="btn btn-${level.variant}${level.profile === profile ? " active" : ""}"
                    data-hub-rule-value="${esc(level.profile)}"
                    aria-pressed="${level.profile === profile ? "true" : "false"}">
                    <i class="fa-solid ${esc(level.icon)} me-2"></i>${esc(level.label)}
                </button>`,
            ).join("")}
        </div>
        <div class="row g-2 mt-2${profile === "custom" ? "" : " d-none"}" data-hub-rule-custom>
            <div class="col">
                <label class="form-label form-label-sm mb-1" for="diaperPollutionRange">Canais afetados</label>
                <input type="number" class="form-control" id="diaperPollutionRange" data-hub-rule-field="pollutionRange"
                    min="${rangeMin}" max="${rangeMax}" step="1" value="${esc(String(data.pollutionRange ?? ""))}">
                <div class="form-text">Quantos canais molhados obrigam a uma muda.</div>
            </div>
            <div class="col">
                <label class="form-label form-label-sm mb-1" for="diaperPollutionValue">Limiar por canal</label>
                <input type="number" class="form-control" id="diaperPollutionValue" data-hub-rule-field="pollutionValue"
                    min="${valueMin}" max="${valueMax}" step="1" value="${esc(String(data.pollutionValue ?? ""))}">
                <div class="form-text">A partir de quanto um canal conta como molhado.</div>
            </div>
        </div>
        <div class="form-text">O sensor apenas transmite e nada lhe é enviado. Passa a valer na leitura seguinte.</div>`;
}

/** O que esta escolhido no bloco, lido do DOM. */
export function read(root, data) {
    const active = root.querySelector("[data-hub-rule-value].active");
    const profile = active?.dataset.hubRuleValue || "normal";
    const preset = (data.presets || {})[profile];
    if (preset) return {profile, ...preset};

    return {
        profile,
        pollutionRange: Number(root.querySelector('[data-hub-rule-field="pollutionRange"]')?.value),
        pollutionValue: Number(root.querySelector('[data-hub-rule-field="pollutionValue"]')?.value),
    };
}

/** Recusa antes de sair do browser: isto decide alarmes de muda. */
export function validate(selection) {
    return Number.isInteger(selection.pollutionRange)
        && Number.isInteger(selection.pollutionValue)
        ? null
        : "Indique dois números inteiros.";
}

/**
 * O preset normal grava-se por remocao: a ausencia de linha JA significa normal, e uma
 * linha a dizer o mesmo era estado a manter sem necessidade.
 */
export async function save(imei, selection) {
    const {pollutionRange, pollutionValue} = selection;
    const result = selection.profile === "normal"
        ? await apiReset(imei)
        : await apiSave(imei, {pollutionRange, pollutionValue});

    return result?.error ? (result.error.message || result.error.code) : null;
}

/** Repor poe no preset normal. Nao grava: o Guardar ao lado e que confirma. */
export const resetProfile = "normal";
