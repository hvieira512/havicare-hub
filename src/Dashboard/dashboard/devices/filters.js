import { changeDeviceFilter, setDeviceFilters, state } from "../state.js";
import {
    FILTERS_STORAGE_KEY,
    clearStorageKey,
    saveJsonStorage,
} from "../storage.js";
import { loadSummary, normalizeFilterValue } from "./list.js";

/**
 * O que cada clique nos filtros da listagem faz: mexe no estado, guarda-o e volta a pedir a
 * lista. A marcação das colunas é do `list.js`.
 */

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

/** Um filtro guardado, seja lista ou valor único da forma antiga. */
export function storedFilterList(value) {
    if (Array.isArray(value)) {
        return value.map(String).filter((entry) => entry !== "" && entry !== "all");
    }
    const single = normalizeFilterValue(value);
    return single === null ? [] : [single];
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
    clearStorageKey(FILTERS_STORAGE_KEY);
    await loadSummary();
}
