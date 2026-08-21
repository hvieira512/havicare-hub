import {
    getDevice as apiGetDevice,
    getDevices as apiGetDevices,
    getLicenses as apiGetLicenses,
    getModels as apiGetModels,
    getProtocols as apiGetProtocols,
    getSuppliers as apiGetSuppliers,
    requestFeature as apiRequestFeature,
} from "../api/index.js";
import {getDeviceTypeSuppliersModels as apiGetDeviceTypeSuppliersModels} from "../api/models.js";
import {state, clearSelection, selectImei, setTelemetryPage} from "../state.js";
import {
    commandLabel,
    displayValue,
    esc,
    eventTime,
    featureLabel,
    fieldLabel,
    rowPayload,
    when,
} from "../format.js";
import {
    emptyPanel,
    renderRequestCardShell,
    statusBadge,
    uplinkCardContent,
} from "../renderers.js";
import {renderPagination, resolvePaginationPage} from "../pagination.js";
import {
    capabilitiesForSupplier,
    capabilitiesGroupedBySection,
    capabilityCatalogEntryByKey,
    capabilityLabelByKey,
    capabilitySectionLabel,
    companyLabel,
    deriveFourPTouchDeviceId,
    deviceTypeLabel,
    deviceTypeOptions,
    isFourPTouchSelection,
    findModelInfo,
    flattenedCapabilityKeys,
    humanizeCapabilityKey,
    licenseDisplayLabel,
    licenseLabel,
    deviceTypeFields,
    linksToGateway,
    modelCommercialName,
    modelDeviceType,
    modelDisplayLabel,
    modelDisplayName,
    modelInternalName,
    modelsForSupplier,
    modelsForSupplierAndType,
    normalizeDeviceType,
    normalizeLicenseId,
    supplierProtocol,
    suppliersForDeviceType,
    suppliersFromModels,
    usesMacAddress,
} from "../domain.js";
import {
    initDeviceDetailView,
    clearSelectedDeviceFromStorage,
    renderSelection as renderSelectionDetail,
    saveSelectedDeviceToStorage,
} from "./detail-view.js";
import {connectDeviceStream, disconnectDeviceStream} from "./stream.js";

let els;
let ui;
let services;
let deviceSearchTimer = null;
export function initDeviceListDetail(context) {
    els = context.els;
    ui = context.ui;
    services = context.services;
    initDeviceDetailView(context);
}

function normalizeFilterValue(value) {
    if (!value || value === "undefined" || value === "all") return null;
    return String(value);
}

function apiRoleLabel(role) {
    return role === "hub_admin" ? "Admin Hub" : "Cliente por licença";
}

