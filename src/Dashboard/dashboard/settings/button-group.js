import { html } from "../core/html.js";

/** Um grupo de botões de escolha única, de itens `{value, label}`. */
export function buttonGroup(items, selected, action) {
    return items.length
        ? items
                .map((item) => {
                    const value = String(item.value ?? "");
                    const label = String(item.label ?? value);
                    return html`<button type="button" class="btn btn-sm ${value === selected ? "btn-primary" : "btn-outline-primary"}" aria-pressed="${value === selected}" data-action="${action}" data-value="${value}">${label}</button>`;
                })
                .join("")
        : "<div class=\"text-secondary small py-2\">Sem opções disponíveis</div>";
}
