import { html } from "../html.js";

/** As duas tiras de pastilhas da plataforma: as secções de um catálogo e os filtros aplicados. */

/**
 * Uma tira de pastilhas de secção, com ícone e contagem: a única forma de escolher uma secção
 * no hub. A acesa vem do estado e não do DOM, porque a tira é redesenhada.
 */
export function sectionStrip(sections, action, activeKey = "") {
    return sections
        .map(({ key, label, count, icon }) => html`
        <button type="button" class="capability-section-chip d-inline-flex align-items-center flex-shrink-0 rounded-pill text-nowrap${key === activeKey ? " selected" : ""}"
            data-action="${action}" data-section="${key}">
            <i class="fa-solid ${icon}" aria-hidden="true"></i>${label}<span class="count count-number" data-section-count>${count}</span>
        </button>`)
        .join("");
}

/**
 * As pastilhas dos filtros aplicados, com o x para remover cada um, por baixo da pesquisa e
 * do botão de filtros. Entre listagens varia só o nome da acção.
 */
export function filterChips(labels, action) {
    return labels
        .map(
            (item) => html`
        <span class="filter-chip d-inline-flex align-items-center fw-semibold text-uppercase text-nowrap rounded-pill bg-body-secondary text-secondary">
            <span>${item.label}</span>
            <button type="button" class="filter-chip-remove d-flex align-items-center justify-content-center p-0 border-0 rounded-circle" data-action="${action}"
                data-filter-key="${item.key}" aria-label="Remover filtro ${item.label}">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </span>`,
        )
        .join("");
}
