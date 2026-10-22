/** Todos os elementos com `id`, por `id`; o `DashboardElementIdsTest` confere-os com a marcação. */
export let els = {};

/** Enche o `els` com os elementos da página, ou com os que um teste lhe der. */
export function cacheElements(found = null) {
    els = found ?? Object.fromEntries([...document.querySelectorAll("[id]")].map((el) => [el.id, el]));
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
