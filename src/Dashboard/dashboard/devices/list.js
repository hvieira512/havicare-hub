import {
    getDevice as apiGetDevice,
    getDevices as apiGetDevices,
    getProtocols as apiGetProtocols,
} from "../api/index.js";
import {getDeviceTypeSuppliersModels as apiGetDeviceTypeSuppliersModels} from "../api/models.js";
import {ensureCapabilityCatalog} from "../capability-catalog.js";
import {ensureLicensesLoaded} from "../licenses.js";
import {state, clearSelection, selectImei} from "../state.js";
import {esc} from "../format.js";
import {
    deviceLicenseHtml,
    emptyPanel,
    onlineBadge,
    renderDeviceTypeTiles,
} from "../widgets.js";
import {renderPagination, resolvePaginationPage} from "../pagination.js";
import {
    companyLabel,
    deviceTypeLabel,
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
import {connectDeviceStream, disconnectDeviceStream} from "./stream.js";

/**
 * A coluna da esquerda: a lista de dispositivos, a sua paginação e busca, o modal de
 * escolher dispositivo, e o carregamento do que a lista precisa (modelos, fornecedores,
 * licenças, protocolos).
 *
 * Escolher uma linha é o que liga esta coluna à da direita, e por isso é daqui que se
 * chama o `detail.js`.
 */
let els;
let ui;
let deviceSearchTimer = null;
// Quantas linhas de esqueleto no máximo: a moldura mais alta (46rem) leva doze cartões, e
// acima disso seriam linhas cortadas a custo zero de informação.
const SKELETON_MAX_ROWS = 12;
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
    const {online} = state.deviceFilters;
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
            license: {companies: [], none: 0},
        },
        deviceTotals: devicesResponse.summary || {total: 0, online: 0},
    };
    state.deviceListPageSize =
        state.summary.devicePagination.limit || state.deviceListPageSize;
    state.deviceListPage = state.summary.devicePagination.page || 1;
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
    state.deviceTypeSuppliersModels = flattenDeviceTypeSuppliersModels(groups);
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
 * O modal abre primeiro e enche-se quando a resposta chega: com o `show()` depois do
 * `await`, um `/api/devices` lento deixa o botão sem resposta e ainda clicável.
 *
 * O esqueleto só aparece quando não há nada para mostrar. Numa segunda abertura a lista
 * anterior fica à vista, marcada como ocupada, que diz mais do que caixas vazias.
 */
async function openDeviceSelector() {
    ui.deviceSelectorModal?.show();
    if (!els.deviceList.childElementCount) {
        renderDeviceSelectorSkeleton();
    }
    await loadSummary();
}

/**
 * O esqueleto da lista e dos filtros. Cada linha é o cartão a sério -- as mesmas classes,
 * logo a mesma altura -- com barras no lugar do texto, para a lista não saltar quando os
 * dados chegam. São tantas quantas a página pode trazer, e a caixa corta as que não cabem.
 *
 * A `placeholder-wave` fica no contentor e não em cada linha, para ser uma passagem só
 * sobre a lista toda.
 */
function renderDeviceSelectorSkeleton() {
    const repeat = (count, markup) => Array.from({length: count}, () => markup).join("");
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

    els.deviceList.innerHTML = `
        <div class="device-card-skeleton-list placeholder-wave">
        ${repeat(Math.min(state.deviceListPageSize, SKELETON_MAX_ROWS), row)}
        </div>`;

    for (const el of [els.deviceSupplierFilter, els.deviceModelFilter, els.deviceLicenseFilter]) {
        el.innerHTML = `
            <div class="placeholder-wave">
            ${repeat(
                3,
                `<div class="filter-option" aria-hidden="true">
                    <span class="filter-option-box"></span>
                    <span class="placeholder col-6"></span>
                </div>`,
            )}
            </div>`;
    }
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
    renderDeviceFilterControls();
    renderDeviceSelectorSummary();

    els.deviceList.innerHTML = state.summary.devices.length
        ? state.summary.devices.map(renderDeviceCard).join("")
        : emptyPanel("Não há dispositivos para o filtro selecionado.");
    renderDevicePagination(state.summary.devicePagination);
}

function renderDeviceSelectorSummary() {
    if (!els.deviceSelectorSummary) return;
    const {total, online} = state.summary.deviceTotals || {total: 0, online: 0};
    els.deviceSelectorSummary.textContent = total
        ? `${total} ${total === 1 ? "dispositivo" : "dispositivos"} · ${online} ligado${online === 1 ? "" : "s"}`
        : "";
}

/**
 * Um dispositivo por linha, e a linha toda. Quem abre este modal quer reconhecer *um*
 * dispositivo, e reconhece-o pela foto, pelo estado e pelo IMEI -- esses três ficam à
 * esquerda, e a atribuição em campos de largura fixa à direita, na mesma abcissa.
 */