async function loadSummary() {
    const [devicesResponse] = await Promise.all([
        apiGetDevices({
            page: state.deviceListPage,
            limit: state.deviceListPageSize,
            deviceType: state.deviceFilters.deviceType,
            licenseId: state.deviceFilters.licenseId,
            company: state.deviceFilters.company,
            supplier: state.deviceFilters.supplier,
            model: state.deviceFilters.model,
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

async function ensureModelsLoaded(force = false) {
    if (
        !force &&
        Array.isArray(state.summary.models) &&
        state.summary.models.length > 0
    ) {
        return state.summary.models;
    }

    const modelsResponse = await apiGetModels({ limit: 500 });
    state.summary.models = modelsResponse.data || [];
    return state.summary.models;
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

async function ensureLicensesLoaded(force = false) {
    if (
        !force &&
        Array.isArray(state.settingsModal.licenses) &&
        state.settingsModal.licenses.length > 0
    ) {
        return state.settingsModal.licenses;
    }

    const licensesResponse = await apiGetLicenses({ limit: 500 });
    state.settingsModal.licenses = licensesResponse.data || [];
    return state.settingsModal.licenses;
}

async function ensureSuppliersLoaded(force = false) {
    if (
        !force &&
        Array.isArray(state.modelModalSuppliers) &&
        state.modelModalSuppliers.length > 0
    ) {
        return state.modelModalSuppliers;
    }

    const suppliersResponse = await apiGetSuppliers({ limit: 500 });
    state.modelModalSuppliers = suppliersResponse.data || [];
    return state.modelModalSuppliers;
}

async function ensureProtocolsLoaded(force = false) {
    if (!force && Array.isArray(state.protocols) && state.protocols.length > 0) {
        return state.protocols;
    }

    const protocolsResponse = await apiGetProtocols();
    state.protocols = protocolsResponse?.error ? [] : protocolsResponse.data || [];
    return state.protocols;
}

async function openDeviceSelector() {
    await loadSummary();
    ui.deviceSelectorModal?.show();
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

    const tableMarkup = state.summary.devices.length
        ? `
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th></th>
                        <th>Estado</th>
                        <th>IMEI</th>
                        <th>Tipo</th>
                        <th>Empresa</th>
                        <th>Licença</th>
                        <th>SIM</th>
                        <th>Fornecedor</th>
                        <th>Modelo</th>
                    </tr>
                </thead>
                <tbody>
                    ${state.summary.devices
                        .map((device) => {
                            const isSelected =
                                state.selectedImei === device.imei;
                            const imageMarkup = device.image
                                ? `<img src="${esc(device.image)}" class="object-fit-contain" alt="${esc(device.model || device.imei)}" style="width:40px;height:40px;">`
                                : '<i class="fa-solid fa-microchip fa-xl text-secondary" style="width:40px"></i>';
                            return `
                            <tr${isSelected ? ' class="table-primary"' : ""} data-imei="${esc(device.imei)}" data-action="select" role="button" tabindex="0">
                                <td style="width:52px">${imageMarkup}</td>
                                <td>
                                    <span class="d-inline-flex align-items-center gap-2 small">
                                        <span class="rounded-circle ${device.online ? "bg-success" : "bg-danger"} d-inline-block flex-shrink-0" style="width:.55rem;height:.55rem;"></span>
                                        ${device.online ? "Ligado" : "Desligado"}
                                    </span>
                                </td>
                                <td class="fw-semibold text-break">${esc(device.imei)}</td>
                                <td>${esc(deviceTypeLabel(normalizeDeviceType(device.deviceType)))}</td>
                                <td>${esc(companyLabel(device.company))}</td>
                                <td>${esc(licenseDisplayLabel(device.licenseId))}</td>
                                <td class="text-break">${esc(device.simNumber || "-")}</td>
                                <td>${esc(device.supplier || "-")}</td>
                                <td>${esc(device.model || "-")}</td>
                            </tr>`;
                        })
                        .join("")}
                </tbody>
            </table>
        </div>
    `
        : emptyPanel("Não há dispositivos para o filtro selecionado.");

    els.deviceList.innerHTML = tableMarkup;
    renderDevicePagination(state.summary.devicePagination);
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

function renderSelectOptions(select, options, selectedValue, labelForValue) {
    const normalizedSelectedValue = normalizeFilterValue(selectedValue);
    const html = [
        '<option value="all">Todos</option>',
        ...options.map(
            (option) =>
                `<option value="${esc(option)}"${option === normalizedSelectedValue ? " selected" : ""}>${esc(labelForValue(option))}</option>`,
        ),
    ];
    select.innerHTML = html.join("");
    select.value = options.includes(normalizedSelectedValue)
        ? normalizedSelectedValue
        : "all";
}

function renderDeviceFilterControls() {
    const options = state.summary.deviceFiltersAvailable || {
        deviceType: [],
        licenseId: [],
        company: [],
        supplier: [],
        model: [],
    };
    renderSelectOptions(
        els.deviceTypeFilter,
        options.deviceType || [],
        state.deviceFilters.deviceType,
        (value) => deviceTypeLabel(value),
    );
    renderSelectOptions(
        els.deviceLicenseFilter,
        options.licenseId || [],
        state.deviceFilters.licenseId,
        (value) => licenseLabel(value),
    );
    renderSelectOptions(
        els.deviceCompanyFilter,
        options.company || [],
        state.deviceFilters.company,
        (value) => companyLabel(value),
    );
    renderSelectOptions(
        els.deviceSupplierFilter,
        options.supplier || [],
        state.deviceFilters.supplier,
        (value) => value,
    );
    renderSelectOptions(
        els.deviceModelFilter,
        options.model || [],
        state.deviceFilters.model,
        (value) => modelDisplayName("", value),
    );
    renderAppliedDeviceFilters();
}

function renderAppliedDeviceFilters() {
    const labels = [];

    if (state.deviceFilters.deviceType) {
        labels.push({
            key: "deviceType",
            label: `Tipo: ${deviceTypeLabel(state.deviceFilters.deviceType)}`,
        });
    }
    if (state.deviceFilters.licenseId) {
        labels.push({
            key: "licenseId",
            label: `Licença: ${licenseLabel(state.deviceFilters.licenseId)}`,
        });
    }
    if (state.deviceFilters.company) {
        labels.push({
            key: "company",
            label: `Empresa: ${companyLabel(state.deviceFilters.company)}`,
        });
    }
    if (state.deviceFilters.supplier) {
        labels.push({
            key: "supplier",
            label: `Fornecedor: ${state.deviceFilters.supplier}`,
        });
    }
    if (state.deviceFilters.model) {
        labels.push({
            key: "model",
            label: `Modelo: ${modelDisplayName("", state.deviceFilters.model)}`,
        });
    }

    els.deviceActiveFilters.innerHTML = labels.length
        ? labels
              .map(
                  (item) => `
            <span class="badge text-bg-secondary d-inline-flex align-items-center gap-2">
                <span>${esc(item.label)}</span>
                <button type="button" class="btn btn-sm p-0 border-0 text-white" data-action="removeDeviceFilter" data-filter-key="${esc(item.key)}" aria-label="Remover filtro">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </span>
        `,
              )
              .join("")
        : '<span class="small text-secondary">Sem filtros ativos</span>';
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
    renderSelectionDetail();
    connectDeviceStream(imei);
    return true;
}

export {
    apiRoleLabel,
    capabilitiesForSupplier,
    capabilitiesGroupedBySection,
    capabilityCatalogEntryByKey,
    capabilityLabelByKey,
    capabilitySectionLabel,
    clearSelection,
    companyLabel,
    deriveFourPTouchDeviceId,
    deviceTypeLabel,
    deviceTypeOptions,
    ensureLicensesLoaded,
    ensureDeviceTypeSuppliersModelsLoaded,
    ensureModelsLoaded,
    ensureProtocolsLoaded,
    ensureSuppliersLoaded,
    findModelInfo,
    flattenedCapabilityKeys,
    handleDeviceListLimitChange,
    handleDeviceListSearchInput,
    handleDevicePaginationClick,
    humanizeCapabilityKey,
    isDeviceSelectorOpen,
    isFourPTouchSelection,
    licenseDisplayLabel,
    licenseLabel,
    deviceTypeFields,
    linksToGateway,
    loadDevice,
    loadSummary,
    modelCommercialName,
    modelDeviceType,
    modelDisplayLabel,
    modelDisplayName,
    modelInternalName,
    modelsForSupplier,
    modelsForSupplierAndType,
    normalizeDeviceType,
    normalizeFilterValue,
    normalizeLicenseId,
    openDeviceSelector,
    renderAppliedDeviceFilters,
    renderDeviceFilterControls,
    renderDevicePagination,
    renderDeviceSelector,
    selectDevice,
    supplierProtocol,
    suppliersForDeviceType,
    suppliersFromModels,
    usesMacAddress,
};
