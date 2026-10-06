import { html, raw } from "../../../html.js";

/**
 * Uma escala curta com as posições à vista, para enumerações em que a ordem conta. O `name`
 * vem de fora: com nomes iguais, dois grupos na mesma página comportam-se como um.
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
