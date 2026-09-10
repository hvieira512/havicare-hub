import { html, raw } from "./html.js";
import { deviceTypeLabel, normalizeDeviceType } from "./domain.js";

/**
 * As peças de interface que não pertencem a um ecrã em particular: a atribuição de um
 * dispositivo, a imagem de um modelo, os grupos de botões, o mosaico de tipos, as pastilhas
 * de filtro e o estado vazio. Os cartões de telemetria estão no `telemetry-cards.js`, e a
 * pastilha de estado no `components/state-badge.js`.
 */

/**
 * Uma etiqueta com o seu controlo. O controlo entra como HTML já pronto e passa pelo
 * `raw()`; a etiqueta e a ajuda entram como texto e saem escapadas.
 */
export function field(label, control, { help = "", cls = "", required = false } = {}) {
    const classAttribute = cls ? html` class="${cls}"` : "";
    const helpLine = help ? html`<div class="form-text">${help}</div>` : "";
    return html`
        <div${raw(classAttribute)}>
            <label class="form-label-sm${required ? " required" : ""}">${label}</label>
            ${raw(control)}
            ${raw(helpLine)}
        </div>`;
}

/**
 * Uma tira de pastilhas de secção, cada uma com a sua contagem. A pastilha acesa vem do
 * estado e não do DOM, porque a tira é redesenhada.
 */
export function sectionStrip(sections, action, activeKey = "") {
    return sections
        .map(({ key, label, count, icon = "" }) => html`
        <button type="button" class="capability-section-chip d-inline-flex align-items-center flex-shrink-0 rounded-pill text-nowrap${key === activeKey ? " selected" : ""}"
            data-action="${action}" data-section="${key}">
            ${raw(icon ? html`<i class="fa-solid ${icon}"></i>` : "")}${label}<span class="count count-number" data-section-count>${count}</span>
        </button>`)
        .join("");
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
        ? html`<img src="${modelInfo.image}" class="object-fit-contain" alt="${label}" style="width:${size}px;height:${size}px;">`
        : html`<i class="fa-solid fa-microchip text-secondary" style="width:${size}px;font-size:${Math.round(size * 0.62)}px"></i>`;
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
        ? html`<img src="${modelInfo.image}" class="object-fit-contain" alt="${imageLabel}">`
        : html`<div class="text-center text-secondary"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${label}</div></div>`;
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
                    return html`<button type="button" class="btn btn-sm ${value === selected ? "btn-primary" : "btn-outline-primary"}" data-action="${action}" data-value="${value}">${label}</button>`;
                })
                .join("")
        : "<div class=\"text-secondary small py-2\">Sem opções disponíveis</div>";
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
 * O mosaico de tipos de dispositivo. O `multiple` separa o filtro da escolha única, e decide
 * que atributos saem. As contagens são opcionais: ao criar um modelo não há o que contar.
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

/**
 * As pastilhas dos filtros aplicados, com o x para remover cada um. O arranjo é sempre o
 * mesmo -- pesquisa e botão de filtros na primeira linha, pastilhas na de baixo --, e entre
 * a listagem de dispositivos e a de modelos o que varia é só o nome da acção.
 */
export function filterChips(labels, action) {
    return labels
        .map(
            (item) => html`
        <span class="filter-chip d-inline-flex align-items-center fw-semibold text-uppercase text-nowrap rounded-pill bg-body-secondary text-secondary">
            <span>${item.label}</span>
            <button type="button" class="filter-chip-remove d-flex align-items-center justify-content-center p-0 border-0 rounded-circle" data-action="${action}"
                data-filter-key="${item.key}" aria-label="Remover filtro ${item.label}">
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
    return html`<div class="text-secondary py-3">${text}</div>`;
}
