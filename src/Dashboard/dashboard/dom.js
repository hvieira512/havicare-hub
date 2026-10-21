/** Todos os elementos com `id`, por `id`; o `DashboardElementIdsTest` confere-os com a marcação. */
export function cacheElements() {
    const els = {};
    for (const el of document.querySelectorAll("[id]")) els[el.id] = el;
    return els;
}

const lastHtml = new WeakMap();

/** Escreve a marcação só quando difere da anterior, porque substituir o `innerHTML` tira o foco. */
export function renderInto(container, markup, beforeWrite = null) {
    // Pelo texto: o `html` devolve um `Fragment` novo a cada render.
    markup = String(markup);
    if (!container || lastHtml.get(container) === markup) return false;

    beforeWrite?.(container);
    lastHtml.set(container, markup);
    container.innerHTML = markup;
    return true;
}
