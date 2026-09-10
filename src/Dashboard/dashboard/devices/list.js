import {
    getDevice as apiGetDevice,
    getDevices as apiGetDevices,
    getProtocols as apiGetProtocols,
} from "../api/index.js";
import { getDeviceTypeSuppliersModels as apiGetDeviceTypeSuppliersModels } from "../api/models.js";
import { ensureCapabilityCatalog } from "../capability-catalog.js";
import { ensureLicensesLoaded } from "../licenses.js";
import {
    state,
    clearSelection,
    resetDetailFiltersDraft,
    resetDeviceListPage,
    selectImei,
    setDeviceListPage,
    setDeviceTypeSuppliersModels,
    setSelectedDetail,
} from "../state.js";
import { html, raw } from "../html.js";
import { emptyPanel, renderDeviceTypeTiles } from "../widgets.js";
import { deviceCard, deviceCardSkeletonList } from "./device-card.js";
import { renderPagination, resolvePaginationPage } from "../pagination.js";
import {
    companyLabel,
    deviceTypeOptions,
    licenseDisplayLabel,
    modelDisplayName,
    normalizeDeviceType,
} from "../domain.js";
import {
    initDeviceDetailView,
    clearSelectedDeviceFromStorage,
    renderSelection as renderSelectionDetail,
    saveSelectedDeviceToStorage,
} from "./detail.js";
import { connectDeviceStream, disconnectDeviceStream } from "./stream.js";

/**
 * A coluna da esquerda: a lista, a paginação, a busca e o modal de escolher dispositivo.
 * Escolher uma linha é o que liga esta coluna à da direita, e daqui chama-se o `detail.js`.
 */
let els;
let ui;
let deviceSearchTimer = null;

export function initDeviceList(context) {
    els = context.els;
    ui = context.ui;
    initDeviceDetailView(context);
}

function normalizeFilterValue(value) {
    if (!value || value === "undefined" || value === "all") return null;
    return String(value);
}

/**
 * Enquanto o pedido corre, a lista fica marcada como ocupada: em filtro, pesquisa ou
 * página, o conteúdo antigo continua no ecrã e nada mais diria que está a mudar.
 */
async function loadSummary() {
    els.deviceList?.setAttribute("aria-busy", "true");
    try {
        return await fetchSummary();
    } finally {
        els.deviceList?.removeAttribute("aria-busy");
    }
}

async function fetchSummary() {
    const { online } = state.deviceFilters;
    const [devicesResponse] = await Promise.all([
        apiGetDevices({
            page: state.deviceListPage,
            limit: state.deviceListPageSize,
            deviceType: state.deviceFilters.deviceType,
            supplier: state.deviceFilters.supplier,
            model: state.deviceFilters.model,
            license: state.deviceFilters.license,
            online: online === null ? null : online ? "online" : "offline",
            q: state.deviceSearchQuery,
        }),
        ensureLicensesLoaded(),
    ]);
    state.summary = {
        devices: devicesResponse.data || [],
        devicesError: devicesResponse.error || null,
        models: state.summary.models || [],
        devicePagination: devicesResponse.pagination || {
            limit: state.deviceListPageSize,
            page: 1,
            total_pages: 1,
            total: 0,
        },
        deviceFiltersAvailable: devicesResponse.filters?.available || {
            deviceType: [],
            licenseId: [],
            company: [],
            supplier: [],
            model: [],
        },
        deviceFilterCounts: devicesResponse.filters?.counts || {
            deviceType: [],
            supplier: [],
            model: [],
            license: { companies: [], none: 0 },
        },
        deviceTotals: devicesResponse.summary || { total: 0, online: 0 },
    };
    state.deviceListPageSize =
        state.summary.devicePagination.limit || state.deviceListPageSize;
    setDeviceListPage(state.summary.devicePagination.page);
    renderDeviceSelector();
    if (state.selectedImei) {
        await loadDevice(state.selectedImei);
    } else {
        renderSelectionDetail();
    }
}

function flattenDeviceTypeSuppliersModels(groups = []) {
    const models = [];

    for (const group of groups || []) {
        const deviceType = normalizeDeviceType(group.deviceType || "watch");
        for (const supplier of group.suppliers || []) {
            const supplierName = String(supplier.name || "").trim();
            for (const model of supplier.models || []) {
                models.push({
                    ...model,
                    supplier: supplierName,
                    deviceType,
                    enabled: !!supplier.enabled,
                });
            }
        }
    }

    return models;
}

async function ensureDeviceTypeSuppliersModelsLoaded(force = false) {
    if (
        !force &&
        Array.isArray(state.deviceTypeSuppliersModels) &&
        state.deviceTypeSuppliersModels.length > 0
    ) {
        return state.deviceTypeSuppliersModels;
    }

    const response = await apiGetDeviceTypeSuppliersModels();
    const groups = response?.error ? [] : response.data || [];
    setDeviceTypeSuppliersModels(flattenDeviceTypeSuppliersModels(groups));
    return state.deviceTypeSuppliersModels;
}

