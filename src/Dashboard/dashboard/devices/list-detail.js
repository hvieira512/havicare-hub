import {
    getDevice as apiGetDevice,
    getDevices as apiGetDevices,
    getLicenses as apiGetLicenses,
    getModels as apiGetModels,
    getSuppliers as apiGetSuppliers,
    requestFeature as apiRequestFeature,
} from "../api/index.js";
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
    commandFeature,
    emptyPanel,
    modelImageHtml,
    renderRequestCardShell,
    statusBadge,
    uplinkCardContent,
} from "../renderers.js";
import {renderPagination, resolvePaginationPage} from "../shared/pagination.js";
import {
    clearStorageKey,
    saveJsonStorage,
    saveTextStorage,
} from "../core/storage.js";
import {connectDeviceStream, disconnectDeviceStream} from "./stream.js";

let els;
let ui;
let services;
let deviceSearchTimer = null;
let connectionChartRoot = null;

export function initDeviceListDetail(context) {
    els = context.els;
    ui = context.ui;
    services = context.services;
}

const deviceTypeOptions = [
    { value: "watch", label: "Relógio" },
    { value: "ncs", label: "NCS" },
    { value: "radar", label: "Radar" },
];

function deviceTypeLabel(deviceType) {
    return (
        deviceTypeOptions.find((option) => option.value === deviceType)
            ?.label || deviceType
    );
}

function suppliersForDeviceType(deviceType, models = state.summary.models) {
    const allSuppliers = suppliersFromModels(models);
    if (!deviceType || deviceType === "watch") {
        return allSuppliers;
    }
    const deviceTypeSuppliers = (models || [])
        .filter(
            (model) =>
                normalizeDeviceType(
                    model.device_type || model.deviceType || "watch",
                ) === deviceType,
        )
        .map((model) => model.supplier)
        .filter(Boolean);
    return allSuppliers.filter((name) => deviceTypeSuppliers.includes(name));
}

function normalizeDeviceType(deviceType) {
    return deviceTypeOptions.some((option) => option.value === deviceType)
        ? deviceType
        : "watch";
}

function normalizeFilterValue(value) {
    if (!value || value === "undefined" || value === "all") return null;
    return String(value);
}

function normalizeLicenseId(licenseId) {
    const value = String(licenseId ?? "0").trim();
    return value === "" ? "0" : value;
}

function licenseLabel(licenseId) {
    return normalizeLicenseId(licenseId) === "0"
        ? "Sem Licença"
        : normalizeLicenseId(licenseId);
}

function companyLabel(company) {
    const value = String(company ?? "").trim();
    return value === "" || value === "null" ? "Sem empresa" : value;
}

function licenseDisplayLabel(
    licenseId,
    licenses = state.settingsModal.licenses || [],
) {
    const normalized = normalizeLicenseId(licenseId);
    if (normalized === "0") {
        return "Sem Licença";
    }

    const match = (licenses || []).find(
        (item) =>
            String(item.license_id || item.licenseId || "") === normalized,
    );
    if (!match) {
        return normalized;
    }

    const name = String(match.name || "").trim();
    return name !== "" ? `${name} (${normalized})` : normalized;
}

function apiRoleLabel(role) {
    return role === "hub_admin" ? "Admin Hub" : "Cliente por licença";
}

function supplierProtocol(supplier, models = state.summary.models) {
    const existing = models.find(
        (model) => model.supplier === supplier && model.protocol,
    );
    return existing?.protocol || "";
}

function modelInternalName(model) {
    return String(
        model.internal_model || model.internalModel || model.model || "",
    );
}

function modelCommercialName(model) {
    return String(
        model.commercial_name ||
            model.commercialName ||
            model.internal_model ||
            model.internalModel ||
            model.model ||
            "",
    );
}

function modelDeviceType(model) {
    return normalizeDeviceType(
        model?.device_type || model?.deviceType || "watch",
    );
}

function suppliersFromModels(models = state.summary.models) {
    return [...new Set(models.map((model) => model.supplier).filter(Boolean))];
}

