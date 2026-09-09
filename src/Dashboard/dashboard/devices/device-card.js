import { html, raw } from "../html.js";
import { deviceLicenseHtml } from "../widgets.js";
import { onlineBadge } from "../components/state-badge.js";
import { deviceTypeLabel, normalizeDeviceType } from "../domain.js";
import { ago } from "../format.js";

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

/**
 * Desde quando é que o aparelho está como está.
 *
 * A pastilha responde «está a falar agora?», que é sim ou não; isto responde «desde quando?».
 * Sem esta linha, «Desligado» dizia a mesma coisa sobre um relógio que se desligou há nove
 * minutos, um gateway calado há uma semana e um aparelho que nunca falou desde que foi
 * registado -- e o do meio é o mais grave dos três.
 *
 * Sem data não se escreve «nunca», que se lê como um facto sobre o aparelho: «sem registo»
 * diz o que se passa, que é o hub tê-lo registado e ainda não o ter ouvido. E não há limite
 * a partir do qual a linha muda de cor: quanto tempo é demasiado depende do aparelho -- um
 * radar fala de segundos a segundos, um relógio passa a noite quieto --, e o tempo decorrido
 * é um facto que dispensa esse juízo.
 */
function lastSeenLine(lastSeenAt) {
    return lastSeenAt
        ? html`<span class="device-card-when">${ago(lastSeenAt)}</span>`
        : "<span class=\"device-card-when never\">sem registo</span>";
}

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
        <span class="device-card-state">
            ${raw(onlineBadge(device.online))}
            ${raw(lastSeenLine(device.lastSeenAt))}
        </span>
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
        <span class="device-card-state">
            <span class="placeholder device-card-skeleton-pill"></span>
            <span class="placeholder device-card-skeleton-when"></span>
        </span>
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
