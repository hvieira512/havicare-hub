import { html, raw } from "../html.js";

/**
 * Uma etiqueta com o seu controlo, e a linha de ajuda por baixo quando existe.
 *
 * O controlo entra como HTML já pronto e passa pelo `raw()`; a etiqueta e a ajuda entram
 * como texto e saem escapadas.
 *
 * Vive aqui e não no `devices/config/inputs/shared.js` porque não sabe nada de dispositivos
 * nem de `data-config-*`: é o esqueleto de um campo de formulário.
 */
export function field(label, control, { help = "", cls = "", required = false } = {}) {
    const classAttribute = cls ? html` class="${cls}"` : "";
    const helpLine = help ? html`<div class="form-text">${help}</div>` : "";
    return html`
        <div${raw(classAttribute)}>
            <label class="form-label-sm${required ? " required" : ""}">${label}</label>
            ${raw(control)}
            ${raw(helpLine)}
        </div>`;
}
