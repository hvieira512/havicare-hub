import { changeDeviceFilter, setDeviceFilters, state } from "../state.js";
import {
    FILTERS_STORAGE_KEY,
    clearStorageKey,
    saveJsonStorage,
} from "../storage.js";
import { html, raw } from "../html.js";
import { filterChips } from "../components/chips.js";
import { deviceTypeTiles } from "../components/device-type-tiles.js";
import {
    companyLabel,
    deviceTypeLabel,
    deviceTypeOptions,
    licenseDisplayLabel,
    modelDisplayName,
    normalizeDeviceType,
} from "../domain.js";

/**
 * O painel de filtros da listagem, desenho e comportamento. Quem volta a pedir a lista entra
 * pelo `onChange` do arranque, e não por um import do `list.js`, para não haver ciclos.
 */

let els;
let onChange = () => {};

export function initListFilters(context) {
    els = context.els;
    onChange = context.onChange;
}

const repeatMarkup = (count, markup) => Array.from({ length: count }, () => markup).join("");

/**
 * As colunas de filtro sem afirmarem nada, na espera e na falha: «não há licenças» seria
 * afirmar uma ausência que ninguém mediu.
 */
export function renderDeviceFilterSkeleton() {
    els.deviceTypeFilter.innerHTML = `
        <div class="placeholder-wave device-type-grid d-grid gap-2">
        ${repeatMarkup(
            6,
            `<div class="device-type-tile" aria-hidden="true">
                <span class="device-type-tile-icon"><i class="fa-solid fa-square placeholder"></i></span>
                <span class="device-type-tile-name placeholder col-7">&nbsp;</span>
                <span class="count-number placeholder col-5">&nbsp;</span>
            </div>`,
        )}
        </div>`;

    for (const el of [els.deviceSupplierFilter, els.deviceLicenseFilter]) {
        el.innerHTML = `
            <div class="placeholder-wave">
            ${repeatMarkup(
                3,
                `<div class="filter-option d-flex align-items-center text-start rounded-2" aria-hidden="true">
                    <span class="filter-option-box d-grid flex-shrink-0"></span>
                    <span class="flex-fill min-w-0 text-truncate"><span class="placeholder col-6">&nbsp;</span></span>
                </div>`,
            )}
            </div>`;
    }
}

export function renderDeviceFilterControls() {
    renderDeviceTypeFilter();
    renderDeviceSupplierFilter();
    renderDeviceLicenseFilter();

    for (const input of document.querySelectorAll("input[name=\"deviceOnlineFilter\"]")) {
        const { online } = state.deviceFilters;
        const value = online === null ? "all" : online ? "online" : "offline";
        input.checked = input.value === value;
    }

    renderDeviceFilterCounters();
    renderApplyDeviceFiltersButton();
}

/**
 * Os tipos, a aceitar vários. Vêm do catálogo e não das contagens, para que um tipo sem
 * dispositivos apareça apagado -- saber que a frota não tem pulseiras é informação.
 */
function renderDeviceTypeFilter() {
    const counts = new Map(
        (state.summary.deviceFilterCounts?.deviceType || []).map((option) => [
            normalizeDeviceType(option.value),
            option.count,
        ]),
    );

    els.deviceTypeFilter.innerHTML = deviceTypeTiles(deviceTypeOptions, {
        selected: state.deviceFilters.deviceType,
        multiple: true,
        counts,
    });
}

function filterOptionMarkup({
    key,
    value,
    label,
    count,
    selected,
    partial = false,
    nested = false,
    disabled = false,
}) {
    const classes = [
        "filter-option d-flex align-items-center text-start rounded-2",
        nested ? "filter-option-nested" : "",
        selected ? "selected" : "",
        partial ? "partial" : "",
    ]
        .filter(Boolean)
        .join(" ");

    return html`
        <button type="button" class="${classes}" data-action="toggleDeviceFilter"
            data-filter-key="${key}" data-filter-value="${value}" aria-pressed="${selected ? "true" : "false"}"
            ${disabled ? "disabled" : ""}>
        <span class="filter-option-box d-grid flex-shrink-0"><i class="fa-solid ${partial && !selected ? "fa-minus" : "fa-check"}"></i></span>
        <span class="flex-fill min-w-0 text-truncate">${label}</span>
        <span class="count-number flex-shrink-0">${count}</span>
        </button>`;
}

