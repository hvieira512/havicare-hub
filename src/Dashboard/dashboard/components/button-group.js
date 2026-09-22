import { html } from "../html.js";

/**
 * Um grupo de botões para uma escolha única, com o escolhido a cheio e os outros em
 * contorno. As chaves do valor e do rótulo são parâmetros porque as listas que passam por
 * aqui vêm de sítios diferentes -- fornecedores, tipos, protocolos -- e nem todas se chamam
 * `value` e `label`.
 */
export function buttonGroup(
    items,
    selected,
    action,
    valueKey = "value",
    labelKey = "label",
) {
    return items.length
        ? items
                .map((item) => {
                    const value = String(item[valueKey] ?? "");
                    const label = String(item[labelKey] ?? value);
                    return html`<button type="button" class="btn btn-sm ${value === selected ? "btn-primary" : "btn-outline-primary"}" data-action="${action}" data-value="${value}">${label}</button>`;
                })
                .join("")
        : "<div class=\"text-secondary small py-2\">Sem opções disponíveis</div>";
}