function renderDeviceCard(device) {
    const selected = state.selectedImei === device.imei;
    const image = device.image
        ? `<img src="${esc(device.image)}" alt="${esc(device.model || device.imei)}">`
        : '<i class="fa-solid fa-microchip"></i>';
    const meta = [
        deviceTypeLabel(normalizeDeviceType(device.deviceType)),
        [device.supplier, device.model].filter(Boolean).join(" "),
    ]
        .filter(Boolean)
        .join(" · ");

    return `
        <button type="button" class="device-card${selected ? " selected" : ""}${device.online ? "" : " offline"}"
            data-imei="${esc(device.imei)}" data-action="select"${selected ? ' aria-current="true"' : ""}>
        <span class="device-card-thumb">${image}</span>
        ${onlineBadge(device.online)}
        <span class="device-card-identity">
            <span class="min-width-0">
                <span class="device-card-imei d-block text-truncate">${esc(device.imei)}</span>
                <span class="device-card-meta d-block text-truncate">${esc(meta)}</span>
            </span>
        </span>
        <span class="device-card-fields">
            <span class="device-card-field">
                <span class="device-card-field-label">Licença</span>
                ${deviceLicenseHtml(device, "device-card-field-value")}
            </span>
            <span class="device-card-field">
                <span class="device-card-field-label">SIM</span>
                <span class="device-card-field-value${device.simNumber ? " tabular-nums" : " empty"}">${esc(device.simNumber || "—")}</span>
            </span>
        </span>
        </button>`;
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

/** Uma lista de opções de escolha múltipla, com a contagem de cada uma. */
function renderFilterOptionList(rootEl, key, options, labelForValue, search = "") {
    const selected = state.deviceFilters[key];
    const needle = search.trim().toLowerCase();
    // A ordem é a que vem do servidor e não muda ao marcar: com o que está marcado a subir
    // ao topo, a opção seguinte deslocava-se debaixo do cursor entre dois cliques.
    const visible = options.filter((option) => {
        if (needle === "") return true;
        return (
            String(option.value).toLowerCase().includes(needle) ||
            String(labelForValue(option.value)).toLowerCase().includes(needle)
        );
    });

    rootEl.innerHTML = visible.length
        ? visible
              .map((option) => {
                  const value = String(option.value);
                  return filterOptionMarkup({
                      key,
                      value,
                      label: labelForValue(value),
                      count: option.count,
                      selected: selected.includes(value),
                  });
              })
              .join("")
        : `<div class="small text-secondary px-1 py-2">Nada corresponde à procura.</div>`;
}

function filterOptionMarkup({key, value, label, count, selected, partial = false, nested = false}) {
    const classes = [
        "filter-option",
        nested ? "filter-option-nested" : "",
        selected ? "selected" : "",
        partial ? "partial" : "",
    ]
        .filter(Boolean)
        .join(" ");

    return `
        <button type="button" class="${classes}" data-action="toggleDeviceFilter"
            data-filter-key="${esc(key)}" data-filter-value="${esc(value)}" aria-pressed="${selected ? "true" : "false"}">
        <span class="filter-option-box"><i class="fa-solid ${partial && !selected ? "fa-minus" : "fa-check"}"></i></span>
        <span class="filter-option-name">${esc(label)}</span>
        <span class="filter-option-count count-number">${esc(count)}</span>
        </button>`;
}

/**
 * A árvore de empresas e licenças. Marcar a empresa marca-a toda; marcar algumas licenças
 * deixa-a no traço do meio, que diz "esta empresa, mas não inteira".
 *
 * "Sem licença" é a primeira e é folha: é a única que não pertence a empresa nenhuma, e no
 * fim ficaria atrás de uma lista que pode crescer.
 */
function renderDeviceLicenseFilter() {
    const tree = state.summary.deviceFilterCounts?.license || {companies: [], none: 0};
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

        rows.push(
            `<div class="filter-branch">${licenses
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
                .join("")}</div>`,
        );
    }

    els.deviceLicenseFilter.innerHTML = rows.length
        ? rows.join("")
        : '<div class="small text-secondary px-1 py-2">Não há licenças para mostrar.</div>';
}

function renderDeviceFilterControls() {
    const counts = state.summary.deviceFilterCounts || {
        deviceType: [],
        supplier: [],
        model: [],
        license: {companies: [], none: 0},
    };

    renderDeviceTypeFilter();
    renderFilterOptionList(
        els.deviceSupplierFilter,
        "supplier",
        counts.supplier || [],
        (value) => value,
    );
    renderFilterOptionList(
        els.deviceModelFilter,
        "model",
        counts.model || [],
        (value) => modelDisplayName("", value),
        state.deviceModelFilterSearch,
    );
    renderDeviceLicenseFilter();

    if (els.deviceModelFilterSearch) {
        els.deviceModelFilterSearch.value = state.deviceModelFilterSearch;
    }
    for (const input of document.querySelectorAll('input[name="deviceOnlineFilter"]')) {
        const {online} = state.deviceFilters;
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
        supplier: state.deviceFilters.supplier.length,
        model: state.deviceFilters.model.length,
        license: state.deviceFilters.license.length,
    };
    const counterEls = {
        deviceType: els.deviceTypeFilterCount,
        supplier: els.deviceSupplierFilterCount,
        model: els.deviceModelFilterCount,
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
    state.deviceListPage = 1;
    void loadSummary();
}

function handleDeviceListSearchInput() {
    state.deviceSearchQuery = els.deviceListSearch.value.trim();
    state.deviceListPage = 1;
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
    state.deviceListPage = nextPage;
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
    state.selectedDetail = detail;
    state.selectedDetail.recent = null;
    state.detailFiltersDraft = { ...state.detailFilters };
    // Os nomes das capacidades vêm do catálogo deste tipo de dispositivo, e carregam-se
    // aqui porque este é o único sítio por onde entra um dispositivo novo: nos redesenhos
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