/**
 * A árvore de fornecedores e modelos, com o desenho da das licenças, mas os dois níveis são
 * filtros distintos, `supplier` e `model`.
 */
function renderDeviceSupplierFilter() {
    const tree = state.summary.deviceFilterCounts?.supplierModels || { suppliers: [] };
    const selectedSuppliers = state.deviceFilters.supplier;
    const selectedModels = state.deviceFilters.model;
    const rows = [];

    for (const entry of tree.suppliers || []) {
        const supplier = String(entry.supplier);
        const models = entry.models || [];
        const supplierSelected = selectedSuppliers.includes(supplier);
        const someModelSelected = models.some((model) =>
            selectedModels.includes(String(model.model)),
        );

        rows.push(
            filterOptionMarkup({
                key: "supplier",
                value: supplier,
                label: supplier,
                count: entry.count,
                selected: supplierSelected,
                partial: !supplierSelected && someModelSelected,
            }),
        );

        if (models.length === 0) {
            continue;
        }

        const branch = models
            .map((model) => {
                const value = String(model.model);
                return filterOptionMarkup({
                    key: "model",
                    value,
                    label: modelDisplayName(supplier, value),
                    count: model.count,
                    selected: supplierSelected || selectedModels.includes(value),
                    nested: true,
                });
            })
            .join("");

        rows.push(html`<div class="filter-branch position-relative d-flex flex-column">${raw(branch)}</div>`);
    }

    els.deviceSupplierFilter.innerHTML = rows.length
        ? rows.join("")
        : "<div class=\"small text-secondary px-1 py-2\">Não há fornecedores para mostrar.</div>";
}

/**
 * Marcar a empresa marca-a toda; marcar algumas licenças deixa-a no traço do meio. O «sem
 * licença» vem primeiro, para não ficar atrás de uma lista que cresce.
 */
function renderDeviceLicenseFilter() {
    const tree = state.summary.deviceFilterCounts?.license || { companies: [], none: 0 };
    const selected = state.deviceFilters.license;
    const rows = [];

    if (tree.none > 0) {
        rows.push(
            filterOptionMarkup({
                key: "license",
                value: "none",
                label: "Sem licença",
                count: tree.none,
                selected: selected.includes("none"),
            }),
        );
    }

    for (const company of tree.companies || []) {
        const name = String(company.company);
        const licenses = company.licenses || [];
        const licenseValues = licenses.map((license) => `${name}:${license.licenseId}`);
        const companySelected = selected.includes(name);
        const someLicenseSelected = licenseValues.some((value) => selected.includes(value));

        rows.push(
            filterOptionMarkup({
                key: "license",
                value: name,
                label: companyLabel(name),
                count: company.count,
                selected: companySelected,
                partial: !companySelected && someLicenseSelected,
            }),
        );

        if (licenses.length === 0) {
            continue;
        }

        const branch = licenses
            .map((license) => {
                const value = `${name}:${license.licenseId}`;
                return filterOptionMarkup({
                    key: "license",
                    value,
                    label: licenseDisplayLabel(
                        license.licenseId,
                        state.licenses || [],
                    ),
                    count: license.count,
                    selected: companySelected || selected.includes(value),
                    nested: true,
                });
            })
            .join("");

        rows.push(html`<div class="filter-branch position-relative d-flex flex-column">${raw(branch)}</div>`);
    }

    els.deviceLicenseFilter.innerHTML = rows.length
        ? rows.join("")
        : "<div class=\"small text-secondary px-1 py-2\">Não há licenças para mostrar.</div>";
}

/**
 * Os contadores ao lado de cada título. O do topo conta os grupos com filtro aplicado e
 * não os valores marcados: diz quantas coisas estão a estreitar a lista.
 */
