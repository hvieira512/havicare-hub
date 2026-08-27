import {esc} from "./format.js";

/** O resumo das listagens servidas pela API, que dizem quantos registos existem em total. */
const defaultSummary = (start, end, total) => `A mostrar de ${start} até ${end} | ${total}`;

/**
 * Os controlos de paginação, para as listagens da API e para as que paginam no cliente.
 *
 * `summary` existe porque as duas famílias dizem a mesma coisa de formas diferentes e
 * ambas estão certas: uma listagem servida em páginas anuncia-se por extenso, e os painéis
 * estreitos do dispositivo escolhido só têm largura para "1–12 de 30".
 *
 * `goAction` existe porque os handlers dos painéis do dispositivo estão registados em
 * `telemetryPageGo`/`downlinkPageGo` e não em `${actionPrefix}Go`. Com o valor por omissão
 * nesses dois sítios os botões numerados deixam de responder sem dar erro nenhum.
 */
export function renderPagination({
    pagination,
    rootEl,
    summaryEl,
    controlsEl,
    actionPrefix,
    defaultLimit = 20,
    summary = defaultSummary,
    goAction = `${actionPrefix}Go`,
}) {
    const total = pagination?.total ?? 0;
    const totalPages = pagination?.total_pages ?? 1;
    const currentPage = pagination?.page ?? 1;
    const limit = pagination?.limit ?? defaultLimit;

    if (totalPages <= 1) {
        rootEl.classList.add("d-none");
        summaryEl.textContent = "";
        controlsEl.innerHTML = "";
        return;
    }

    const pageStart = (currentPage - 1) * limit + 1;
    const pageEnd = Math.min(total, currentPage * limit);
    rootEl.classList.remove("d-none");
    summaryEl.textContent = summary(pageStart, pageEnd, total);
    controlsEl.innerHTML = [
        `<button type="button" class="btn btn-outline-secondary btn-sm" data-action="${esc(actionPrefix)}Prev" ${currentPage <= 1 ? "disabled" : ""} aria-label="Página anterior"><i class="fa-solid fa-chevron-left"></i></button>`,
        ...Array.from({length: totalPages}, (_, index) => {
            const page = index + 1;
            return `<button type="button" class="btn ${page === currentPage ? "btn-primary" : "btn-outline-secondary"} btn-sm" data-action="${esc(goAction)}" data-page="${page}" ${page === currentPage ? 'aria-current="page"' : ""}>${page}</button>`;
        }),
        `<button type="button" class="btn btn-outline-secondary btn-sm" data-action="${esc(actionPrefix)}Next" ${currentPage >= totalPages ? "disabled" : ""} aria-label="Página seguinte"><i class="fa-solid fa-chevron-right"></i></button>`,
    ].join("");
}

export function resolvePaginationPage(event, pagination, actionPrefix) {
    const button = event.target.closest(
        `[data-action="${actionPrefix}Prev"], [data-action="${actionPrefix}Next"], [data-action="${actionPrefix}Go"]`,
    );
    if (!button) {
        return null;
    }

    const currentPage = pagination?.page ?? 1;
    const totalPages = pagination?.total_pages ?? 1;

    if (button.dataset.action === `${actionPrefix}Prev`) {
        return Math.max(1, currentPage - 1);
    }
    if (button.dataset.action === `${actionPrefix}Next`) {
        return Math.min(totalPages, currentPage + 1);
    }
    return Math.min(
        Math.max(1, parseInt(button.dataset.page || "1", 10) || 1),
        totalPages,
    );
}
