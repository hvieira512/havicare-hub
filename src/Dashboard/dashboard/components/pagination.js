import { esc } from "../format.js";

/** Quantos lugares tem a janela. Ímpar, para a página actual ficar ao centro. */
const WINDOW_SLOTS = 7;

/** As pontas, a vizinhança da actual e reticências (`null`), sempre em `WINDOW_SLOTS` lugares. */
function pageWindow(currentPage, totalPages) {
    if (totalPages <= WINDOW_SLOTS) {
        return Array.from({ length: totalPages }, (_, index) => index + 1);
    }
    // Junto às pontas a vizinhança encosta-se, para não sobrar um lugar por preencher.
    if (currentPage <= 4) {
        return [1, 2, 3, 4, 5, null, totalPages];
    }
    if (currentPage >= totalPages - 3) {
        return [
            1,
            null,
            totalPages - 4,
            totalPages - 3,
            totalPages - 2,
            totalPages - 1,
            totalPages,
        ];
    }
    return [1, null, currentPage - 1, currentPage, currentPage + 1, null, totalPages];
}

/** Num ecrã estreito ficam só as setas: catorze alvos de 24px não se acertam com o polegar. */
const NUMBER_SLOT = "d-none d-md-flex";

/** As setas e a janela de páginas entre elas; vazio quando há uma página só. */
export function paginationControls({ pagination, actionPrefix }) {
    const goAction = `${actionPrefix}Go`;
    const totalPages = pagination?.totalPages ?? 1;
    const currentPage = pagination?.page ?? 1;

    if (totalPages <= 1) {
        return "";
    }

    const arrow = (action, page, icon, label, disabled) =>
        `<li class="page-item${disabled ? " disabled" : ""}">` +
        `<button type="button" class="page-link page-link-touch rounded ms-0" data-action="${esc(action)}" data-page="${page}" ${disabled ? "disabled" : ""}` +
        ` aria-label="${esc(label)}"><i class="fa-solid ${icon}"></i></button></li>`;

    // Um `span` e não um botão: as reticências não são um destino.
    const gap =
        `<li class="page-item disabled ${NUMBER_SLOT}">` +
        "<span class=\"page-link rounded ms-0 px-1 text-center\" aria-hidden=\"true\">…</span></li>";

    return [
        arrow(`${actionPrefix}Prev`, currentPage - 1, "fa-chevron-left", "Página anterior", currentPage <= 1),
        ...pageWindow(currentPage, totalPages).map((page) => {
            if (page === null) {
                return gap;
            }
            const active = page === currentPage;
            return `<li class="page-item ${NUMBER_SLOT}${active ? " active" : ""}">` +
                `<button type="button" class="page-link page-link-touch rounded ms-0 px-1 text-center" data-action="${esc(goAction)}" data-page="${page}"` +
                `${active ? " aria-current=\"page\"" : ""}>${page}</button></li>`;
        }),
        arrow(`${actionPrefix}Next`, currentPage + 1, "fa-chevron-right", "Página seguinte", currentPage >= totalPages),
    ].join("");
}

/** O «Carregar mais» do telemóvel; vazio na última página. */
export function loadMoreButton({ pagination, actionPrefix }) {
    const totalPages = pagination?.totalPages ?? 1;
    const currentPage = pagination?.page ?? 1;

    if (currentPage >= totalPages) {
        return "";
    }

    return `<button type="button" class="btn btn-outline-secondary py-2 mt-2" data-action="${esc(actionPrefix)}More">Carregar mais</button>`;
}

/** As linhas à vista; com o «Carregar mais», a página seguinte junta-se às anteriores. */
export function pagedRows(rows, { page, pageSize, cumulative = false }) {
    return rows.slice(cumulative ? 0 : (page - 1) * pageSize, page * pageSize);
}
