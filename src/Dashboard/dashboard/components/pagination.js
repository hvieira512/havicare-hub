import { esc } from "../format.js";

/** Quantos lugares tem a janela. Ímpar, para a página actual ficar ao centro. */
const WINDOW_SLOTS = 7;

/**
 * A janela de páginas: as duas pontas, a vizinhança da página actual, e reticências a marcar
 * o que ficou de fora (`null`). São sempre `WINDOW_SLOTS` lugares -- um paginador com sete
 * botões numa página e nove noutra muda de tamanho debaixo do rato de quem carregou nele.
 */
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

/**
 * Os números escondem-se num ecrã estreito e ficam as duas setas: catorze alvos de 24px não
 * se acertam com o polegar, e a que página se vai não é pergunta de telemóvel.
 */
const NUMBER_SLOT = "d-none d-md-flex";

/**
 * Os botões de um paginador: as duas setas e a janela de páginas entre elas. Vazio quando há
 * uma página só -- não há para onde ir, e um paginador de um botão é ruído.
 */
export function paginationControls({ pagination, actionPrefix }) {
    const goAction = `${actionPrefix}Go`;
    const totalPages = pagination?.total_pages ?? 1;
    const currentPage = pagination?.page ?? 1;

    if (totalPages <= 1) {
        return "";
    }

    const arrow = (action, icon, label, disabled) =>
        `<li class="page-item${disabled ? " disabled" : ""}">` +
        `<button type="button" class="page-link page-link-touch rounded ms-0" data-action="${esc(action)}" ${disabled ? "disabled" : ""}` +
        ` aria-label="${esc(label)}"><i class="fa-solid ${icon}"></i></button></li>`;

    // Um `span` e não um botão: as reticências não são um destino, e o `page-link` dá-lhes a
    // mesma medida mínima dos números para o lugar não encolher quando lá está.
    const gap =
        `<li class="page-item disabled ${NUMBER_SLOT}">` +
        "<span class=\"page-link rounded ms-0 px-1 text-center\" aria-hidden=\"true\">…</span></li>";

    return [
        arrow(`${actionPrefix}Prev`, "fa-chevron-left", "Página anterior", currentPage <= 1),
        ...pageWindow(currentPage, totalPages).map((page) => {
            if (page === null) {
                return gap;
            }
            const active = page === currentPage;
            return `<li class="page-item ${NUMBER_SLOT}${active ? " active" : ""}">` +
                `<button type="button" class="page-link page-link-touch rounded ms-0 px-1 text-center" data-action="${esc(goAction)}" data-page="${page}"` +
                `${active ? " aria-current=\"page\"" : ""}>${page}</button></li>`;
        }),
        arrow(`${actionPrefix}Next`, "fa-chevron-right", "Página seguinte", currentPage >= totalPages),
    ].join("");
}

/**
 * O que a paginação numerada é no telemóvel: um botão largo em vez de catorze de 24px, que
 * nenhum polegar acerta. Vazio na última página, que não tem mais nada para trazer.
 */
export function loadMoreButton({ pagination, actionPrefix }) {
    const totalPages = pagination?.total_pages ?? 1;
    const currentPage = pagination?.page ?? 1;

    if (currentPage >= totalPages) {
        return "";
    }

    return `<button type="button" class="btn btn-outline-secondary py-2 mt-2" data-action="${esc(actionPrefix)}More">Carregar mais</button>`;
}

/**
 * As linhas que a lista mostra. Com o «Carregar mais» ela acumula, e a página seguinte
 * junta-se às anteriores em vez de as substituir.
 */
export function pagedRows(rows, { page, pageSize, cumulative = false }) {
    return rows.slice(cumulative ? 0 : (page - 1) * pageSize, page * pageSize);
}
