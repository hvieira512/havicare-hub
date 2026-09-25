import { html, raw } from "../html.js";
import { deviceTypeFields, deviceTypeLabel, normalizeDeviceType } from "../domain.js";

/**
 * O ícone de cada tipo, tirado do catálogo. Estava aqui numa tabela à parte, e a entrada
 * desenha a mesma constelação em PHP, que não lê módulos ES: duas cópias, uma adição.
 */
export function deviceTypeIcon(deviceType) {
    // O que não se reconhece cai no tipo por omissão, como em todo o `domain.js`; o
    // `fa-microchip` é só para o caso de uma entrada do catálogo chegar sem ícone.
    return deviceTypeFields(deviceType).icon || "fa-microchip";
}

/**
 * O mosaico de tipos de dispositivo. O `multiple` separa o filtro da escolha única, e decide
 * que atributos saem. As contagens são opcionais: ao criar um modelo não há o que contar.
 *
 * Vive ao lado do ícone porque partilha o mapa e porque o desenha em cada mosaico.
 */
export function deviceTypeTiles(
    options,
    {
        selected = [],
        multiple = false,
        counts = null,
        action = "toggleDeviceFilter",
        filterKey = "deviceType",
    } = {},
) {
    const chosen = (Array.isArray(selected) ? selected : [selected])
        .filter((value) => value !== null && value !== undefined && value !== "")
        .map((value) => normalizeDeviceType(String(value)));

    return options
        .map((option) => {
            const value = normalizeDeviceType(
                typeof option === "string" ? option : option.value,
            );
            const on = chosen.includes(value);
            const count = counts
                ? Number((counts.get ? counts.get(value) : counts[value]) || 0)
                : null;
            const filterAttrs = multiple
                ? html` data-filter-key="${filterKey}" data-filter-value="${value}"`
                : "";
            const check = multiple
                ? "<span class=\"device-type-tile-check position-absolute d-grid\"><i class=\"fa-solid fa-check\"></i></span>"
                : "";
            const countChip = counts
                ? html`<span class="count-number">${count === 0 ? "nenhum" : count}</span>`
                : "";
            return html`
            <button type="button" class="device-type-tile position-relative d-flex flex-column align-items-center gap-1 w-100 text-center rounded-3${on ? " selected" : ""}"
                data-action="${action}" data-value="${value}"${raw(filterAttrs)}
                ${count === 0 && !on ? "disabled" : ""} aria-pressed="${on ? "true" : "false"}">
            ${raw(check)}
            <span class="device-type-tile-icon lh-1"><i class="fa-solid ${deviceTypeIcon(value)}"></i></span>
            <span class="device-type-tile-name fw-semibold lh-sm">${deviceTypeLabel(value)}</span>
            ${raw(countChip)}
            </button>`;
        })
        .join("");
}
