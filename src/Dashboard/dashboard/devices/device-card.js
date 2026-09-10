import { html, raw } from "../html.js";
import { deviceLicenseBlock } from "../components/device-license.js";
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

/** Quantos caracteres duas cadeias partilham desde o princípio. */
function commonPrefixLength(a, b) {
    const limit = Math.min(a.length, b.length);
    let i = 0;
    while (i < limit && a[i] === b[i]) i += 1;
    return i;
}

/** Abaixo disto o prefixo comum é coincidência, e esbatê-lo é ruído. */
const MIN_SHARED = 3;
/** O mínimo que fica a cheio, mesmo entre dois que só diferem no último dígito. */
const MIN_SUFFIX = 4;

/**
 * Divide um identificador no prefixo que os vizinhos da lista partilham e no resto.
 *
 * @returns {{prefix: string, suffix: string}}
 */
export function imeiEmphasis(imei, others) {
    const value = String(imei || "");
    const shared = others.reduce(
        (longest, other) => (String(other) === value
            ? longest
            : Math.max(longest, commonPrefixLength(value, String(other)))),
        0,
    );
    const dim = Math.min(shared, Math.max(0, value.length - MIN_SUFFIX));

    return dim < MIN_SHARED
        ? { prefix: "", suffix: value }
        : { prefix: value.slice(0, dim), suffix: value.slice(dim) };
}

const sharedPrefixHtml = (prefix) =>
    prefix === "" ? "" : html`<span class="text-body-secondary">${prefix}</span>`;

/** A coluna fica vazia quando o aparelho não tem SIM: um traço não diz mais do que nada. */
const simNumberHtml = (simNumber) =>
    simNumber
        ? html`<span class="device-card-field-value tabular-nums">${simNumber}</span>`
        : "";

/** Sem data é «sem registo», e não «nunca»: o hub registou o aparelho e ainda não o ouviu. */
function lastSeenLine(lastSeenAt) {
    return lastSeenAt
        ? html`<span class="device-card-when">${ago(lastSeenAt)}</span>`
        : "<span class=\"device-card-when never\">sem registo</span>";
}

export function deviceCard(device, selected, siblings = []) {
    const image = device.image
        ? html`<img src="${device.image}" alt="${device.model || device.imei}">`
        : "<i class=\"fa-solid fa-microchip\"></i>";
    const { prefix, suffix } = imeiEmphasis(device.imei, siblings);
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
            ${raw(onlineBadge(device.online, "align-self-start"))}
            ${raw(lastSeenLine(device.lastSeenAt))}
        </span>
        <span class="device-card-identity">
            <span class="min-w-0">
                <span class="device-card-imei d-block text-truncate">${raw(sharedPrefixHtml(prefix))}${suffix}</span>
                <span class="device-card-meta d-block text-truncate">${meta}</span>
            </span>
        </span>
        <span class="device-card-fields">
            <span class="device-card-field">
                ${raw(deviceLicenseBlock(device, {
                    valueClass: "device-card-field-value",
                    noteClass: "device-card-field-note text-truncate",
                }))}
            </span>
            <span class="device-card-field">
                ${raw(simNumberHtml(device.simNumber))}
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
