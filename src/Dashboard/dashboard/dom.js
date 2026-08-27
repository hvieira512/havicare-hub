/**
 * Todos os elementos com `id`, por `id`.
 *
 * A casca é servida inteira pelo PHP e isto corre no `DOMContentLoaded`, por isso não há
 * elemento por nascer; um `id` que não exista dá `undefined` em vez de `null`, e todos os
 * sítios que lêem daqui já se protegem com `?.` ou com uma verificação de existência.
 */
export function cacheElements() {
    const els = {};
    for (const el of document.querySelectorAll("[id]")) els[el.id] = el;
    return els;
}
