import { html } from "../../../html.js";
import { boolValue } from "../normalizers.js";

/** Só tem de ser único dentro da página. */
let uidCounter = 0;

export function nextUid(prefix) {
    uidCounter += 1;
    return `${prefix}-${uidCounter}`;
}

/** O `ariaLabel` serve os cartões sem rótulo visível, que têm o nome no título da linha. */
export const numberField = (
    configField,
    value,
    { min = 0, max = "", step = 1, ariaLabel = "", unit = "", cls = "" } = {},
) => {
    const maxAttr = max === "" ? "" : html` max="${max}"`;
    const ariaAttr = ariaLabel === "" ? "" : html` aria-label="${ariaLabel}"`;
    const input = html`<input class="form-control" type="number" min="${min}"${maxAttr} step="${step}" data-config-field="${configField}"${ariaAttr} value="${String(value)}">`;

    return unit === ""
        ? input
        : html`<div class="input-group${cls ? ` ${cls}` : ""}">${input}<span class="input-group-text">${unit}</span></div>`;
};

export function enabledSwitch(enabled, cls = "") {
    return html`
        <div class="form-check form-switch${cls ? ` ${cls}` : ""}">
            <input class="form-check-input" type="checkbox" role="switch" data-config-field="enabled" ${enabled ? "checked" : ""}>
            <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
        </div>`;
}

/** Um interruptor e o intervalo dele, sem rótulo: o nome está na linha de cima. */
export function intervalToggle(desired, field, { fallback, unit, bounds = {} }) {
    return html`
        <div class="d-flex align-items-center gap-3">
            ${enabledSwitch(boolValue(desired.enabled, true))}
            ${numberField(field, desired[field] ?? fallback, {
                ...bounds,
                unit,
                ariaLabel: "Intervalo",
                cls: "flex-nowrap w-auto ms-auto",
            })}
        </div>`;
}

export function syncSwitchLabel(input) {
    const label = input.parentElement?.querySelector("[data-switch-label]");
    if (!label) return;

    label.textContent = input.checked
        ? label.dataset.switchOn || "Ligado"
        : label.dataset.switchOff || "Desligado";
}

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
    // Um preset preenche vários campos de uma vez; nenhum activo quer dizer valores próprios.
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
