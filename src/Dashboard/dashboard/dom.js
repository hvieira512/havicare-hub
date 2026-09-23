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
