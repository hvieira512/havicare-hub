/**
 * Todos os elementos com `id`, por `id`.
 *
 * A casca é servida inteira pelo PHP e isto corre no `DOMContentLoaded`, por isso não há
 * elemento por nascer. Um `id` que não exista dá `undefined`, e a maior parte dos leitores
 * não se protege: renomear um `id` na marcação sem o renomear aqui rebenta o arranque e
 * devolve o ecrã de entrada com «Não foi possível carregar a aplicação».
 *
 * Falhar assim é o que se quer -- ruidoso e no primeiro segundo --, e quem o apanha antes de
 * chegar ao browser é o `DashboardElementIdsTest`, que cruza cada `els.<id>` com a marcação.
 */
export function cacheElements() {
    const els = {};
    for (const el of document.querySelectorAll("[id]")) els[el.id] = el;
    return els;
}
