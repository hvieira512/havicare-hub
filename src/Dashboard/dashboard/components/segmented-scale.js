import { html, raw } from "../html.js";

/**
 * Uma escala curta com todas as posições à vista, à largura de quem a recebe.
 *
 * Serve as enumerações em que a ordem diz alguma coisa e a lista fechada a esconde -- o
 * volume do dispensador tem quatro posições e está invertido, `0` é o mais alto.
 *
 * O `name` vem de fora: sem nomes distintos, dois grupos na mesma página comportam-se como um.
 */
export function segmentedScale({ name, field, value, options, label = "" }) {
    const current = String(value ?? "");
    const buttons = options.map((option) => {
        const optionValue = String(option.value);
        const id = `${name}-${optionValue}`;
        const icon = option.icon
            ? html`<i class="fa-solid ${option.icon} me-2" aria-hidden="true"></i>`
            : "";

        return html`<input type="radio" class="btn-check" name="${name}" id="${id}" value="${optionValue}"
                data-config-field="${field}"${raw(optionValue === current ? " checked" : "")}>
            <label class="btn btn-outline-${option.tone || "secondary"}" for="${id}">${raw(icon)}${option.label ?? optionValue}</label>`;
    });

    return html`<div class="btn-group w-100" role="group"${raw(label === "" ? "" : html` aria-label="${label}"`)}>${raw(buttons.join(""))}</div>`;
}