async function ensureProtocolsLoaded(force = false) {
    if (!force && Array.isArray(state.protocols) && state.protocols.length > 0) {
        return state.protocols;
    }

    const protocolsResponse = await apiGetProtocols();
    state.protocols = protocolsResponse?.error ? [] : protocolsResponse.data || [];
    return state.protocols;
}

/**
 * Abre primeiro e enche-se quando a resposta chega: com o `show()` depois do `await`, um
 * pedido lento deixava o botão sem resposta. O esqueleto só aparece na primeira abertura.
 */
async function openDeviceSelector() {
    ui.deviceSelectorModal?.show();
    if (!els.deviceList.childElementCount) {
        renderDeviceSelectorSkeleton();
    }
    await loadSummary();
}

const repeatMarkup = (count, markup) => Array.from({ length: count }, () => markup).join("");

/**
 * As três colunas de filtro sem afirmarem nada.
 *
 * Serve as duas situações em que não se sabe o que lá deve estar: a espera e a falha. Dizer
 * «não há licenças» em qualquer delas é afirmar uma ausência que ninguém mediu, e lê-se como
 * resposta quando é a falta de uma.
 */
function renderDeviceFilterSkeleton() {
    els.deviceTypeFilter.innerHTML = `
        <div class="placeholder-wave device-type-grid">
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
                // A barra vai dentro do `filter-option-name` e não por cima dele: a altura da
                // linha vem do mesmo CSS da opção a sério, e a largura fica a de um nome.
                `<div class="filter-option" aria-hidden="true">
                    <span class="filter-option-box"></span>
                    <span class="filter-option-name"><span class="placeholder col-6">&nbsp;</span></span>
                </div>`,
            )}
            </div>`;
    }
}

/**
 * O esqueleto da lista e dos filtros. Cada linha é o cartão a sério com barras no lugar do
 * texto, para a lista não saltar quando os dados chegam.
 */
function renderDeviceSelectorSkeleton() {
    els.deviceList.innerHTML = deviceCardSkeletonList(state.deviceListPageSize);
    renderDeviceFilterSkeleton();
}

function isDeviceSelectorOpen() {
    const modalEl = document.getElementById("deviceSelectorModal");
    return !!modalEl && modalEl.classList.contains("show");
}

function renderDeviceSelector() {
    if (els.deviceListLimit) {
        els.deviceListLimit.value = String(state.deviceListPageSize);
    }
    if (els.deviceListSearch) {
        els.deviceListSearch.value = state.deviceSearchQuery;
    }
    // Uma falha diz-se uma vez, na lista, que é onde o utilizador está. As colunas que
    // dependem da mesma resposta ficam em esqueleto em vez de anunciarem vazio.
    if (state.summary.devicesError) renderDeviceFilterSkeleton();
    else renderDeviceFilterControls();

    renderDeviceSelectorSummary();

    els.deviceList.innerHTML = deviceListBody(state.summary);
    renderDevicePagination(state.summary.devicePagination);
}

/**
 * O corpo da lista: os cartões, ou -- na falta deles -- a distinção entre um filtro sem
 * resultados e uma falha a carregar, que não se podem ler como a mesma coisa.
 */
export function deviceListBody(summary) {
    if (summary.devices.length) {
        return summary.devices.map(renderDeviceCard).join("");
    }
    if (summary.devicesError) {
        return html`<div class="text-danger py-3 text-center d-flex flex-column align-items-center gap-2">
            <span>Não foi possível carregar os dispositivos.</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-action="retryDeviceList">Tentar de novo</button>
        </div>`;
    }
    const empty = deviceListEmptyState(state.deviceFilters, state.deviceSearchQuery);
    if (!empty.canClear) {
        return emptyPanel(empty.message);
    }

    return html`<div class="text-secondary py-3 text-center d-flex flex-column align-items-center gap-2">
        <span>${empty.message}</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-action="clearDeviceFilters">Limpar filtros</button>
    </div>`;
}

/** Como se chama cada grupo de filtro no meio de uma frase. */
const FILTER_GROUP_LABEL = {
    deviceType: "tipo",
    supplier: "modelo",
    model: "modelo",
    license: "licença",
};

/**
 * O que dizer quando a lista sai vazia.
 *
 * Os filtros persistem entre sessões, e um deles esquecido faz uma procura pelo IMEI exacto
 * responder «não há dispositivos» -- que se lê como «esse aparelho não existe». O vazio tem de
 * dizer o que o está a causar e trazer consigo o botão que o desfaz.
 */
export function deviceListEmptyState(filters, query) {
    const search = String(query || "").trim();
    // O `online` é booleano e não uma string: `true` são os ligados, `false` os desligados, e
    // `null` é não filtrar por estado.
    const groups = [];
    if (filters.online === true) groups.push("ligados");
    if (filters.online === false) groups.push("desligados");
    for (const [key, label] of Object.entries(FILTER_GROUP_LABEL)) {
        if ((filters[key] || []).length > 0 && !groups.includes(label)) {
            groups.push(label);
        }
    }

    if (groups.length === 0) {
        return {
            canClear: false,
            message: search === ""
                ? "Não há dispositivos registados."
                : `Nenhum dispositivo corresponde a «${search}».`,
        };
    }

    const applied = groups.join(", ");

    return {
        canClear: true,
        message: search === ""
            ? `Nenhum dispositivo passa os filtros aplicados (${applied}).`
            : `Nenhum dispositivo corresponde a «${search}» entre os que os filtros deixam ver (${applied}).`,
    };
}