function modelsForSupplier(supplier, models = state.summary.models) {
    return models.filter((model) => model.supplier === supplier);
}

function findModelInfo(supplier, model, models = state.summary.models) {
    return (
        models.find(
            (entry) =>
                entry.supplier === supplier &&
                modelInternalName(entry) === model,
        ) || null
    );
}

function modelDisplayName(supplier, model, models = state.summary.models) {
    const info = findModelInfo(supplier, model, models);
    return info ? modelCommercialName(info) : model;
}

function modelsForSupplierAndType(
    supplier,
    deviceType,
    models = state.summary.models,
) {
    return modelsForSupplier(supplier, models).filter(
        (model) => modelDeviceType(model) === normalizeDeviceType(deviceType),
    );
}

function modelDisplayLabel(model) {
    const commercialName = modelCommercialName(model);
    const internalName = modelInternalName(model);
    return commercialName === internalName
        ? commercialName
        : `${commercialName} (${internalName})`;
}

function deriveFourPTouchDeviceId(imei) {
    const digits = String(imei || "").replace(/\D+/g, "");
    if (digits.length === 15) return digits.slice(4, 14);
    if (digits.length === 10) return digits;
    if (digits.length > 10) return digits.slice(-10);
    return digits;
}

function isFourPTouchSelection(
    supplier = els.deviceForm?.dataset?.supplier || "",
    model = els.deviceForm?.dataset?.model || "",
) {
    return (
        supplierProtocol(supplier, state.summary.models) === "four-p-touch" ||
        supplier === "4P Touch"
    );
}

function capabilitiesForSupplier(supplier, models = state.summary.models) {
    const entry = models.find(
        (model) =>
            model.supplier === supplier &&
            model?.capabilities &&
            typeof model.capabilities === "object",
    );
    return flattenedCapabilityKeys(entry?.capabilities || {});
}

function flattenedCapabilityKeys(capabilities) {
    const enabled = [];
    for (const entries of Object.values(capabilities || {})) {
        if (!entries || typeof entries !== "object") {
            continue;
        }
        for (const [key, supported] of Object.entries(entries)) {
            if (supported) {
                enabled.push(key);
            }
        }
    }
    return enabled;
}

function capabilityLabelByKey(key) {
    return (
        capabilityCatalogEntryByKey(key)?.label || humanizeCapabilityKey(key)
    );
}

function capabilitySectionLabel(section) {
    const label = state.settingsModal.capabilityCatalog.find(
        (entry) => entry.section === section,
    )?.sectionLabel;
    if (label) {
        return label;
    }

    return humanizeCapabilityKey(section);
}

function capabilityCatalogEntryByKey(
    key,
    catalog = state.settingsModal.capabilityCatalog,
) {
    return (catalog || []).find((entry) => entry.key === key) || null;
}

function capabilitiesGroupedBySection(
    catalog = state.settingsModal.capabilityCatalog,
) {
    const grouped = new Map();
    for (const entry of catalog || []) {
        const section = String(entry.section || "").trim();
        if (!section) {
            continue;
        }
        if (!grouped.has(section)) {
            grouped.set(section, []);
        }
        grouped.get(section).push(entry);
    }

    return [...grouped.entries()].map(([section, entries]) => ({
        section,
        label: capabilitySectionLabel(section),
        entries,
    }));
}

