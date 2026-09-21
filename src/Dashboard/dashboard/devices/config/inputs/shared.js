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