function renderDeviceSelectorSummary() {
    if (!els.deviceSelectorSummary) return;
    // Uma contagem da resposta anterior ao lado de um erro lê-se como se ainda valesse.
    const { total, online } = state.summary.devicesError
        ? { total: 0, online: 0 }
        : state.summary.deviceTotals || { total: 0, online: 0 };
    els.deviceSelectorSummary.textContent = total
        ? `${total} ${total === 1 ? "dispositivo" : "dispositivos"} · ${online} ligado${online === 1 ? "" : "s"}`
        : "";
}

/** Os vizinhos são os da mesma página: é com esses que o identificador se confunde. */
function renderDeviceCard(device, _index, all) {
    return deviceCard(
        device,
        state.selectedImei === device.imei,
        all.map((other) => other.imei),
    );
}

function renderDevicePagination(pagination) {
    renderPagination({
        pagination,
        rootEl: els.deviceListPagination,
        summaryEl: els.deviceListPaginationSummary,
        controlsEl: els.deviceListPaginationControls,
        actionPrefix: "devicePage",
        defaultLimit: state.deviceListPageSize,
    });
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

    renderDeviceTypeTiles(els.deviceTypeFilter, deviceTypeOptions, {
        selected: state.deviceFilters.deviceType,
        multiple: true,
        counts,
    });
}

function filterOptionMarkup({ key, value, label, count, selected, partial = false, nested = false }) {
    const classes = [
        "filter-option",
        nested ? "filter-option-nested" : "",
        selected ? "selected" : "",
        partial ? "partial" : "",
    ]
        .filter(Boolean)
        .join(" ");

    return html`
        <button type="button" class="${classes}" data-action="toggleDeviceFilter"
            data-filter-key="${key}" data-filter-value="${value}" aria-pressed="${selected ? "true" : "false"}">
        <span class="filter-option-box"><i class="fa-solid ${partial && !selected ? "fa-minus" : "fa-check"}"></i></span>
        <span class="filter-option-name">${label}</span>
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

        rows.push(html`<div class="filter-branch">${raw(branch)}</div>`);
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

        rows.push(html`<div class="filter-branch">${raw(branch)}</div>`);
    }

    els.deviceLicenseFilter.innerHTML = rows.length
        ? rows.join("")
        : "<div class=\"small text-secondary px-1 py-2\">Não há licenças para mostrar.</div>";
}

function renderDeviceFilterControls() {
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

function handleDeviceListLimitChange() {
    const nextLimit = parseInt(els.deviceListLimit.value || "20", 10) || 20;
    if (state.deviceListPageSize === nextLimit) {
        return;
    }
    state.deviceListPageSize = nextLimit;
    resetDeviceListPage();
    void loadSummary();
}

function handleDeviceListSearchInput() {
    state.deviceSearchQuery = els.deviceListSearch.value.trim();
    resetDeviceListPage();
    clearTimeout(deviceSearchTimer);
    deviceSearchTimer = setTimeout(() => {
        void loadSummary();
    }, 250);
}

function handleDevicePaginationClick(event) {
    const nextPage = resolvePaginationPage(
        event,
        state.summary.devicePagination,
        "devicePage",
    );
    if (nextPage === null) return;
    setDeviceListPage(nextPage);
    void loadSummary();
}

async function selectDevice(imei) {
    selectImei(imei);
    saveSelectedDeviceToStorage();
    const loaded = await loadDevice(imei);
    if (loaded) {
        ui.deviceSelectorModal?.hide();
    }
}

async function loadDevice(imei) {
    const detail = await apiGetDevice(imei);
    if (detail?.error) {
        if (state.selectedImei === imei) {
            disconnectDeviceStream();
            clearSelection();
            clearSelectedDeviceFromStorage();
        }
        renderSelectionDetail();
        return false;
    }
    disconnectDeviceStream();
    setSelectedDetail(detail);
    resetDetailFiltersDraft();
    // Aqui porque é o único sítio por onde entra um dispositivo novo; nos redesenhos
    // seguintes a cache já está quente.
    await ensureCapabilityCatalog(detail.model?.deviceType || "watch");
    renderSelectionDetail();
    connectDeviceStream(imei);
    return true;
}

export {
    ensureDeviceTypeSuppliersModelsLoaded,
    ensureProtocolsLoaded,
    handleDeviceListLimitChange,
    handleDeviceListSearchInput,
    handleDevicePaginationClick,
    isDeviceSelectorOpen,
    loadDevice,
    loadSummary,
    normalizeFilterValue,
    openDeviceSelector,
    renderDeviceSelector,
    selectDevice,
};
