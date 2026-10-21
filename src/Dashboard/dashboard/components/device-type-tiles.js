import { html, raw } from "../html.js";
import {
    deviceTypeFields,
    deviceTypeLabel,
    deviceTypeShortLabel,
    normalizeDeviceType,
} from "../domain.js";

export function deviceTypeIcon(deviceType) {
    // O `fa-microchip` é para uma entrada do catálogo que chegue sem ícone.
    return deviceTypeFields(deviceType).icon || "fa-microchip";
}

/** O mosaico de tipos: o `multiple` separa o filtro da escolha única. */
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
            // O filtro vive na coluna estreita do selector, três por linha: lá vai o nome curto.
            const name = multiple ? deviceTypeShortLabel(value) : deviceTypeLabel(value);
            const countChip = counts
                ? html`<span class="count-number">${count === 0 ? "nenhum" : count}</span>`
                : "";
            return html`
            <button type="button" class="device-type-tile position-relative d-flex flex-column align-items-center gap-1 w-100 text-center rounded-3${multiple ? " pt-2 px-1 pb-2" : ""}${on ? " selected" : ""}"
                data-action="${action}" data-value="${value}"${raw(filterAttrs)}
                ${count === 0 && !on ? "disabled" : ""} aria-pressed="${on ? "true" : "false"}"
                title="${deviceTypeLabel(value)}">
            ${raw(check)}
            <span class="device-type-tile-icon lh-1"><i class="fa-solid ${deviceTypeIcon(value)}"></i></span>
            <span class="device-type-tile-name fw-semibold lh-sm${multiple ? " mw-100 text-truncate" : ""}">${name}</span>
            ${raw(countChip)}
            </button>`;
        })
        .join("");
}
