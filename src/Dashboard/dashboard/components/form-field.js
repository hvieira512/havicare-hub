import { html } from "../html.js";

/**
 * Uma etiqueta com o seu controlo e a ajuda por baixo. O controlo vem construído com `html`:
 * o que chegar em texto sai escapado.
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
