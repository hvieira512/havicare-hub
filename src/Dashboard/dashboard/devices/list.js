import {
    getDevice as apiGetDevice,
    getDevices as apiGetDevices,
    getProtocols as apiGetProtocols,
} from "../api/index.js";
import { getDeviceTypeSuppliersModels as apiGetDeviceTypeSuppliersModels } from "../api/index.js";
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
import { html } from "../html.js";
import { emptyPanel } from "../components/empty-panel.js";
import { deviceCard, deviceCardSkeletonList } from "./device-card.js";
import { renderPagination, resolvePaginationPage } from "../pagination.js";
import { normalizeDeviceType } from "../domain.js";
import {
    initListFilters,
    renderDeviceFilterControls,
    renderDeviceFilterSkeleton,
} from "./list-filters.js";
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
    // Quem volta a pedir a lista entra por aqui e não por um import de volta: os filtros
    // mexem no estado, a lista é que a vai buscar, e o grafo de módulos fica sem ciclos.
    initListFilters({ els, onChange: loadSummary });
    initDeviceDetailView(context);
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

/**
 * Só a última listagem pedida pode escrever a lista e o paginador. A pesquisa espera 250 ms
 * antes de pedir, mas um clique na paginação ou num filtro não espera por nada, e duas
 * respostas trocadas deixavam no ecrã a página que não foi pedida.
 */
let summaryGeneration = 0;

async function fetchSummary() {
    summaryGeneration += 1;
    const generation = summaryGeneration;
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
    if (generation !== summaryGeneration) {
        return;
    }
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
    // A pesquisa não sobrevive ao fecho. Reabrir logo a seguir a escolher um dispositivo
    // mostrava esse e mais nenhum, com as pastilhas de tipo todas a dizer «nenhum» -- lê-se
    // como se a frota tivesse desaparecido. Os filtros ficam: esses são escolha guardada.
    clearTimeout(deviceSearchTimer);
    state.deviceSearchQuery = "";
    if (els.deviceListSearch) els.deviceListSearch.value = "";
    resetDeviceListPage();
    if (!els.deviceList.childElementCount) {
        renderDeviceSelectorSkeleton();
    }
    await loadSummary();
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
    await loadDevice(imei);
    // O que fecha o selector é a escolha ter vingado, e não esta leitura ter sido a última a
    // escrever: o `loadDevice` devolve falso tanto quando falha como quando outra leitura o
    // ultrapassa, e a listagem relê o dispositivo escolhido assim que a resposta dela chega.
    // Uma falha tira a selecção, e aí o selector fica aberto para se escolher outro.
    if (state.selectedImei === imei) {
        ui.deviceSelectorModal?.hide();
    }
}

/**
 * Só a última leitura pedida pode escrever no ecrã. Duas respostas trocadas punham o detalhe
 * de um dispositivo por baixo da identidade de outro, e o `refreshSelectedDevice` seguinte
 * mantinha a telemetria errada lá. É o contador do `stream.js`, com a mesma razão de ser.
 */
let deviceLoadGeneration = 0;

async function loadDevice(imei) {
    deviceLoadGeneration += 1;
    const generation = deviceLoadGeneration;
    const detail = await apiGetDevice(imei);
    if (generation !== deviceLoadGeneration) {
        return false;
    }
    if (detail?.error) {
        if (state.selectedImei === imei) {
            disconnectDeviceStream();
            clearSelection();
            clearSelectedDeviceFromStorage();
        }
        renderSelectionDetail();
        return false;
    }
    // Aqui porque é o único sítio por onde entra um dispositivo novo; nos redesenhos
    // seguintes a cache já está quente.
    await ensureCapabilityCatalog(detail.model?.deviceType || "watch");
    if (generation !== deviceLoadGeneration) {
        return false;
    }
    disconnectDeviceStream();
    setSelectedDetail(detail);
    resetDetailFiltersDraft();
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
    openDeviceSelector,
    renderDeviceSelector,
    selectDevice,
};
