import { html } from "../html.js";

/**
 * O estado vazio de um painel, em texto e não em caixa: dentro de um cartão branco, uma
 * segunda moldura cinzenta a dizer que não há nada lê-se como conteúdo.
 */
export function emptyPanel(text) {
    return html`<div class="text-secondary py-3">${text}</div>`;
}
