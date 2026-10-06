/**
 * Todos os elementos com `id`, por `id`. Um `id` renomeado só na marcação rebenta o arranque,
 * e o `DashboardElementIdsTest` apanha-o antes.
 */
export function cacheElements() {
    const els = {};
    for (const el of document.querySelectorAll("[id]")) els[el.id] = el;
    return els;
}

const lastHtml = new WeakMap();

/**
 * Escreve a marcação só quando difere da anterior: substituir o `innerHTML` tira o foco a quem
 * o tem. O `beforeWrite` corre só quando se escreve.
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
