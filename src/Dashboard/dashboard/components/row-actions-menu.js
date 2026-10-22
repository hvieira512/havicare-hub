import { html } from "../html.js";

/** Os verbos de uma linha num menu "⋮"; o `"divider"` separa o apagar do resto. */
export function rowActionsMenu(label, items) {
    const entries = items.map((item) => (item === "divider"
        ? html`<li><hr class="dropdown-divider"></li>`
        : html`<li><button type="button" class="dropdown-item${item.danger ? " text-danger" : ""}" data-action="${item.action}" ${item.attrs}>${item.label}</button></li>`));

    return html`
        <div class="dropdown flex-shrink-0">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="dropdown" aria-expanded="false" aria-label="${label}"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>
            <ul class="dropdown-menu dropdown-menu-end shadow">${entries}</ul>
        </div>`;
}
