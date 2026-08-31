import { html, raw } from "../html.js";
import { deviceLicenseHtml, onlineBadge } from "../widgets.js";
import { deviceTypeLabel, normalizeDeviceType } from "../domain.js";

/**
 * O cartão de um dispositivo: a marcação, o esqueleto que ocupa o seu lugar enquanto os
 * dados não chegam, e o nome da acção que ele emite.
 *
 * Está aqui e não a meio do `list.js` por causa da regra 4 do README. Um cartão estava
 * espalhado por quatro sítios -- a marcação numa função da lista, o estilo no CSS global, o
 * ouvinte no `wiring/`, e os dados no `state` --, e mudá-lo obrigava a abrir quatro
 * ficheiros sem que nada dissesse que os quatro pedaços eram a mesma coisa.
 *
 * O que se juntou foi o que se podia juntar sem pagar por isso:
 *
 * - A marcação e o esqueleto vêm para aqui, porque têm de mudar juntos: o esqueleto é o
 *   cartão com as mesmas classes e barras no lugar do texto, e é isso que impede a lista de
 *   saltar quando os dados chegam. Separados, um mudava sem o outro.
 * - O nome da acção passa a ser exportado, para o ouvinte delegado do `wiring/` não repetir
 *   a string que a marcação escreve.
 * - **O CSS fica onde está**, no bloco `.device-card*` do `assets/css/device.css`, marcado
 *   com um comentário que aponta para aqui. Sem passo de compilação, um ficheiro de estilo
 *   por widget é um pedido HTTP por widget -- foi por isso que a divisão do CSS parou em
 *   cinco ficheiros por área, e um ficheiro por cartão desfazia essa conta.
 *
 * O ouvinte continua delegado na raiz da lista, que é o padrão da casa: as opções são
 * redesenhadas a cada resposta, e um ouvinte por cartão obrigava a religá-los todos.
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
            <span class="min-width-0">
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
 * O esqueleto da lista: o cartão a sério, com as mesmas classes e portanto a mesma altura,
 * com barras no lugar do texto. São tantos quantos a página pode trazer, e a caixa corta os
 * que não cabem.
 *
 * A `placeholder-wave` fica no contentor e não em cada linha, para ser uma passagem só sobre
 * a lista toda.
 */
export function deviceCardSkeletonList(pageSize) {
    const row = `
        <div class="device-card device-card-skeleton" aria-hidden="true">
        <span class="device-card-thumb placeholder"></span>
        <span class="placeholder device-card-skeleton-pill"></span>
        <span class="device-card-identity">
            <span class="min-width-0 w-100">
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
