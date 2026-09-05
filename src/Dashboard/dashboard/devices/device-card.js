import { html, raw } from "../html.js";
import { deviceLicenseHtml } from "../widgets.js";
import { onlineBadge } from "../components/state-badge.js";
import { deviceTypeLabel, normalizeDeviceType } from "../domain.js";

/**
 * O cartão de um dispositivo: a marcação, o esqueleto e o nome da acção que emite. Estão
 * juntos porque mudam juntos -- o esqueleto é o cartão com as mesmas classes.
 *
 * O CSS fica no bloco `.device-card*` do `assets/css/device.css`, e o ouvinte fica delegado
 * na raiz da lista: as opções redesenham-se a cada resposta.
 */

/** O `data-action` que o cartão escreve, e que o ouvinte delegado procura. */
export const DEVICE_CARD_ACTION = "select";

/** Quantas linhas de esqueleto no máximo: a moldura mais alta leva doze cartões. */
const SKELETON_MAX_ROWS = 12;

export function deviceCard(device, selected) {
    const image = device.image
        ? html`<img src="${device.image}" alt="${device.model || device.imei}">`
        : "<i class=\"fa-solid fa-microchip\"></i>";
    const meta = [
        deviceTypeLabel(normalizeDeviceType(device.deviceType)),
        [device.supplier, device.model].filter(Boolean).join(" "),
    ]
        .filter(Boolean)
        .join(" · ");

    return html`
        <button type="button" class="device-card${selected ? " selected" : ""}${device.online ? "" : " offline"}"
            data-imei="${device.imei}" data-action="${DEVICE_CARD_ACTION}"${raw(selected ? " aria-current=\"true\"" : "")}>
        <span class="device-card-thumb">${raw(image)}</span>
        ${raw(onlineBadge(device.online))}
        <span class="device-card-identity">
            <span class="min-w-0">
                <span class="device-card-imei d-block text-truncate">${device.imei}</span>
                <span class="device-card-meta d-block text-truncate">${meta}</span>
            </span>
        </span>
        <span class="device-card-fields">
            <span class="device-card-field">
                <span class="device-card-field-label">Licença</span>
                ${raw(deviceLicenseHtml(device, "device-card-field-value"))}
            </span>
            <span class="device-card-field">
                <span class="device-card-field-label">SIM</span>
                <span class="device-card-field-value${device.simNumber ? " tabular-nums" : " empty"}">${device.simNumber || "—"}</span>
            </span>
        </span>
        </button>`;
}

/**
 * O cartão a sério com barras no lugar do texto, para a lista não saltar quando os dados
 * chegam. A `placeholder-wave` fica no contentor, para ser uma passagem só.
 */
export function deviceCardSkeletonList(pageSize) {
    const row = `
        <div class="device-card device-card-skeleton" aria-hidden="true">
        <span class="device-card-thumb placeholder"></span>
        <span class="placeholder device-card-skeleton-pill"></span>
        <span class="device-card-identity">
            <span class="min-w-0 w-100">
                <span class="placeholder d-block col-7 mb-1"></span>
                <span class="placeholder d-block col-4"></span>
            </span>
        </span>
        <span class="device-card-fields">
            <span class="device-card-field"><span class="placeholder col-9"></span></span>
            <span class="device-card-field"><span class="placeholder col-7"></span></span>
        </span>
        </div>`;
    const rows = Math.min(pageSize, SKELETON_MAX_ROWS);

    return `
        <div class="device-card-skeleton-list placeholder-wave">
        ${Array.from({ length: rows }, () => row).join("")}
        </div>`;
}
