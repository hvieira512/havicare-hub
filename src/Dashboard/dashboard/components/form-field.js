import { html } from "../html.js";

/**
 * Uma etiqueta com o seu controlo, e a linha de ajuda por baixo quando existe.
 *
 * O controlo tem de vir construído com `html`: o que chegar em texto sai escapado, como a
 * etiqueta e a ajuda.
 *
 * Vive aqui e não no `devices/config/inputs/shared.js` porque não sabe nada de dispositivos
 * nem de `data-config-*`: é o esqueleto de um campo de formulário.
 */
export function field(label, control, { help = "", cls = "", required = false } = {}) {
    const classAttribute = cls ? html` class="${cls}"` : "";
    const helpLine = help ? html`<div class="form-text">${help}</div>` : "";
    return html`
        <div${classAttribute}>
            <label class="form-label-sm${required ? " required" : ""}">${label}</label>
            ${control}
            ${helpLine}
        </div>`;
}
