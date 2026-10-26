import { html } from "../core/html.js";

/** Uma opção de uma árvore de escolha: a caixa, o nome e, quando há, a contagem. */
export function filterOption({ attrs, label, count = null, selected, partial = false, nested = false, radio = false }) {
    const classes = [
        "filter-option d-flex align-items-center text-start rounded-2",
        nested ? "filter-option-nested" : "",
        selected ? "selected" : "",
        partial ? "partial" : "",
    ]
        .filter(Boolean)
        .join(" ");

    return html`
        <button type="button" class="${classes}" ${attrs}>
        <span class="filter-option-box d-grid flex-shrink-0${radio ? " rounded-circle" : ""}"><i class="fa-solid ${partial && !selected ? "fa-minus" : "fa-check"}"></i></span>
        <span class="flex-fill min-w-0 text-truncate">${label}</span>
        ${count === null ? "" : html`<span class="count-number flex-shrink-0">${count}</span>`}
        </button>`;
}

/** Os filhos de uma opção, pendurados no traço que os liga ao pai. */
export const filterBranch = (rows) =>
    html`<div class="filter-branch position-relative d-flex flex-column">${rows}</div>`;
