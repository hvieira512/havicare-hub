import { changeDeviceFilter, setDeviceFilters, state } from "../state.js";
import {
    FILTERS_STORAGE_KEY,
    clearStorageKey,
    saveJsonStorage,
} from "../storage.js";
import { html, raw } from "../html.js";
import { deviceTypeTiles } from "../components/device-type-tiles.js";
import {
    companyLabel,
    deviceTypeOptions,
    licenseDisplayLabel,
    modelDisplayName,
    normalizeDeviceType,
} from "../domain.js";

/**
 * O painel de filtros da listagem: o mosaico de tipos, as árvores de fornecedores e de
 * licenças, o estado de ligação, e o que cada clique faz a eles.
 *
 * Desenho e comportamento no mesmo sítio, porque marcar um valor e mostrá-lo marcado são a
 * mesma ideia. O que falta é quem volta a pedir a lista: entra por um `onChange` entregue no
 * arranque, e não por um import de volta ao `list.js` -- o grafo de módulos não tem ciclos.
 */

let els;
let onChange = () => {};

export function initListFilters(context) {
    els = context.els;
    onChange = context.onChange;
}

const repeatMarkup = (count, markup) => Array.from({ length: count }, () => markup).join("");

/**
 * As três colunas de filtro sem afirmarem nada.
 *
 * Serve as duas situações em que não se sabe o que lá deve estar: a espera e a falha. Dizer
 * «não há licenças» em qualquer delas é afirmar uma ausência que ninguém mediu, e lê-se como
 * resposta quando é a falta de uma.
 */
export function renderDeviceFilterSkeleton() {
    els.deviceTypeFilter.innerHTML = `
        <div class="placeholder-wave device-type-grid d-grid gap-2">
        ${repeatMarkup(
            6,
            // As mesmas classes da pastilha a sério: a altura vem do mesmo CSS, e a grelha
            // não salta quando os dados chegam.
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
                // A barra vai dentro do nome e não por cima dele: a altura da linha vem do
                // mesmo CSS da opção a sério, e a largura fica a de um nome.
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
}

/**
 * O mosaico de tipos, a aceitar vários. Vêm do catálogo e não das contagens, para que um
 * tipo sem dispositivos apareça apagado -- saber que a frota não tem pulseiras é informação.
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

function filterOptionMarkup({ key, value, label, count, selected, partial = false, nested = false }) {
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
            data-filter-key="${key}" data-filter-value="${value}" aria-pressed="${selected ? "true" : "false"}">
        <span class="filter-option-box d-grid flex-shrink-0"><i class="fa-solid ${partial && !selected ? "fa-minus" : "fa-check"}"></i></span>
        <span class="flex-fill min-w-0 text-truncate">${label}</span>
        <span class="count-number flex-shrink-0">${count}</span>
        </button>`;
}

/**
 * A árvore de fornecedores e modelos, com a forma da das licenças. A diferença é que aqui os
 * dois níveis continuam a ser dois filtros distintos, `supplier` e `model` -- só o desenho é
 * que é comum.
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
 * A árvore de empresas e licenças. Marcar a empresa marca-a toda; marcar algumas deixa-a no
 * traço do meio. O "sem licença" é a primeira e é folha, para não ficar atrás de uma lista
 * que cresce.
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
                        state.settingsModal.licenses || [],
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
    for (const [key, count] of Object.entries(perGroup)) {
        const el = counterEls[key];
        if (!el) continue;
        el.textContent = count ? String(count) : "";
        el.classList.toggle("d-none", count === 0);
    }

    const activeGroups =
        Object.values(perGroup).filter((count) => count > 0).length +
        (state.deviceFilters.online === null ? 0 : 1);
    for (const el of [els.deviceFilterCount, els.deviceFilterCountMobile]) {
        if (!el) continue;
        el.textContent = activeGroups ? String(activeGroups) : "";
        el.classList.toggle("d-none", activeGroups === 0);
    }
    els.clearDeviceFiltersBtn.classList.toggle("d-none", activeGroups === 0);
}

/**
 * Marcar ou desmarcar um valor de filtro. Nada marcado quer dizer tudo, e por isso não há
 * opção "Todos": desmarcar o último valor é o que a repõe.
 *
 * Marcar uma empresa apaga as licenças dela marcadas à parte, senão a condição levava a mesma
 * empresa duas vezes; marcar uma licença de uma empresa inteira troca-a pelas suas licenças.
 */
async function toggleDeviceFilter(key, value) {
    const current = state.deviceFilters[key] || [];
    let next;

    // O modelo mostra-se marcado quando o fornecedor dele está marcado, mas não está na lista
    // do filtro `model` -- quem lá está é o fornecedor. Clicá-lo quer dizer "tira este", e
    // por isso troca-se o fornecedor pelos irmãos, como a licença faz com a empresa.
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
    // Marcar o fornecedor absorve os modelos dele: tê-los na lista ao lado significaria o
    // mesmo fornecedor duas vezes na condição, e a lista estreitava em vez de alargar.
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
