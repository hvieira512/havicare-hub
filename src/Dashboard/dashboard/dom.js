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

/** O que cada contentor levou da última vez, para não se reescrever o que não mudou. */
const lastHtml = new WeakMap();

/**
 * Escreve a marcação num contentor, e só quando ela difere da anterior.
 *
 * O stream redesenha o detalhe a cada mensagem e não compara nada: substituir o `innerHTML`
 * tira do documento o elemento que tem o foco, e o teclado perde o sítio várias vezes por
 * segundo num aparelho vivo.
 */
export function renderInto(container, markup, beforeWrite = null) {
    if (!container || lastHtml.get(container) === markup) return false;
    // Os tooltips do Bootstrap desfazem-se antes de o elemento deles sair, e só quando sai:
    // desfazê-los a cada mensagem fechava sozinho o que estivesse aberto.
    beforeWrite?.(container);
    lastHtml.set(container, markup);
    container.innerHTML = markup;
    return true;
}