function humanizeCapabilityKey(value) {
    return String(value || "")
        .replace(/_/g, " ")
        .replace(/\b\w/g, (char) => char.toUpperCase());
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
        ensureModelsLoaded(),
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
        renderSelection();
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

    const modelLookup = {};
    for (const model of state.summary.models) {
        modelLookup[`${model.supplier}:${modelInternalName(model)}`] = model;
    }

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
                            const modelInfo =
                                modelLookup[
                                    `${device.supplier}:${device.model}`
                                ];
                            const isSelected =
                                state.selectedImei === device.imei;
                            return `
                            <tr${isSelected ? ' class="table-primary"' : ""} data-imei="${esc(device.imei)}" data-action="select" role="button" tabindex="0">
                                <td style="width:52px">${modelImageHtml(modelInfo)}</td>
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
                                <td>${esc(modelInfo ? modelCommercialName(modelInfo) : device.model || "-")}</td>
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
        renderSelection();
        return false;
    }
    disconnectDeviceStream();
    state.selectedDetail = detail;
    state.selectedDetail.recent = null;
    await ensureModelsLoaded();
    renderSelection();
    connectDeviceStream(imei);
    return true;
}

function renderSelection() {
    els.deviceSelectionEmptyState.classList.toggle(
        "d-none",
        !!state.selectedDetail,
    );
    els.selectedDevicePanel.classList.toggle("d-none", !state.selectedDetail);
    els.detailEmptyState.classList.toggle("d-none", !!state.selectedDetail);
    els.deviceDetail.classList.toggle("d-none", !state.selectedDetail);
    if (!state.selectedDetail) {
        if (connectionChartRoot) {
            connectionChartRoot.dispose();
            connectionChartRoot = null;
        }
        els.requestCardCount.textContent = "";
        els.requestGrid.innerHTML = "";
        return;
    }

    const device = state.selectedDetail.device;
    const deviceModel = state.selectedDetail.model;
    renderSelectedDeviceSummary(device, deviceModel);

    if (!state.detailFilters.from) {
        const sevenDaysAgo = new Date();
        sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);
        state.detailFilters.from = sevenDaysAgo.toISOString().slice(0, 16);
    }

    populateDetailFilterTypes();
    syncDetailFilterControls();

    const allItems = allDetailItems();
    const filtered = filterDetailItems(allItems);
    const ncsEvents = filtered
        .filter(
            (item) =>
                item._source === "event" && item.payload?.type === "ncs.event",
        )
        .map((item) => item.raw);
    const telemetry = filtered
        .filter((item) => item._source === "telemetry")
        .map((item) => item.raw);
    const commands = filtered
        .filter((item) => item._source === "command")
        .map((item) => item.raw);
    const connectionEvents = filtered
        .filter((item) => item._source === "connection")
        .map((item) => item.raw);

    renderTelemetryList([...telemetry, ...ncsEvents]);
    renderRequestCards(
        telemetryRequestCards(
            state.selectedDetail?.capabilities?.telemetry || {},
        ),
        telemetry,
    );
    renderDownlinkRequests(commands);
    renderConnectionTimeline(connectionEvents);
}

const TELEMETRY_REQUEST_GROUPS = [
    {
        key: "telemetry",
        label: "Telemetria",
    },
    {
        key: "system",
        label: "Informação do sistema",
    },
];

const TELEMETRY_REQUEST_SYSTEM_FEATURES = new Set([
    "firmware_version",
    "device_status",
]);

function telemetryRequestCards(telemetryCapabilities = {}) {
    const cards = Object.entries(telemetryCapabilities || {})
        .filter(([, entry]) => entry?.supported && entry?.requestable !== false)
        .map(([feature, entry]) => ({
            id: feature,
            feature,
            requestable: !!entry?.requestable,
            group: TELEMETRY_REQUEST_SYSTEM_FEATURES.has(feature)
                ? "system"
                : "telemetry",
        }))
        .sort((a, b) => {
            if (a.group !== b.group) {
                return a.group === "telemetry" ? -1 : 1;
            }

            return String(featureLabel(a.feature || "")).localeCompare(
                String(featureLabel(b.feature || "")),
                "pt-PT",
            );
        });

    return TELEMETRY_REQUEST_GROUPS
        .map((group) => ({
            ...group,
            cards: cards.filter((card) => card.group === group.key),
        }))
        .filter((group) => group.cards.length);
}

function renderSelectedDeviceSummary(device, deviceModel) {
    const supplier = String(deviceModel?.supplier || "");
    const model = String(deviceModel?.internalModel || "");
    const modelInfo = findModelInfo(supplier, model);
    const facts = [
        {
            label: "Tipo",
            value: deviceTypeLabel(
                normalizeDeviceType(deviceModel?.deviceType || "watch"),
            ),
        },
        { label: "Licença", value: licenseLabel(device.licenseId) },
        { label: "Fornecedor", value: supplier || "-" },
        {
            label: "Modelo",
            value: modelInfo ? modelCommercialName(modelInfo) : model || "-",
        },
        {
            label: "Última ligação",
            value: when(device.lastSeenAt) || "Sem registo",
        },
    ];

    if (device.simNumber) {
        facts.push({ label: "SIM", value: String(device.simNumber) });
    }
    if (device.deviceId && String(device.deviceId) !== String(device.imei)) {
        facts.push({ label: "Device ID", value: String(device.deviceId) });
    }

    els.selectedDevicePreview.innerHTML = modelImageHtml(modelInfo);
    els.selectedDeviceTitle.innerHTML = `<span class="rounded-circle ${device.online ? "bg-success" : "bg-danger"} d-inline-block flex-shrink-0 me-2" style="width:.75rem;height:.75rem;"></span>${esc(device.imei)}`;
    els.selectedDeviceMeta.textContent = `${supplier || "Sem fornecedor"}${model ? ` · ${model}` : ""}`;
    els.selectedDeviceFacts.innerHTML = facts
        .map(
            (item) => `
        <div class="col-6">
            <dt>${esc(item.label)}</dt>
            <dd class="text-break">${esc(item.value)}</dd>
        </div>
    `,
        )
        .join("");
}

function allDetailItems() {
    const items = [];
    const recent = state.selectedDetail.recent || {};
    for (const row of recent.telemetry || []) {
        const payload = rowPayload(row);
        if (payload && !payload.debug)
            items.push({ _source: "telemetry", raw: row, payload });
    }
    for (const row of recent.events || []) {
        const payload = rowPayload(row);
        if (!payload) continue;
        if (payload.type === "ncs.event")
            items.push({ _source: "event", raw: row, payload });
        if (
            payload.type === "device.connected" ||
            payload.type === "device.disconnected"
        )
            items.push({ _source: "connection", raw: row, payload });
    }
    for (const row of recent.commands || []) {
        const payload = rowPayload(row);
        if (payload) items.push({ _source: "command", raw: row, payload });
    }
    return items;
}

function filterDetailItems(items) {
    const { from, to, type } = state.detailFilters;
    return items.filter((item) => {
        if (type !== "all" && type !== "") {
            const itemType = normalizeTelemetryFilterType(detailItemType(item));
            if (itemType !== type) return false;
        }
        if (from || to) {
            const time = itemTime(item);
            if (!time) return false;
            if (from && time < new Date(from).getTime()) return false;
            if (to && time > new Date(to).getTime()) return false;
        }
        return true;
    });
}

function detailItemType(item) {
    const p = item.payload;
    if (p.type === "ncs.event") return p.data?.event || "general_alert";
    if (p.type === "device.connected") return "device.connected";
    if (p.type === "device.disconnected") return "device.disconnected";
    if (p.nativeType) return p.nativeType;
    if (p.type && p.type !== "telemetry") return p.type;
    return "outros";
}

function normalizeTelemetryFilterType(type) {
    if (type === "blood_pressure_systolic" || type === "blood_pressure_diastolic") {
        return "blood_pressure";
    }

    return type;
}

function itemTime(item) {
    const p = item.payload;
    return Date.parse(p.occurredAt || p.recordedAt || p.requestedAt || "");
}

function populateDetailFilterTypes() {
    const select = els.detailFilterType;
    const currentValue = state.detailFilters.type;
    const telemetryCapabilities =
        state.selectedDetail?.capabilities?.telemetry &&
        typeof state.selectedDetail.capabilities.telemetry === "object"
            ? state.selectedDetail.capabilities.telemetry
            : {};
    const sorted = Object.entries(telemetryCapabilities)
        .filter(([, entry]) => entry?.supported)
        .map(([key]) => key)
        .sort();
    const signature = sorted.join("|");
    if (select.dataset.detailFilterTypesSignature !== signature) {
        const options = [
            '<option value="all">Todos</option>',
            ...sorted.map(
                (t) =>
                    `<option value="${esc(t)}">${esc(telemetryFilterLabel(t))}</option>`,
            ),
        ];
        if (currentValue && currentValue !== "all" && !sorted.includes(currentValue)) {
            options.push(
                `<option value="${esc(currentValue)}">${esc(telemetryFilterLabel(currentValue))}</option>`,
            );
        }
        select.innerHTML = options.join("");
        select.dataset.detailFilterTypesSignature = signature;
    }

    if (currentValue && currentValue !== "all") {
        const hasCurrentValue = Array.from(select.options).some(
            (option) => option.value === currentValue,
        );
        if (!hasCurrentValue) {
            select.insertAdjacentHTML(
                "beforeend",
                `<option value="${esc(currentValue)}">${esc(telemetryFilterLabel(currentValue))}</option>`,
            );
        }
        select.value = currentValue;
        return;
    }

    select.value = "all";
}

function telemetryFilterLabel(type) {
    return featureLabel(type) || type;
}

function syncDetailFilterControls() {
    els.detailFilterFrom.value = state.detailFilters.from;
    els.detailFilterTo.value = state.detailFilters.to;
    els.detailFilterType.value = state.detailFilters.type;
}

function applyDetailFilters() {
    state.detailFilters.from = els.detailFilterFrom.value;
    state.detailFilters.to = els.detailFilterTo.value;
    state.detailFilters.type = els.detailFilterType.value;
    state.telemetryPage = 1;
    renderSelection();
}

function clearDetailFilters() {
    state.detailFilters = { from: "", to: "", type: "all" };
    state.telemetryPage = 1;
    renderSelection();
}

function renderTelemetryList(telemetryRows) {
    const telemetry = telemetryRows
        .map(rowPayload)
        .filter((payload) => payload && !payload.debug)
        .sort((a, b) => eventTime(b) - eventTime(a));
    const totalPages = Math.max(
        1,
        Math.ceil(telemetry.length / state.telemetryPageSize),
    );
    setTelemetryPage(state.telemetryPage, totalPages);

    const start = (state.telemetryPage - 1) * state.telemetryPageSize;
    const pageRows = telemetry.slice(start, start + state.telemetryPageSize);

    els.telemetryCount.textContent = telemetry.length
        ? `${telemetry.length} eventos`
        : "";
    els.telemetryList.innerHTML = pageRows.length
        ? `<div class="list-group">${pageRows.map(renderTelemetryRow).join("")}</div>`
        : emptyPanel("Ainda não há eventos recebidos.");
    renderTelemetryPager(telemetry.length, totalPages);
}

function renderTelemetryPager(totalRows, totalPages) {
    const root = els.telemetryPager;
    const summaryEl = els.telemetryPagerSummary;
    const controlsEl = els.telemetryPagerControls;

    if (totalRows <= state.telemetryPageSize) {
        root.classList.add("d-none");
        summaryEl.textContent = "";
        controlsEl.innerHTML = "";
        return;
    }

    const currentPage = state.telemetryPage;
    const limit = state.telemetryPageSize;
    const pageStart = (currentPage - 1) * limit + 1;
    const pageEnd = Math.min(totalRows, currentPage * limit);
    root.classList.remove("d-none");
    summaryEl.textContent = `${pageStart}–${pageEnd} de ${totalRows}`;
    controlsEl.innerHTML = [
        `<button type="button" class="btn btn-outline-secondary btn-sm" data-action="telemetryPrev" ${currentPage <= 1 ? "disabled" : ""} aria-label="Página anterior"><i class="fa-solid fa-chevron-left"></i></button>`,
        ...Array.from({ length: totalPages }, (_, index) => {
            const page = index + 1;
            return `<button type="button" class="btn ${page === currentPage ? "btn-primary" : "btn-outline-secondary"} btn-sm" data-action="telemetryPageGo" data-page="${page}" ${page === currentPage ? 'aria-current="page"' : ""}>${page}</button>`;
        }),
        `<button type="button" class="btn btn-outline-secondary btn-sm" data-action="telemetryNext" ${currentPage >= totalPages ? "disabled" : ""} aria-label="Página seguinte"><i class="fa-solid fa-chevron-right"></i></button>`,
    ].join("");
}

function renderTelemetryRow(payload) {
    const type = payload?.type || "telemetry";
    const data =
        payload?.data && typeof payload.data === "object" ? payload.data : {};
    const card = uplinkCardContent(type, data);
    const details = telemetryDetails(data, payload);

    return `
        <div class="list-group-item">
        <div class="d-flex justify-content-between gap-3">
        <div class="min-width-0">
        <div class="fw-semibold"><i class="fa-solid ${esc(card.icon)} text-secondary me-2"></i>${esc(featureLabel(type))}</div>
        <div class="small text-secondary">${esc(payload.source?.nativeType || "telemetria")}</div>
        </div>
        <div class="text-end flex-shrink-0">
        <div class="fw-semibold">${esc(card.value)}</div>
        <div class="small text-secondary">${esc(when(payload.occurredAt || payload.recordedAt) || "hora desconhecida")}</div>
        </div>
        </div>
        ${details ? `<div class="small text-secondary mt-2 text-break">${details}</div>` : ""}
        </div>`;
}

function telemetryDetails(data, payload) {
    if (payload?.type === "position") {
        return radarPositionDetails(data);
    }

    const details = [];
    const skipKeys =
        payload?.type === "ncs.event" ? new Set(["event", "alarm"]) : new Set();
    if (data && typeof data === "object") {
        for (const [key, value] of Object.entries(data)) {
            if (value === undefined || value === null || value === "") continue;
            if (skipKeys.has(key)) continue;
            details.push(`${fieldLabel(key)}: ${esc(displayValue(value))}`);
        }
    }
    if (payload?.extra && typeof payload.extra === "object") {
        details.push(
            ...Object.entries(payload.extra)
                .filter(
                    ([, value]) =>
                        value !== undefined && value !== null && value !== "",
                )
                .slice(0, 6)
                .map(
                    ([key, value]) =>
                        `${fieldLabel(key)}: ${esc(displayValue(value))}`,
                ),
        );
    }
    return details.join(" · ");
}

function radarPositionDetails(data) {
    const people = Array.isArray(data?.people) ? data.people : [];
    if (!people.length) {
        return "People: 0";
    }

    const countLabel = `People: ${people.length}`;
    const personLines = people.map((person, index) => {
        const personIndex = person?.person_index ?? index + 1;
        const x = displayValue(person?.x_position_dm);
        const y = displayValue(person?.y_position_dm);
        const z = displayValue(person?.z_position_cm);
        const posture = displayValue(person?.posture_state);

        return [
            `Person ${esc(personIndex)}`,
            `x: ${esc(x)} dm`,
            `y: ${esc(y)} dm`,
            `z: ${esc(z)} cm`,
            `posture: ${esc(posture)}`,
        ].join(" · ");
    });

    return [countLabel, ...personLines].join("<br>");
}

function renderRequestCards(groups, telemetry = []) {
    const totalCards = groups.reduce(
        (count, group) => count + group.cards.length,
        0,
    );

    els.requestCardCount.textContent = totalCards
        ? `${totalCards} ações`
        : "";
    els.requestGrid.innerHTML = totalCards
        ? groups
              .map((group) => renderRequestCardGroup(group, telemetry))
              .join("")
        : `<div class="col-12">${emptyPanel("Não há pedidos disponíveis para este dispositivo.")}</div>`;
}

function renderRequestCardGroup(group, telemetry = []) {
    return `
        <div class="col-12">
        <div class="border rounded bg-body-tertiary p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="fw-semibold">${esc(group.label || "Pedidos")}</div>
        <span class="badge text-bg-secondary">${group.cards.length}</span>
        </div>
        <div class="row g-3">
        ${group.cards
            .map((command) =>
                renderRequestCardShell(
                    command,
                    state.loadingCommands.has(
                        String(
                            command.id ||
                                command.feature ||
                                command.command ||
                                "",
                        ),
                    ),
                    telemetry,
                ),
            )
            .join("")}
        </div>
        </div>
        </div>`;
}

function renderDownlinkRequests(commands) {
    els.downlinkRequests.innerHTML = commands.length
        ? `
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
        <thead>
        <tr><th>Pedido em</th><th>Pedido</th><th>Estado</th><th>Resposta</th><th>Detalhes</th></tr>
        </thead>
        <tbody>
        ${commands.map(renderDownlinkRow).join("")}
        </tbody>
        </table>
        </div>`
        : emptyPanel("Ainda não há pedidos ao dispositivo.");
}

function renderDownlinkRow(command) {
    const status = String(command.status || "unknown");
    return `
        <tr>
        <td class="text-nowrap small">${esc(when(command.requestedAt) || "-")}</td>
        <td><div class="fw-semibold">${esc(commandLabel(command) || "Pedido")}</div><div class="small text-secondary">${esc(command.nativeType || "")}</div></td>
        <td>${statusBadge(status)}</td>
        <td class="small">${esc(command.ackedAt ? when(command.ackedAt) : command.sentAt ? when(command.sentAt) : "-")}</td>
        <td class="small text-secondary">${esc(command.error || command.replyNativeType || expectedReplies(command))}</td>
        </tr>`;
}

function renderConnectionTimeline(rows) {
    const events = rows
        .map(rowPayload)
        .filter((event) =>
            ["device.connected", "device.disconnected"].includes(
                String(event?.type || ""),
            ),
        )
        .sort((a, b) => eventTime(a) - eventTime(b));

    const connectedCount = events.filter(
        (e) => e.type === "device.connected",
    ).length;
    const disconnectedCount = events.filter(
        (e) => e.type === "device.disconnected",
    ).length;

    if (events.length < 2) {
        if (connectionChartRoot) {
            connectionChartRoot.dispose();
            connectionChartRoot = null;
        }
        els.connectionTimeline.innerHTML =
            events.length === 1
                ? `<div class="text-center text-secondary py-4"><i class="fa-solid fa-circle ${events[0].type === "device.connected" ? "text-success" : "text-secondary"} me-2"></i>${events[0].type === "device.connected" ? "Ligado" : "Desligado"} · ${esc(when(events[0].occurredAt || events[0].recordedAt))}</div>`
                : "";
        return;
    }

    if (connectionChartRoot) {
        connectionChartRoot.dispose();
    }

    connectionChartRoot = am5.Root.new(els.connectionTimeline);
    connectionChartRoot._logo?.dispose();

    connectionChartRoot.setThemes([
        am5themes_Animated.new(connectionChartRoot),
    ]);

    const chart = connectionChartRoot.container.children.push(
        am5xy.XYChart.new(connectionChartRoot, {
            panX: false,
            panY: false,
            wheelX: "none",
            wheelY: "none",
            paddingTop: 8,
            paddingBottom: 8,
            paddingLeft: 0,
            paddingRight: 0,
        }),
    );

    const dateAxis = chart.xAxes.push(
        am5xy.DateAxis.new(connectionChartRoot, {
            baseInterval: { timeUnit: "minute", count: 1 },
            renderer: am5xy.AxisRendererX.new(connectionChartRoot, {
                minGridDistance: 60,
            }),
            tooltip: am5.Tooltip.new(connectionChartRoot, {}),
        }),
    );
    dateAxis.get("renderer").grid.template.set("visible", false);

    const valueAxis = chart.yAxes.push(
        am5xy.ValueAxis.new(connectionChartRoot, {
            renderer: am5xy.AxisRendererY.new(connectionChartRoot, {}),
            min: -0.2,
            max: 0.2,
            strictMinMax: true,
        }),
    );
    valueAxis.get("renderer").grid.template.set("visible", false);
    valueAxis.get("renderer").labels.template.set("forceHidden", true);
    valueAxis.get("renderer").set("visible", false);

    const data = connectionTimelineData(events);
    const series = chart.series.push(
        am5xy.LineSeries.new(connectionChartRoot, {
            name: "Ligação",
            xAxis: dateAxis,
            yAxis: valueAxis,
            valueYField: "value",
            valueXField: "date",
            stroke: am5.color(0x6c757d),
            strokeWidth: 2,
            tooltip: am5.Tooltip.new(connectionChartRoot, {
                labelText: '{label} em {valueX.formatDate("dd/MM/yyyy HH:mm")}',
            }),
        }),
    );
    series.data.setAll(data);

    series.bullets.push(function (_root, _series, dataItem) {
        const color = dataItem.dataContext?.bulletColor || "#6c757d";
        return am5.Bullet.new(connectionChartRoot, {
            sprite: am5.Circle.new(connectionChartRoot, {
                radius: 5,
                fill: am5.color(color),
                stroke: am5.color(0xffffff),
                strokeWidth: 1,
            }),
        });
    });

    dateAxis.start = 0;
    dateAxis.end = 1;

    chart.set(
        "cursor",
        am5xy.XYCursor.new(connectionChartRoot, {
            behavior: "none",
            xAxis: dateAxis,
        }),
    );
}

function connectionTimelineData(events) {
    return events
        .map((event) => {
            const isConnected = event.type === "device.connected";
            return {
                date: eventTime(event),
                value: 0,
                label: isConnected ? "Ligado" : "Desligado",
                bulletColor: isConnected ? "#198754" : "#dc3545",
            };
        })
        .filter((point) => point.date > 0);
}

function expectedReplies(command) {
    return Array.isArray(command.expectedReplyTypes) &&
        command.expectedReplyTypes.length
        ? `À espera de ${command.expectedReplyTypes.join(", ")}`
        : "";
}

async function requestTelemetryFeature(feature) {
    state.loadingCommands.add(feature);
    renderSelection();
    try {
        const result = await apiRequestFeature(state.selectedImei, feature);
        if (result.error) alert(result.error.message || result.error.code);
        if (state.selectedImei) {
            await loadDevice(state.selectedImei);
        }
    } finally {
        state.loadingCommands.delete(feature);
        renderSelection();
    }
}

const SELECTED_DEVICE_STORAGE_KEY = "hub-dashboard-selected-device";

function saveSelectedDeviceToStorage() {
    if (state.selectedImei) {
        saveTextStorage(SELECTED_DEVICE_STORAGE_KEY, state.selectedImei);
    }
}

function clearSelectedDeviceFromStorage() {
    clearStorageKey(SELECTED_DEVICE_STORAGE_KEY);
}

export {
    apiRoleLabel,
    applyDetailFilters,
    capabilitiesForSupplier,
    capabilitiesGroupedBySection,
    capabilityCatalogEntryByKey,
    capabilityLabelByKey,
    capabilitySectionLabel,
    clearDetailFilters,
    clearSelection,
    companyLabel,
    deriveFourPTouchDeviceId,
    deviceTypeLabel,
    deviceTypeOptions,
    ensureLicensesLoaded,
    ensureModelsLoaded,
    ensureSuppliersLoaded,
    findModelInfo,
    flattenedCapabilityKeys,
    allDetailItems,
    filterDetailItems,
    handleDeviceListLimitChange,
    handleDeviceListSearchInput,
    handleDevicePaginationClick,
    humanizeCapabilityKey,
    isDeviceSelectorOpen,
    isFourPTouchSelection,
    licenseDisplayLabel,
    licenseLabel,
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
    renderTelemetryList,
    renderSelection,
    requestTelemetryFeature,
    selectDevice,
    supplierProtocol,
    suppliersForDeviceType,
    suppliersFromModels,
};