function renderDeviceFilterCounters() {
    const perGroup = {
        deviceType: state.deviceFilters.deviceType.length,
        // Dois filtros num só grupo no ecrã: a pastilha conta os dois, senão marcar um
        // modelo estreitava a lista sem que nada o dissesse.
        supplier:
            state.deviceFilters.supplier.length + state.deviceFilters.model.length,
        license: state.deviceFilters.license.length,
    };
    const counterEls = {
        deviceType: els.deviceTypeFilterCount,
        supplier: els.deviceSupplierFilterCount,
        license: els.deviceLicenseFilterCount,
    };
    // Só o texto: a pastilha vazia desaparece pelo `.count-chip:empty` do `shell.css`.
    for (const [key, count] of Object.entries(perGroup)) {
        const el = counterEls[key];
        if (!el) continue;
        el.textContent = count ? String(count) : "";
    }

    const activeGroups =
        Object.values(perGroup).filter((count) => count > 0).length +
        (state.deviceFilters.online === null ? 0 : 1);
    for (const el of [els.deviceFilterCount, els.deviceFilterCountMobile]) {
        if (!el) continue;
        el.textContent = activeGroups ? String(activeGroups) : "";
    }
    els.clearDeviceFiltersBtn.classList.toggle("d-none", activeGroups === 0);
}

/**
 * O rodapé do painel. No telemóvel o painel cobre a lista, e este número é o que substitui
 * o vê-la: sem ele, filtra-se às cegas até fechar.
 */
function renderApplyDeviceFiltersButton() {
    const total = state.summary.devicePagination?.total ?? 0;
    els.applyDeviceFiltersBtn.textContent = total === 0
        ? "Nenhum dispositivo"
        : `Ver ${total} dispositivo${total === 1 ? "" : "s"}`;
}

/**
 * O que cada filtro aplicado diz na sua pastilha. O grupo e o valor vão numa chave só, e o
 * valor de uma licença já leva dois pontos -- quem a parte, parte no primeiro.
 */
export function deviceFilterChipLabels(filters) {
    const labels = [];

    if (filters.online === true || filters.online === false) {
        labels.push({
            key: `online:${filters.online ? "online" : "offline"}`,
            label: filters.online ? "Ligados" : "Desligados",
        });
    }
    for (const value of filters.deviceType || []) {
        labels.push({ key: `deviceType:${value}`, label: deviceTypeLabel(value) });
    }
    for (const value of filters.supplier || []) {
        labels.push({ key: `supplier:${value}`, label: value });
    }
    for (const value of filters.model || []) {
        labels.push({ key: `model:${value}`, label: value });
    }
    for (const value of filters.license || []) {
        labels.push({ key: `license:${value}`, label: licenseChipLabel(value) });
    }

    return labels;
}

function licenseChipLabel(value) {
    if (value === "none") return "Sem licença";
    const separator = value.lastIndexOf(":");

    return separator < 0
        ? companyLabel(value)
        : licenseDisplayLabel(value.slice(separator + 1), state.licenses || []);
}

/** As pastilhas do que está aplicado, por cima da lista e fora do painel. */
export function renderDeviceActiveFilters() {
    const labels = deviceFilterChipLabels(state.deviceFilters);

    els.deviceActiveFilters.innerHTML = filterChips(labels, "removeDeviceFilter");
    els.deviceActiveFilters.classList.toggle("d-none", labels.length === 0);
}

export async function handleDeviceFilterChipRemove(event) {
    const button = event.target.closest("[data-action=\"removeDeviceFilter\"]");
    if (!button) return;
    const separator = String(button.dataset.filterKey || "").indexOf(":");
    if (separator < 0) return;
    const key = button.dataset.filterKey.slice(0, separator);
    const value = button.dataset.filterKey.slice(separator + 1);

    if (key === "online") {
        changeDeviceFilter("online", null);
        saveJsonStorage(FILTERS_STORAGE_KEY, state.deviceFilters);
        await onChange();
        return;
    }
    if (!(key in state.deviceFilters)) return;
    // O valor está aplicado, e por isso o `toggle` só o pode tirar -- as trocas da árvore
    // ficam todas do lado de lá da condição.
    await toggleDeviceFilter(key, value);
}

/**
 * Nada marcado quer dizer tudo, por isso não há «Todos». Marcar uma empresa absorve as licenças
 * dela; clicar numa licença de uma empresa marcada troca a empresa pelas outras licenças.
 */
