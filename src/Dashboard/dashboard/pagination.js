import { paginationControls } from "./components/pagination.js";

/** O resumo das listagens servidas pela API, que dizem quantos registos existem em total. */
const defaultSummary = (start, end, total) => `A mostrar de ${start} até ${end} | ${total}`;

/**
 * Escreve o paginador nos três elementos que o compõem: o contentor, que se esconde quando
 * não há para onde ir, o resumo e os controlos. Os botões vêm do componente; o que vive aqui
 * é o que toca no DOM.
 *
 * `summary` existe porque as duas famílias de listagem dizem a mesma coisa de formas
 * diferentes e ambas estão certas: uma listagem servida em páginas anuncia-se por extenso, e
 * os painéis estreitos do dispositivo escolhido só têm largura para "1–12 de 30".
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
    const controls = paginationControls({ pagination, actionPrefix, goAction });

    if (controls === "") {
        rootEl.classList.add("d-none");
        // Sem resumo não há elemento nenhum: onde o total já vive numa pastilha ao lado do
        // título, repeti-lo aqui era escrever o mesmo número duas vezes no mesmo ecrã.
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

/** `goAction` acompanha o do `renderPagination`: os painéis do dispositivo não usam o padrão. */
export function resolvePaginationPage(
    event,
    pagination,
    actionPrefix,
    goAction = `${actionPrefix}Go`,
) {
    const button = event.target.closest(
        `[data-action="${actionPrefix}Prev"], [data-action="${actionPrefix}Next"], [data-action="${goAction}"]`,
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
