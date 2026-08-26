import {
    setDownlinkPage,
    setTelemetryPage,
    state,
} from "../state.js";
import {
    FILTERS_STORAGE_KEY,
    clearStorageKey,
    saveJsonStorage,
} from "../storage.js";
import {
    loadSummary,
    normalizeFilterValue,
    renderDeviceSelector,
} from "./list-detail.js";
import {
    allDetailItems,
    filterDetailItems,
    renderDownlinkRequests,
    renderTelemetryList,
} from "./detail-view.js";

/**
 * Os filtros da listagem de dispositivos e os paginadores dos dois painéis do escolhido.
 *
 * Estão juntos porque partilham a mesma ideia: leem o estado, mexem-lhe, e voltam a pedir
 * a lista -- nenhum deles constroi marcacao.
 */
let els = {};

export function initDeviceFilterHandlers(context) {
    els = context.els;
}

/**
 * Marcar ou desmarcar um valor de filtro.
 *
 * Nada marcado quer dizer tudo, e é por isso que não há opção "Todos" no topo de cada
 * grupo: desmarcar o último valor é o que a repõe.
 *
 * Marcar uma empresa marca-a inteira, e por isso apaga as licenças dela que estivessem
 * marcadas à parte -- ter as duas coisas na lista significaria a mesma empresa duas vezes
 * na condição. Marcar uma licença de uma empresa que estava inteira troca a empresa pelas
 * suas licenças, para que desmarcar uma só tire essa.
 */
async function toggleDeviceFilter(key, value) {
    const current = state.deviceFilters[key] || [];
    let next;

    if (current.includes(value)) {
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

    state.deviceFilters = { ...state.deviceFilters, [key]: next };
    state.deviceListPage = 1;
    saveJsonStorage(FILTERS_STORAGE_KEY, state.deviceFilters);
    await loadSummary();
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
    const tree = state.summary.deviceFilterCounts?.license || {companies: []};
    const entry = (tree.companies || []).find(
        (candidate) => String(candidate.company) === company,
    );
    return (entry?.licenses || []).map((license) => `${company}:${license.licenseId}`);
}

export async function handleDeviceFilterClick(event) {
    const button = event.target.closest('[data-action="toggleDeviceFilter"]');
    if (!button || button.disabled) return;
    const key = button.dataset.filterKey;
    const value = button.dataset.filterValue;
    if (!key || value === undefined || !(key in state.deviceFilters)) return;
    await toggleDeviceFilter(key, value);
}

export async function handleDeviceOnlineFilterChange(event) {
    const value = event.target.value;
    state.deviceFilters = {
        ...state.deviceFilters,
        online: value === "all" ? null : value === "online",
    };
    state.deviceListPage = 1;
    saveJsonStorage(FILTERS_STORAGE_KEY, state.deviceFilters);
    await loadSummary();
}

export function handleDeviceModelFilterSearch() {
    state.deviceModelFilterSearch = els.deviceModelFilterSearch.value;
    renderDeviceSelector();
}

export async function clearDeviceFilters() {
    state.deviceFilters = {
        deviceType: [],
        supplier: [],
        model: [],
        license: [],
        online: null,
    };
    state.deviceModelFilterSearch = "";
    state.deviceListPage = 1;
    clearStorageKey(FILTERS_STORAGE_KEY);
    await loadSummary();
}

export function handleDownlinkPagerClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button || !state.selectedDetail) return;

    const commands = filterDetailItems(allDetailItems())
        .filter((item) => item._source === "command")
        .map((item) => item.raw);
    const totalPages = Math.max(
        1,
        Math.ceil(commands.length / state.downlinkPageSize),
    );

    if (button.dataset.action === "downlinkPrev")
        setDownlinkPage(state.downlinkPage - 1, totalPages);
    if (button.dataset.action === "downlinkNext")
        setDownlinkPage(state.downlinkPage + 1, totalPages);
    if (button.dataset.action === "downlinkPageGo")
        setDownlinkPage(parseInt(button.dataset.page || "1", 10), totalPages);

    renderDownlinkRequests(commands);
}

export function handleTelemetryPagerClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button || !state.selectedDetail) return;
    const allItems = allDetailItems();
    const filtered = filterDetailItems(allItems);
    const telemetryRows = filtered
        .filter((item) => ["telemetry", "event"].includes(item._source))
        .map((item) => item.raw);
    const totalPages = Math.max(
        1,
        Math.ceil(telemetryRows.length / state.telemetryPageSize),
    );
    if (button.dataset.action === "telemetryPrev")
        setTelemetryPage(state.telemetryPage - 1, totalPages);
    if (button.dataset.action === "telemetryNext")
        setTelemetryPage(state.telemetryPage + 1, totalPages);
    if (button.dataset.action === "telemetryPageGo")
        setTelemetryPage(parseInt(button.dataset.page || "1", 10), totalPages);
    renderTelemetryList(telemetryRows);
}
