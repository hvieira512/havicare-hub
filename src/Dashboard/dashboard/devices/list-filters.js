import { changeDeviceFilter, setDeviceFilters, state } from "../state.js";
import { html } from "../html.js";
import { filterChips } from "../components/chips.js";
import { filterBranch, filterOption } from "../components/filter-tree.js";
import { deviceTypeTiles } from "../components/device-type-tiles.js";
import {
    companyLabel,
    deviceTypeLabel,
    deviceTypeOptions,
    licenseDisplayLabel,
    modelDisplayName,
    normalizeDeviceType,
} from "../domain.js";
import { els } from "../dom.js";
import { loadSummary } from "./list.js";

/** O painel de filtros da listagem. */

const repeatMarkup = (count, markup) => Array.from({ length: count }, () => markup).join("");

/** Na espera e na falha as colunas não afirmam nada: «não há licenças» ninguém o mediu. */
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

/** Os tipos vêm do catálogo e não das contagens: um tipo sem dispositivos aparece apagado. */
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

/** Uma caixa de filtro: o grupo e o valor viajam no botão, para o ouvinte delegado. */
function deviceFilterOption({ key, value, selected, disabled = false, ...rest }) {
    return filterOption({
        ...rest,
        selected,
        attrs: html`data-action="toggleDeviceFilter" data-filter-key="${key}" data-filter-value="${value}" aria-pressed="${selected ? "true" : "false"}"${disabled ? " disabled" : ""}`,
    });
}

/** Cada pai com os filhos por baixo; vazia, diz que não há nada. */
function filterTree(groups, emptyText) {
    if (groups.length === 0) {
        return html`<div class="small text-secondary px-1 py-2">${emptyText}</div>`;
    }

    return html`${groups.map(({ parent, children = [] }) => html`${deviceFilterOption(parent)}${children.length ? filterBranch(children.map(deviceFilterOption)) : ""}`)}`;
}

/** Desenhada como a das licenças, mas os dois níveis são filtros distintos. */
function renderDeviceSupplierFilter() {
    const tree = state.summary.deviceFilterCounts?.supplierModels || { suppliers: [] };
    const selectedSuppliers = state.deviceFilters.supplier;
    const selectedModels = state.deviceFilters.model;

    const groups = (tree.suppliers || []).map((entry) => {
        const supplier = String(entry.supplier);
        const models = entry.models || [];
        const supplierSelected = selectedSuppliers.includes(supplier);

        return {
            parent: {
                key: "supplier",
                value: supplier,
                label: supplier,
                count: entry.count,
                selected: supplierSelected,
                partial: !supplierSelected && models.some((model) => selectedModels.includes(String(model.model))),
            },
            children: models.map((model) => ({
                key: "model",
                value: String(model.model),
                label: modelDisplayName(supplier, String(model.model)),
                count: model.count,
                selected: supplierSelected || selectedModels.includes(String(model.model)),
                nested: true,
            })),
        };
    });

    els.deviceSupplierFilter.innerHTML = filterTree(groups, "Não há fornecedores para mostrar.");
}

/** Marcar algumas licenças deixa a empresa no traço do meio; o «sem licença» vem primeiro. */
function renderDeviceLicenseFilter() {
    const tree = state.summary.deviceFilterCounts?.license || { companies: [], none: 0 };
    const selected = state.deviceFilters.license;
    const groups = [];

    if (tree.none > 0) {
        groups.push({
            parent: { key: "license", value: "none", label: "Sem licença", count: tree.none, selected: selected.includes("none") },
        });
    }

    for (const company of tree.companies || []) {
        const name = String(company.company);
        const licenses = company.licenses || [];
        const companySelected = selected.includes(name);

        groups.push({
            parent: {
                key: "license",
                value: name,
                label: companyLabel(name),
                count: company.count,
                selected: companySelected,
                partial: !companySelected && licenses.some((license) => selected.includes(`${name}:${license.licenseId}`)),
            },
            children: licenses.map((license) => ({
                key: "license",
                value: `${name}:${license.licenseId}`,
                label: licenseDisplayLabel(license.licenseId, state.licenses || []),
                count: license.count,
                selected: companySelected || selected.includes(`${name}:${license.licenseId}`),
                nested: true,
            })),
        });
    }

    els.deviceLicenseFilter.innerHTML = filterTree(groups, "Não há licenças para mostrar.");
}

/** O contador do topo conta os grupos com filtro aplicado, e não os valores marcados. */
function renderDeviceFilterCounters() {
    const perGroup = {
        deviceType: state.deviceFilters.deviceType.length,
        // Dois filtros num só grupo no ecrã: a pastilha conta os dois.
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

/** No telemóvel o painel cobre a lista, e o número do rodapé substitui vê-la. */
function renderApplyDeviceFiltersButton() {
    const total = state.summary.devicePagination?.total ?? 0;
    els.applyDeviceFiltersBtn.textContent = total === 0
        ? "Nenhum dispositivo"
        : `Ver ${total} dispositivo${total === 1 ? "" : "s"}`;
}

/** O grupo e o valor vão numa chave só, e o valor de uma licença leva dois pontos: parte-se no primeiro. */
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
        await loadSummary();
        return;
    }
    if (!(key in state.deviceFilters)) return;
    // O valor está aplicado, e por isso o `toggle` só o pode tirar.
    await toggleDeviceFilter(key, value);
}

/**
 * Nada marcado quer dizer tudo. Marcar uma empresa absorve as licenças dela; clicar numa
 * licença de uma empresa marcada troca a empresa pelas outras licenças.
 */
async function toggleDeviceFilter(key, value) {
    const current = state.deviceFilters[key] || [];
    let next;

    // Clicar num modelo coberto pelo fornecedor marcado troca o fornecedor pelos irmãos.
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
    await loadSummary();
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
    await loadSummary();
}

export async function clearDeviceFilters() {
    setDeviceFilters({
        deviceType: [],
        supplier: [],
        model: [],
        license: [],
        online: null,
    });
    await loadSummary();
}

export function storedFilterList(value) {
    return Array.isArray(value) ? value.map(String) : [];
}
