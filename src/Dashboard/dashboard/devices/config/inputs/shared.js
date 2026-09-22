import { esc } from "../../../format.js";

/**
 * As peças que os campos de todos os fornecedores partilham: um contador de identificadores,
 * um campo numérico, o interruptor que acompanha um valor, os botões de escolha e a hora de
 * 24 horas -- a marcação que as desenha e o comportamento que as mantém coerentes.
 */

/** Cresce por processo. Só tem de ser único dentro da página, não estável entre carregamentos. */
let uidCounter = 0;

export function nextUid(prefix) {
    uidCounter += 1;
    return `${prefix}-${uidCounter}`;
}

/**
 * O `ariaLabel` serve os cartões que não desenham rótulo visível: o nome da definição está
 * no título da linha, e um campo sem nome nenhum não se lê a um leitor de ecrã.
 */
export const numberField = (configField, value, { min = 0, max = "", step = 1, ariaLabel = "" } = {}) =>
    `<input class="form-control" type="number" min="${min}"${max === "" ? "" : ` max="${max}"`} step="${step}" data-config-field="${esc(configField)}"${ariaLabel === "" ? "" : ` aria-label="${esc(ariaLabel)}"`} value="${esc(String(value))}">`;

export function enabledSwitch(enabled, cls = "") {
    return `
        <div class="form-check form-switch${cls ? ` ${cls}` : ""}">
            <input class="form-check-input" type="checkbox" role="switch" data-config-field="enabled" ${enabled ? "checked" : ""}>
            <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
        </div>`;
}

/** A etiqueta ao lado do interruptor diz em que estado ele está. */
export function syncSwitchLabel(input) {
    const label = input.parentElement?.querySelector("[data-switch-label]");
    if (!label) return;

    label.textContent = input.checked
        ? label.dataset.switchOn || "Ligado"
        : label.dataset.switchOff || "Desligado";
}

/** Escreve cada campo do preset e recalcula qual dos botões fica aceso. */
function applyConfigPreset(section, button) {
    let preset;
    try {
        preset = JSON.parse(button.dataset.configPreset);
    } catch {
        return;
    }

    for (const [field, value] of Object.entries(preset || {})) {
        const input = section.querySelector(`[data-config-field="${field}"]`);
        if (input) input.value = String(value);
    }

    const group = button.closest("[data-config-choice-group]");
    for (const choice of group?.querySelectorAll("[data-config-preset]") || []) {
        const active = choice === button;
        choice.classList.toggle("active", active);
        choice.setAttribute("aria-pressed", active ? "true" : "false");
    }
}

export function updateConfigChoice(section, button) {
    // Um preset preenche mais do que um campo de uma vez -- a sensibilidade das fraldas são
    // dois inteiros --, e o botão carrega o par em vez de um valor só. O estado activo lê-se
    // dos campos: nenhum preset activo já diz que os valores não são de nenhum deles.
    if (button.dataset.configPreset) {
        applyConfigPreset(section, button);
        return;
    }

    const field = String(button.dataset.configField || "");
    if (!field) return;

    const value = String(button.dataset.configValue || "");
    const input = section.querySelector(`[data-config-field="${field}"]`);
    if (!input) return;

    input.value = value;

    const group = button.closest("[data-config-choice-group]");
    if (!group) return;

    const buttons = group.querySelectorAll(
        "[data-action=\"selectConfigChoice\"]",
    );
    buttons.forEach((choice) => {
        const selected =
            String(choice.dataset.configField || "") === field &&
            String(choice.dataset.configValue || "") === value;
        choice.classList.toggle("active", selected);
        choice.setAttribute("aria-pressed", selected ? "true" : "false");
    });
}

/** Põe os dois pontos à medida que se escreve num campo `data-time-format="24h"`. */
export function normalizeTwentyFourHourTimeInput(input) {
    if (!(input instanceof HTMLInputElement)) {
        return;
    }
    const digits = String(input.value || "").replace(/[^0-9]/g, "").slice(0, 4);
    if (digits.length === 0) {
        input.value = "";
        return;
    }
    if (digits.length <= 2) {
        input.value = digits;
        return;
    }
    input.value = `${digits.slice(0, 2)}:${digits.slice(2)}`;
}
