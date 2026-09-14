import { esc } from "../../../format.js";

/**
 * As peças que os campos de todos os fornecedores partilham: um contador de identificadores,
 * um campo numérico e o interruptor que acompanha um valor.
 */

/** Cresce por processo. Só tem de ser único dentro da página, não estável entre carregamentos. */
let uidCounter = 0;

export function nextUid(prefix) {
    uidCounter += 1;
    return `${prefix}-${uidCounter}`;
}

export const numberField = (configField, value, { min = 0, max = "", step = 1 } = {}) =>
    `<input class="form-control" type="number" min="${min}"${max === "" ? "" : ` max="${max}"`} step="${step}" data-config-field="${esc(configField)}" value="${esc(String(value))}">`;

export function enabledSwitch(enabled, cls = "") {
    return `
        <div class="form-check form-switch${cls ? ` ${cls}` : ""}">
            <input class="form-check-input" type="checkbox" role="switch" data-config-field="enabled" ${enabled ? "checked" : ""}>
            <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
        </div>`;
}