async function toggleDeviceFilter(key, value) {
    const current = state.deviceFilters[key] || [];
    let next;

    // Um modelo coberto pelo fornecedor marcado não está no filtro `model`: clicá-lo troca o
    // fornecedor pelos irmãos, como a licença faz com a empresa.
    const supplierOfClickedModel = key === "model" ? supplierOwningModel(value) : null;
    const clickedModelIsCoveredBySupplier =
        supplierOfClickedModel !== null &&
        !current.includes(value) &&
        (state.deviceFilters.supplier || []).includes(supplierOfClickedModel);

    if (clickedModelIsCoveredBySupplier) {
        const siblings = modelsForSupplier(supplierOfClickedModel).filter(
            (model) => model !== value,
        );
        changeDeviceFilter(
            "supplier",
            (state.deviceFilters.supplier || []).filter(
                (entry) => entry !== supplierOfClickedModel,
            ),
        );
        next = [...new Set([...current, ...siblings])];
    } else if (current.includes(value)) {
        next = current.filter((entry) => entry !== value);
    } else if (key === "license" && !value.includes(":") && value !== "none") {
        next = [...current.filter((entry) => !entry.startsWith(`${value}:`)), value];
    } else if (key === "license" && value.includes(":")) {
        const company = value.slice(0, value.lastIndexOf(":"));
        if (current.includes(company)) {
            const siblings = licenseValuesForCompany(company).filter(
                (entry) => entry !== value,
            );
            next = [...current.filter((entry) => entry !== company), ...siblings];
        } else {
            next = [...current, value];
        }
    } else {
        next = [...current, value];
    }

    changeDeviceFilter(key, next);
    // Marcar o fornecedor absorve os modelos dele, para a condição não o levar duas vezes.
    if (key === "supplier" && next.includes(value)) {
        const owned = modelsForSupplier(value);
        changeDeviceFilter(
            "model",
            (state.deviceFilters.model || []).filter((model) => !owned.includes(model)),
        );
    }
    saveJsonStorage(FILTERS_STORAGE_KEY, state.deviceFilters);
    await onChange();
}

function modelsForSupplier(supplier) {
    const tree = state.summary.deviceFilterCounts?.supplierModels || { suppliers: [] };
    const entry = (tree.suppliers || []).find(
        (candidate) => String(candidate.supplier) === supplier,
    );
    return (entry?.models || []).map((model) => String(model.model));
}

function supplierOwningModel(model) {
    const tree = state.summary.deviceFilterCounts?.supplierModels || { suppliers: [] };
    const entry = (tree.suppliers || []).find((candidate) =>
        (candidate.models || []).some((each) => String(each.model) === model),
    );
    return entry ? String(entry.supplier) : null;
}

function licenseValuesForCompany(company) {
    const tree = state.summary.deviceFilterCounts?.license || { companies: [] };
    const entry = (tree.companies || []).find(
        (candidate) => String(candidate.company) === company,
    );
    return (entry?.licenses || []).map((license) => `${company}:${license.licenseId}`);
}

export async function handleDeviceFilterClick(event) {
    const button = event.target.closest("[data-action=\"toggleDeviceFilter\"]");
    if (!button || button.disabled) return;
    const key = button.dataset.filterKey;
    const value = button.dataset.filterValue;
    if (!key || value === undefined || !(key in state.deviceFilters)) return;
    await toggleDeviceFilter(key, value);
}

export async function handleDeviceOnlineFilterChange(event) {
    const value = event.target.value;
    changeDeviceFilter("online", value === "all" ? null : value === "online");
    saveJsonStorage(FILTERS_STORAGE_KEY, state.deviceFilters);
    await onChange();
}

export async function clearDeviceFilters() {
    setDeviceFilters({
        deviceType: [],
        supplier: [],
        model: [],
        license: [],
        online: null,
    });
    clearStorageKey(FILTERS_STORAGE_KEY);
    await onChange();
}

function normalizeFilterValue(value) {
    if (!value || value === "undefined" || value === "all") return null;
    return String(value);
}

/** Um filtro guardado, seja lista ou valor único da forma antiga. */
export function storedFilterList(value) {
    if (Array.isArray(value)) {
        return value.map(String).filter((entry) => entry !== "" && entry !== "all");
    }
    const single = normalizeFilterValue(value);
    return single === null ? [] : [single];
}
