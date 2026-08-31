import { esc } from "./format.js";

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
        // Sem resumo não há elemento nenhum: onde o total já vive numa pastilha ao lado do
        // título, repeti-lo aqui era escrever o mesmo número duas vezes no mesmo ecrã.
        if (summaryEl) {
            summaryEl.textContent = "";
        }
        controlsEl.innerHTML = "";
        return;
    }

    const pageStart = (currentPage - 1) * limit + 1;
    const pageEnd = Math.min(total, currentPage * limit);
    rootEl.classList.remove("d-none");
    if (summaryEl) {
        summaryEl.textContent = summary(pageStart, pageEnd, total);
    }
    // O componente `pagination` do Bootstrap em vez de um `btn-group` de botões: dá o mesmo
    // sem uma linha de CSS nosso -- cantos só nas pontas, a página actual preenchida, o
    // travado esbatido -- e o `page-link` já traz o alvo de toque e o anel de foco.
    const arrow = (action, icon, label, disabled) =>
        `<li class="page-item${disabled ? " disabled" : ""}">` +
        `<button type="button" class="page-link rounded ms-0" data-action="${esc(action)}" ${disabled ? "disabled" : ""}` +
        ` aria-label="${esc(label)}"><i class="fa-solid ${icon}"></i></button></li>`;

    controlsEl.innerHTML = [
        arrow(`${actionPrefix}Prev`, "fa-chevron-left", "Página anterior", currentPage <= 1),
        ...Array.from({ length: totalPages }, (_, index) => {
            const page = index + 1;
            const active = page === currentPage;
            return `<li class="page-item${active ? " active" : ""}">` +
                `<button type="button" class="page-link rounded ms-0 px-1 text-center" data-action="${esc(goAction)}" data-page="${page}"` +
                `${active ? " aria-current=\"page\"" : ""}>${page}</button></li>`;
        }),
        arrow(`${actionPrefix}Next`, "fa-chevron-right", "Página seguinte", currentPage >= totalPages),
    ].join("");
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
