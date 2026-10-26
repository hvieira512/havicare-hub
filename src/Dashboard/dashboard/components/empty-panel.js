import { html } from "../core/html.js";

/** O estado vazio de um painel, em texto: uma segunda moldura dentro do cartão lê-se como conteúdo. */
export function emptyPanel(text) {
    return html`<div class="text-secondary py-3">${text}</div>`;
}
