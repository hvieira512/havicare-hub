import {esc} from "./format.js";
import {
    deviceTypeLabel,
    normalizeDeviceType,
    normalizeLicenseId,
} from "./domain.js";

/**
 * As peças de interface que não pertencem a um ecrã em particular: a atribuição de um
 * dispositivo, a imagem de um modelo, os grupos de botões, o mosaico de tipos, as pastilhas
 * de filtro e o estado vazio. Os cartões de telemetria estão no `telemetry-cards.js`.
 */

/**
 * A atribuição de um dispositivo, num campo só. A licença pertence à empresa e um
 * dispositivo tem as duas ou nenhuma, por isso o valor tem duas formas e não quatro:
 * `empresa · número`, ou "Sem licença".
 *
 * `valueClass` existe porque o cartão da listagem precisa do seu corte de texto e o painel
 * de factos não: lá o valor herda a tipografia do `dd`.
 */
export function deviceLicenseHtml(device, valueClass = "") {
    const company = String(device.company || "").trim();
    const licenseId = normalizeLicenseId(device.licenseId);
    if (company === "" || company.toLowerCase() === "null" || licenseId === "0") {
        return `<span class="${valueClass ? `${valueClass} ` : ""}license-empty">Sem licença</span>`;
    }

    const attribute = valueClass ? ` class="${valueClass}"` : "";
    return `<span${attribute}>${esc(company)}<span class="license-separator">·</span><span class="license-number">${esc(licenseId)}</span></span>`;
}

export function modelImageHtml(modelInfo, size = 40) {
    const label =
        modelInfo?.commercial_name ||
        modelInfo?.commercialName ||
        modelInfo?.internal_model ||
        modelInfo?.internalModel ||
        modelInfo?.model ||
        "Modelo";
    return modelInfo?.image
        ? `<img src="${esc(modelInfo.image)}" class="object-fit-contain" alt="${esc(label)}" style="width:${size}px;height:${size}px;">`
        : `<i class="fa-solid fa-microchip text-secondary" style="width:${size}px;font-size:${Math.round(size * 0.62)}px"></i>`;
}

/**
 * A imagem grande de um modelo, ou o ícone com a etiqueta quando não há imagem. Não fixa
 * tamanho de propósito: quem manda é o contentor, que já o limita por CSS.
 */
export function modelPreviewHtml(modelInfo, label = "Modelo") {
    const imageLabel =
        modelInfo?.commercial_name ||
        modelInfo?.commercialName ||
        modelInfo?.internal_model ||
        modelInfo?.internalModel ||
        modelInfo?.model ||
        label;
    return modelInfo?.image
        ? `<img src="${esc(modelInfo.image)}" class="object-fit-contain" alt="${esc(imageLabel)}">`
        : `<div class="text-center text-secondary"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${esc(label)}</div></div>`;
}

export function renderButtonGroup(
    container,
    items,
    selected,
    action,
    valueKey = "value",
    labelKey = "label",
) {
    container.innerHTML = items.length
        ? items
        .map((item) => {
            const value = String(item[valueKey] ?? "");
            const label = String(item[labelKey] ?? value);
            return `<button type="button" class="btn btn-sm ${value === selected ? "btn-primary" : "btn-outline-primary"}" data-action="${esc(action)}" data-value="${esc(value)}">${esc(label)}</button>`;
        })
        .join("")
        : '<div class="text-secondary small py-2">Sem opções disponíveis</div>';
}

/** O ícone de cada tipo de dispositivo, o mesmo do assistente de criação. */
const DEVICE_TYPE_ICON = {
    watch: "fa-clock",
    radar: "fa-wifi",
    gateway: "fa-tower-broadcast",
    diaper_sensor: "fa-droplet",
    bracelet: "fa-ring",
    ncs: "fa-bell-concierge",
};

export function deviceTypeIcon(deviceType) {
    return DEVICE_TYPE_ICON[normalizeDeviceType(deviceType)] || "fa-microchip";
}

/**
 * O mosaico de tipos de dispositivo, o único controlo de tipo no painel.
 *
 * `multiple` separa o filtro, onde se marcam vários, da escolha de um tipo só num
 * formulário -- e é o que decide se saem os atributos do filtro ou só o `data-value` que
 * as acções de escolha única leem.
 *
 * As contagens são opcionais porque não existem sempre: ao criar um modelo ainda não há
 * nada para contar, e um mosaico a dizer "nenhum" debaixo do nome mentia sobre isso.
 */
export function renderDeviceTypeTiles(
    container,
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

    container.innerHTML = options
        .map((option) => {
            const value = normalizeDeviceType(
                typeof option === "string" ? option : option.value,
            );
            const on = chosen.includes(value);
            const count = counts
                ? Number((counts.get ? counts.get(value) : counts[value]) || 0)
                : null;
            const filterAttrs = multiple
                ? ` data-filter-key="${esc(filterKey)}" data-filter-value="${esc(value)}"`
                : "";
            return `
            <button type="button" class="device-type-tile${on ? " selected" : ""}"
                data-action="${esc(action)}" data-value="${esc(value)}"${filterAttrs}
                ${count === 0 && !on ? "disabled" : ""} aria-pressed="${on ? "true" : "false"}">
            ${multiple ? '<span class="device-type-tile-check"><i class="fa-solid fa-check"></i></span>' : ""}
            <span class="device-type-tile-icon"><i class="fa-solid ${esc(deviceTypeIcon(value))}"></i></span>
            <span class="device-type-tile-name">${esc(deviceTypeLabel(value))}</span>
            ${counts ? `<span class="device-type-tile-count">${count === 0 ? "nenhum" : count}</span>` : ""}
            </button>`;
        })
        .join("");
}

/**
 * As pastilhas dos filtros aplicados, com o x para remover cada um. O arranjo é sempre o
 * mesmo -- pesquisa e botão de filtros na primeira linha, pastilhas na de baixo --, e entre
 * a listagem de dispositivos e a de modelos o que varia é só o nome da acção.
 */
export function filterChips(labels, action) {
    return labels
        .map(
            (item) => `
        <span class="filter-chip">
            <span>${esc(item.label)}</span>
            <button type="button" class="filter-chip-remove" data-action="${esc(action)}"
                data-filter-key="${esc(item.key)}" aria-label="Remover filtro ${esc(item.label)}">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </span>`,
        )
        .join("");
}

/**
 * O estado vazio de um painel, em texto e não em caixa: dentro de um cartão branco, uma
 * segunda moldura cinzenta a dizer que não há nada lê-se como conteúdo.
 */
export function emptyPanel(text) {
    return `<div class="text-secondary py-3">${esc(text)}</div>`;
}
