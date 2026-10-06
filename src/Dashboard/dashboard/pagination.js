import { paginationControls } from "./components/pagination.js";

/** O resumo das listagens servidas pela API, que dizem quantos registos existem em total. */
const defaultSummary = (start, end, total) => `A mostrar de ${start} até ${end} | ${total}`;

/**
 * Escreve o paginador no contentor, no resumo e nos controlos. O `summary` é por extenso numa
 * listagem em páginas, e curto nos painéis estreitos do dispositivo: «1–12 de 30».
 */
export function renderPagination({
    pagination,
    rootEl,
    summaryEl,
    controlsEl,
    actionPrefix,
    defaultLimit = 20,
    summary = defaultSummary,
}) {
    const controls = paginationControls({ pagination, actionPrefix });

    if (controls === "") {
        rootEl.classList.add("d-none");
        // Sem resumo não há elemento: o total já vive numa pastilha ao lado do título.
        if (summaryEl) {
            summaryEl.textContent = "";
        }
        controlsEl.innerHTML = "";
        return;
    }

    const total = pagination?.total ?? 0;
    const currentPage = pagination?.page ?? 1;
    const limit = pagination?.limit ?? defaultLimit;

    rootEl.classList.remove("d-none");
    if (summaryEl) {
        summaryEl.textContent = summary(
            (currentPage - 1) * limit + 1,
            Math.min(total, currentPage * limit),
            total,
        );
    }
    controlsEl.innerHTML = controls;
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
