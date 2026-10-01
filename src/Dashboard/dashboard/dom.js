/**
 * Todos os elementos com `id`, por `id`.
 *
 * A casca é servida inteira pelo PHP e isto corre no `DOMContentLoaded`, por isso não há
 * elemento por nascer. Um `id` renomeado na marcação e não aqui rebenta o arranque, que é o
 * que se quer; quem o apanha antes do browser é o `DashboardElementIdsTest`.
 */
export function cacheElements() {
    const els = {};
    for (const el of document.querySelectorAll("[id]")) els[el.id] = el;
    return els;
}

const lastHtml = new WeakMap();

/**
 * Escreve a marcação no contentor, e só quando ela difere da anterior: o stream redesenha o
 * detalhe a cada mensagem, e substituir o `innerHTML` tira do documento quem tem o foco.
 *
 * O `beforeWrite` corre só quando se escreve -- é por ali que passa o desfazer dos tooltips.
 */
export function renderInto(container, markup, beforeWrite = null) {
    // Pelo texto: o `html` devolve um `Fragment` novo a cada render.
    markup = String(markup);
    if (!container || lastHtml.get(container) === markup) return false;

    beforeWrite?.(container);
    lastHtml.set(container, markup);
    container.innerHTML = markup;
    return true;
}
