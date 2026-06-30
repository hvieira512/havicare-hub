import {api} from './api.js';
import {clearSelection, selectImei, setTelemetryPage, state} from './state.js';
import {
    ago,
    commandLabel,
    displayValue,
    esc,
    eventTime,
    featureLabel,
    fieldLabel,
    rowPayload,
    when,
} from './format.js';
import {
    commandFeature,
    emptyPanel,
    modelImageHtml,
    modelPreviewHtml,
    renderButtonGroup,
    renderRequestCardShell,
    statusBadge,
    uplinkCardContent,
} from './renderers.js';
import {
    catalogForProtocol,
    readConfigPayload,
    renderDeviceConfigurationRoot,
} from './config.js';
import {normalizePhoneControl, renderPhoneControl, resetPhoneControls, syncPhoneControl} from './phone.js';

let deviceEventSource = null;

function connectDeviceStream(imei) {
    disconnectDeviceStream();
    const url = new URL(`/api/devices/${encodeURIComponent(imei)}/stream`, window.location.origin);
    const token = window.hubDashboardApiToken?.access_token || '';
    if (token) {
        url.searchParams.set('access_token', token);
    }
    deviceEventSource = new EventSource(url);
    deviceEventSource.addEventListener('snapshot', function (e) {
        const data = JSON.parse(e.data);
        if (!state.selectedDetail) return;
        state.selectedDetail.recent = {
            telemetry: data.telemetry || [],
            events: data.events || [],
            commands: data.commands || [],
        };
        renderSelection();
    });
    deviceEventSource.addEventListener('update', function (e) {
        const data = JSON.parse(e.data);
        if (!state.selectedDetail) return;
        state.selectedDetail.recent = {
            telemetry: data.telemetry || [],
            events: data.events || [],
            commands: data.commands || [],
        };
        renderSelection();
    });
    deviceEventSource.onerror = function () {
        if (deviceEventSource?.readyState === EventSource.CLOSED) {
            disconnectDeviceStream();
        }
    };
}

function disconnectDeviceStream() {
    if (deviceEventSource) {
        deviceEventSource.close();
        deviceEventSource = null;
    }
}

let connectionChartRoot = null;

let els = {};
let deviceModal = null;
let deviceSelectorModal = null;
let settingsModal = null;
const configFeedbackTimers = new Map();
const configPhaseTimers = new Map();
const capabilitySectionLabels = {
    telemetry: 'Telemetry',
    health: 'Health',
    contacts: 'Contacts',
    alarms: 'Alarms',
    settings_system: 'Settings / System',
};
const capabilityLabels = {
    location: 'Location telemetry',
    heart_rate: 'Heart rate telemetry',
    blood_pressure: 'Blood pressure telemetry',
    blood_oxygen: 'Blood oxygen telemetry',
    temperature: 'Temperature telemetry',
    breath_rate: 'Breath rate telemetry',
    sleep: 'Sleep telemetry',
    ecg: 'ECG telemetry',
    hrv: 'HRV telemetry',
    ppg: 'PPG telemetry',
    rr_interval: 'RR interval telemetry',
    auto_vitals_interval: 'Auto vitals interval',
    heart_rate_measurement_interval: 'Heart rate interval',
    blood_pressure_measurement_interval: 'Blood pressure interval',
    blood_oxygen_measurement_interval: 'Blood oxygen interval',
    temperature_measurement_interval: 'Temperature interval',
    breath_rate_measurement_interval: 'Breath rate interval',
    ecg_measurement_interval: 'ECG interval',
    hrv_measurement_interval: 'HRV interval',
    ppg_measurement_interval: 'PPG interval',
    rr_interval_measurement_interval: 'RR interval setting',
    heart_rate_continuous: 'Continuous heart rate',
    blood_oxygen_continuous: 'Continuous blood oxygen',
    blood_pressure_trend: 'Blood pressure trend',
    temperature_continuous: 'Continuous temperature',
    step_goal: 'Step goal',
    sleep_monitoring: 'Sleep monitoring',
    blood_pressure_calibration: 'Blood pressure calibration',
    step_reporting_interval: 'Step interval',
    pedometer_schedule: 'Pedometer schedule',
    sos_contacts: 'SOS contacts',
    phonebook: 'Phonebook',
    call_whitelist: 'Call whitelist',
    monitor_number: 'Monitor number',
    alarm_clock: 'Alarm clock',
    medication_reminders: 'Medication reminders',
    low_battery_alert: 'Low battery alert',
    fall_detection: 'Fall detection',
    fall_sensitivity: 'Fall sensitivity',
    sos_sms_alert: 'SOS SMS alert',
    blood_oxygen_alert: 'Blood oxygen alert',
    temperature_high_alert: 'High temperature alert',
    temperature_low_alert: 'Low temperature alert',
    blood_pressure_alert: 'Blood pressure alert',
    heart_rate_high_alert: 'High heart rate alert',
    heart_rate_low_alert: 'Low heart rate alert',
    remove_watch_alarm: 'Remove watch alarm',
    remove_watch_sms_alert: 'Remove watch SMS alert',
    location_reporting_interval: 'Location reporting interval',
    working_mode: 'Working mode',
    device_binding: 'Device binding',
    call_in_restriction: 'Incoming call restriction',
    device_settings_sync: 'Device settings sync',
    device_password: 'Device password',
    language_timezone: 'Language and timezone',
};

let deviceConfigRefreshPromise = null;
let deviceSearchTimer = null;
const FILTERS_STORAGE_KEY = 'hub-dashboard-device-filters';
const SELECTED_DEVICE_STORAGE_KEY = 'hub-dashboard-selected-device';
const deviceTypeOptions = [
    {value: 'watch', label: 'Relógio'},
    {value: 'ncs', label: 'NCS'},
    {value: 'radar', label: 'Radars'},
];

function deviceTypeLabel(deviceType) {
    return deviceTypeOptions.find(option => option.value === deviceType)?.label || deviceType;
}

function suppliersForDeviceType(deviceType, models = state.summary.models) {
    const allSuppliers = suppliersFromModels(models);
    if (!deviceType || deviceType === 'watch') {
        return allSuppliers;
    }
    const deviceTypeSuppliers = (models || [])
        .filter(model => normalizeDeviceType(model.device_type || model.deviceType || 'watch') === deviceType)
        .map(model => model.supplier)
        .filter(Boolean);
    return allSuppliers.filter(name => deviceTypeSuppliers.includes(name));
}

function normalizeDeviceType(deviceType) {
    return deviceTypeOptions.some(option => option.value === deviceType) ? deviceType : 'watch';
}

function normalizeFilterValue(value) {
    if (!value || value === 'undefined' || value === 'all') return null;
    return String(value);
}

function normalizeLicenseId(licenseId) {
    const value = String(licenseId ?? '0').trim();
    return value === '' ? '0' : value;
}

function licenseLabel(licenseId) {
    return normalizeLicenseId(licenseId) === '0' ? 'Sem Licença' : normalizeLicenseId(licenseId);
}

function companyLabel(company) {
    const value = String(company ?? '').trim();
    return value === '' || value === 'null' ? 'Sem empresa' : value;
}

function licenseDisplayLabel(licenseId, licenses = state.settingsModal.licenses || []) {
    const normalized = normalizeLicenseId(licenseId);
    if (normalized === '0') {
        return 'Sem Licença';
    }

    const match = (licenses || []).find(item => String(item.license_id || item.licenseId || '') === normalized);
    if (!match) {
        return normalized;
    }

    const name = String(match.name || '').trim();
    return name !== '' ? `${name} (${normalized})` : normalized;
}

function apiRoleLabel(role) {
    return role === 'hub_admin' ? 'Admin Hub' : 'Cliente por licença';
}

function supplierProtocol(supplier, models = state.summary.models) {
    const existing = models.find(model => model.supplier === supplier && model.protocol);
    return existing?.protocol || '';
}

function modelInternalName(model) {
    return String(model.internal_model || model.internalModel || model.model || '');
}

function modelCommercialName(model) {
    return String(model.commercial_name || model.commercialName || model.internal_model || model.internalModel || model.model || '');
}

function modelDeviceType(model) {
    return normalizeDeviceType(model?.device_type || model?.deviceType || 'watch');
}

function suppliersFromModels(models = state.summary.models) {
    return [...new Set(models.map(model => model.supplier).filter(Boolean))];
}

function modelsForSupplier(supplier, models = state.summary.models) {
    return models.filter(model => model.supplier === supplier);
}

function findModelInfo(supplier, model, models = state.summary.models) {
    return models.find(entry => entry.supplier === supplier && modelInternalName(entry) === model) || null;
}

function modelDisplayName(supplier, model, models = state.summary.models) {
    const info = findModelInfo(supplier, model, models);
    return info ? modelCommercialName(info) : model;
}

function modelsForSupplierAndType(supplier, deviceType, models = state.summary.models) {
    return modelsForSupplier(supplier, models).filter(model => modelDeviceType(model) === normalizeDeviceType(deviceType));
}

function modelDisplayLabel(model) {
    const commercialName = modelCommercialName(model);
    const internalName = modelInternalName(model);
    return commercialName === internalName ? commercialName : `${commercialName} (${internalName})`;
}

function deriveFourPTouchDeviceId(imei) {
    const digits = String(imei || '').replace(/\D+/g, '');
    if (digits.length === 15) return digits.slice(4, 14);
    if (digits.length === 10) return digits;
    if (digits.length > 10) return digits.slice(-10);
    return digits;
}

function isFourPTouchSelection(supplier = els.deviceForm?.dataset?.supplier || '', model = els.deviceForm?.dataset?.model || '') {
    return supplierProtocol(supplier, state.summary.models) === 'four-p-touch' || supplier === '4P Touch';
}

function capabilitiesForSupplier(supplier, models = state.summary.models) {
    const entry = models.find(model => model.supplier === supplier && model?.capabilities && typeof model.capabilities === 'object');
    return flattenedCapabilityKeys(entry?.capabilities || {});
}

function flattenedCapabilityKeys(capabilities) {
    const enabled = [];
    for (const entries of Object.values(capabilities || {})) {
        if (!entries || typeof entries !== 'object') {
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
    return capabilityLabels[key] || String(key || '').replace(/_/g, ' ');
}

function modelsForCapabilitySupplier(supplier, models = state.summary.models) {
    return models.filter(model => model.supplier === supplier);
}

async function loadSummary() {
    const [devicesResponse] = await Promise.all([
        api.devices({
            page: state.deviceListPage,
            limit: state.deviceListPageSize,
            deviceType: state.deviceFilters.deviceType,
            licenseId: state.deviceFilters.licenseId,
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
        devicePagination: devicesResponse.pagination || {limit: state.deviceListPageSize, page: 1, total_pages: 1, total: 0},
        deviceFiltersAvailable: devicesResponse.filters?.available || {deviceType: [], licenseId: [], supplier: [], model: []},
    };
    state.deviceListPageSize = state.summary.devicePagination.limit || state.deviceListPageSize;
    state.deviceListPage = state.summary.devicePagination.page || 1;
    renderDeviceSelector();
    if (state.selectedImei) {
        await loadDevice(state.selectedImei);
    } else {
        renderSelection();
    }
}

async function ensureModelsLoaded(force = false) {
    if (!force && Array.isArray(state.summary.models) && state.summary.models.length > 0) {
        return state.summary.models;
    }

    const modelsResponse = await api.models({limit: 500});
    state.summary.models = modelsResponse.data || [];
    return state.summary.models;
}

async function ensureLicensesLoaded(force = false) {
    if (!force && Array.isArray(state.settingsModal.licenses) && state.settingsModal.licenses.length > 0) {
        return state.settingsModal.licenses;
    }

    const licensesResponse = await api.licenses({limit: 500});
    state.settingsModal.licenses = licensesResponse.data || [];
    return state.settingsModal.licenses;
}

async function ensureSuppliersLoaded(force = false) {
    if (!force && Array.isArray(state.modelModalSuppliers) && state.modelModalSuppliers.length > 0) {
        return state.modelModalSuppliers;
    }

    const suppliersResponse = await api.suppliers({limit: 500});
    state.modelModalSuppliers = suppliersResponse.data || [];
    return state.modelModalSuppliers;
}

async function openDeviceSelector() {
    await loadSummary();
    deviceSelectorModal?.show();
}

function isDeviceSelectorOpen() {
    const modalEl = document.getElementById('deviceSelectorModal');
    return !!modalEl && modalEl.classList.contains('show');
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

    const tableMarkup = state.summary.devices.length ? `
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
                    ${state.summary.devices.map(device => {
                        const modelInfo = modelLookup[`${device.supplier}:${device.model}`];
                        const isSelected = state.selectedImei === device.imei;
                        return `
                            <tr${isSelected ? ' class="table-primary"' : ''} data-imei="${esc(device.imei)}" data-action="select" role="button" tabindex="0">
                                <td style="width:52px">${modelImageHtml(modelInfo)}</td>
                                <td>
                                    <span class="d-inline-flex align-items-center gap-2 small">
                                        <span class="rounded-circle ${device.online ? 'bg-success' : 'bg-danger'} d-inline-block flex-shrink-0" style="width:.55rem;height:.55rem;"></span>
                                        ${device.online ? 'Ligado' : 'Desligado'}
                                    </span>
                                </td>
                                <td class="fw-semibold text-break">${esc(device.imei)}</td>
                                <td>${esc(deviceTypeLabel(normalizeDeviceType(device.deviceType)))}</td>
                                <td>${esc(companyLabel(device.company))}</td>
                                <td>${esc(licenseDisplayLabel(device.licenseId))}</td>
                                <td class="text-break">${esc(device.simNumber || '-')}</td>
                                <td>${esc(device.supplier || '-')}</td>
                                <td>${esc(modelInfo ? modelCommercialName(modelInfo) : (device.model || '-'))}</td>
                            </tr>`;
                    }).join('')}
                </tbody>
            </table>
        </div>
    ` : emptyPanel('Não há dispositivos para o filtro selecionado.');

    els.deviceList.innerHTML = tableMarkup;
    renderDevicePagination(state.summary.devicePagination);
}

function renderDevicePagination(pagination) {
    const root = els.deviceListPagination;
    const summaryEl = els.deviceListPaginationSummary;
    const controlsEl = els.deviceListPaginationControls;
    const totalRows = pagination?.total ?? 0;
    const totalPages = pagination?.total_pages ?? 1;
    const currentPage = pagination?.page ?? 1;
    const limit = pagination?.limit ?? state.deviceListPageSize;

    if (totalPages <= 1) {
        root.classList.add('d-none');
        summaryEl.textContent = '';
        controlsEl.innerHTML = '';
        return;
    }

    const pageStart = ((currentPage - 1) * limit) + 1;
    const pageEnd = Math.min(totalRows, currentPage * limit);
    root.classList.remove('d-none');
    summaryEl.textContent = `A mostrar de ${pageStart} até ${pageEnd} | ${totalRows}`;
    controlsEl.innerHTML = [
        `<button type="button" class="btn btn-outline-secondary btn-sm" data-action="devicePagePrev" ${currentPage <= 1 ? 'disabled' : ''} aria-label="Página anterior"><i class="fa-solid fa-chevron-left"></i></button>`,
        ...Array.from({length: totalPages}, (_, index) => {
            const page = index + 1;
            return `<button type="button" class="btn ${page === currentPage ? 'btn-primary' : 'btn-outline-secondary'} btn-sm" data-action="devicePageGo" data-page="${page}" ${page === currentPage ? 'aria-current="page"' : ''}>${page}</button>`;
        }),
        `<button type="button" class="btn btn-outline-secondary btn-sm" data-action="devicePageNext" ${currentPage >= totalPages ? 'disabled' : ''} aria-label="Página seguinte"><i class="fa-solid fa-chevron-right"></i></button>`,
    ].join('');
}

function renderSelectOptions(select, options, selectedValue, labelForValue) {
    const normalizedSelectedValue = normalizeFilterValue(selectedValue);
    const html = [
        '<option value="all">Todos</option>',
        ...options.map(option => `<option value="${esc(option)}"${option === normalizedSelectedValue ? ' selected' : ''}>${esc(labelForValue(option))}</option>`),
    ];
    select.innerHTML = html.join('');
    select.value = options.includes(normalizedSelectedValue) ? normalizedSelectedValue : 'all';
}

function renderDeviceFilterControls() {
    const options = state.summary.deviceFiltersAvailable || {deviceType: [], licenseId: [], supplier: [], model: []};
    renderSelectOptions(els.deviceTypeFilter, options.deviceType || [], state.deviceFilters.deviceType, value => deviceTypeLabel(value));
    renderSelectOptions(els.deviceLicenseFilter, options.licenseId || [], state.deviceFilters.licenseId, value => licenseLabel(value));
    renderSelectOptions(els.deviceSupplierFilter, options.supplier || [], state.deviceFilters.supplier, value => value);
    renderSelectOptions(els.deviceModelFilter, options.model || [], state.deviceFilters.model, value => modelDisplayName('', value));
    renderAppliedDeviceFilters();
}

function renderAppliedDeviceFilters() {
    const labels = [];

    if (state.deviceFilters.deviceType) {
        labels.push({key: 'deviceType', label: `Tipo: ${deviceTypeLabel(state.deviceFilters.deviceType)}`});
    }
    if (state.deviceFilters.licenseId) {
        labels.push({key: 'licenseId', label: `Licença: ${licenseLabel(state.deviceFilters.licenseId)}`});
    }
    if (state.deviceFilters.supplier) {
        labels.push({key: 'supplier', label: `Fornecedor: ${state.deviceFilters.supplier}`});
    }
    if (state.deviceFilters.model) {
        labels.push({key: 'model', label: `Modelo: ${modelDisplayName('', state.deviceFilters.model)}`});
    }

    els.deviceActiveFilters.innerHTML = labels.length
        ? labels.map(item => `
            <span class="badge text-bg-secondary d-inline-flex align-items-center gap-2">
                <span>${esc(item.label)}</span>
                <button type="button" class="btn btn-sm p-0 border-0 text-white" data-action="removeDeviceFilter" data-filter-key="${esc(item.key)}" aria-label="Remover filtro">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </span>
        `).join('')
        : '<span class="small text-secondary">Sem filtros ativos</span>';
}

function handleDeviceListLimitChange() {
    const nextLimit = parseInt(els.deviceListLimit.value || '5', 10) || 5;
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
    const button = event.target.closest('[data-action="devicePagePrev"], [data-action="devicePageNext"], [data-action="devicePageGo"]');
    if (!button) return;

    const currentPage = state.summary.devicePagination?.page || 1;
    const totalPages = state.summary.devicePagination?.total_pages || 1;
    if (button.dataset.action === 'devicePagePrev') {
        state.deviceListPage = Math.max(1, currentPage - 1);
    } else if (button.dataset.action === 'devicePageNext') {
        state.deviceListPage = Math.min(totalPages, currentPage + 1);
    } else {
        state.deviceListPage = Math.min(Math.max(1, parseInt(button.dataset.page || '1', 10) || 1), totalPages);
    }
    void loadSummary();
}

async function selectDevice(imei) {
    selectImei(imei);
    saveSelectedDeviceToStorage();
    const loaded = await loadDevice(imei);
    if (loaded) {
        deviceSelectorModal?.hide();
    }
}

async function loadDevice(imei) {
    const detail = await api.device(imei);
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
    renderSelection();
    connectDeviceStream(imei);
    return true;
}

function renderSelection() {
    els.deviceSelectionEmptyState.classList.toggle('d-none', !!state.selectedDetail);
    els.selectedDevicePanel.classList.toggle('d-none', !state.selectedDetail);
    els.detailEmptyState.classList.toggle('d-none', !!state.selectedDetail);
    els.deviceDetail.classList.toggle('d-none', !state.selectedDetail);
    if (!state.selectedDetail) {
        if (connectionChartRoot) {
            connectionChartRoot.dispose();
            connectionChartRoot = null;
        }
        els.requestCardCount.textContent = '';
        els.requestGrid.innerHTML = '';
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
    const ncsEvents = filtered.filter(item => item._source === 'event' && item.payload?.type === 'ncs.event').map(item => item.raw);
    const telemetry = filtered.filter(item => item._source === 'telemetry').map(item => item.raw);
    const commands = filtered.filter(item => item._source === 'command').map(item => item.raw);
    const connectionEvents = filtered.filter(item => item._source === 'connection').map(item => item.raw);

    renderTelemetryList([...telemetry, ...ncsEvents]);
    renderRequestCards(telemetryRequestCards(state.selectedDetail?.capabilities?.telemetry || {}), telemetry);
    renderDownlinkRequests(commands);
    renderConnectionTimeline(connectionEvents);
}

function telemetryRequestCards(telemetryCapabilities = {}) {
    return Object.entries(telemetryCapabilities || {})
        .filter(([, entry]) => entry?.supported)
        .map(([feature, entry]) => ({id: feature, feature, requestable: !!entry?.requestable}))
        .sort((a, b) => String(featureLabel(a.feature || '')).localeCompare(String(featureLabel(b.feature || '')), 'pt-PT'));
}

function renderSelectedDeviceSummary(device, deviceModel) {
    const supplier = String(deviceModel?.supplier || '');
    const model = String(deviceModel?.internalModel || '');
    const modelInfo = findModelInfo(supplier, model);
    const facts = [
        {label: 'Tipo', value: deviceTypeLabel(normalizeDeviceType(deviceModel?.deviceType || 'watch'))},
        {label: 'Licença', value: licenseLabel(device.licenseId)},
        {label: 'Fornecedor', value: supplier || '-'},
        {label: 'Modelo', value: modelInfo ? modelCommercialName(modelInfo) : (model || '-')},
        {label: 'Última ligação', value: when(device.lastSeenAt) || 'Sem registo'},
    ];

    if (device.simNumber) {
        facts.push({label: 'SIM', value: String(device.simNumber)});
    }
    if (device.deviceId && String(device.deviceId) !== String(device.imei)) {
        facts.push({label: 'Device ID', value: String(device.deviceId)});
    }

    els.selectedDevicePreview.innerHTML = modelImageHtml(modelInfo);
    els.selectedDeviceTitle.textContent = device.imei;
    els.selectedDeviceMeta.textContent = `${supplier || 'Sem fornecedor'}${model ? ` · ${model}` : ''}`;
    els.selectedDeviceBadge.className = `badge ${device.online ? 'text-bg-success' : 'text-bg-secondary'}`;
    els.selectedDeviceBadge.textContent = device.online ? 'ligado' : 'desligado';
    els.selectedDeviceFacts.innerHTML = facts.map(item => `
        <div class="col-12 col-sm-6">
            <dt>${esc(item.label)}</dt>
            <dd class="text-break">${esc(item.value)}</dd>
        </div>
    `).join('');
}

function allDetailItems() {
    const items = [];
    const recent = state.selectedDetail.recent || {};
    for (const row of (recent.telemetry || [])) {
        const payload = rowPayload(row);
        if (payload && !payload.debug) items.push({_source: 'telemetry', raw: row, payload});
    }
    for (const row of (recent.events || [])) {
        const payload = rowPayload(row);
        if (!payload) continue;
        if (payload.type === 'ncs.event') items.push({_source: 'event', raw: row, payload});
        if (payload.type === 'device.connected' || payload.type === 'device.disconnected') items.push({_source: 'connection', raw: row, payload});
    }
    for (const row of (recent.commands || [])) {
        const payload = rowPayload(row);
        if (payload) items.push({_source: 'command', raw: row, payload});
    }
    return items;
}

function filterDetailItems(items) {
    const {from, to, type} = state.detailFilters;
    return items.filter(item => {
        if (type !== 'all' && type !== '') {
            const itemType = detailItemType(item);
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
    if (p.type === 'ncs.event') return p.data?.event || 'general_alert';
    if (p.type === 'device.connected') return 'device.connected';
    if (p.type === 'device.disconnected') return 'device.disconnected';
    if (p.nativeType) return p.nativeType;
    if (p.type && p.type !== 'telemetry') return p.type;
    return 'outros';
}

function itemTime(item) {
    const p = item.payload;
    return Date.parse(p.occurredAt || p.recordedAt || p.requestedAt || '');
}

function populateDetailFilterTypes() {
    const items = allDetailItems();
    const types = new Set();
    for (const item of items) {
        const t = detailItemType(item);
        if (t) types.add(t);
    }
    const select = els.detailFilterType;
    const currentValue = state.detailFilters.type;
    const sorted = [...types].sort();
    select.innerHTML = ['<option value="all">Todos</option>', ...sorted.map(t => `<option value="${esc(t)}">${esc(detailTypeLabel(t))}</option>`)].join('');
    select.value = sorted.includes(currentValue) ? currentValue : 'all';
}

function detailTypeLabel(type) {
    const labels = {
        help_call: 'SOS',
        reset: 'Cancelado',
        general_alert: 'Alerta Geral',
        'device.connected': 'Ligado',
        'device.disconnected': 'Desligado',
    };
    return labels[type] || featureLabel(type) || type;
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
    state.detailFilters = {from: '', to: '', type: 'all'};
    state.telemetryPage = 1;
    renderSelection();
}

function renderTelemetryList(telemetryRows) {
    const telemetry = telemetryRows
        .map(rowPayload)
        .filter(payload => payload && !payload.debug)
        .sort((a, b) => eventTime(b) - eventTime(a));
    const totalPages = Math.max(1, Math.ceil(telemetry.length / state.telemetryPageSize));
    setTelemetryPage(state.telemetryPage, totalPages);

    const start = (state.telemetryPage - 1) * state.telemetryPageSize;
    const pageRows = telemetry.slice(start, start + state.telemetryPageSize);

    els.telemetryCount.textContent = telemetry.length ? `${telemetry.length} eventos` : '';
    els.telemetryList.innerHTML = pageRows.length
        ? `<div class="list-group">${pageRows.map(renderTelemetryRow).join('')}</div>`
        : emptyPanel('Ainda não há eventos recebidos.');
    renderTelemetryPager(telemetry.length, totalPages);
}

function renderTelemetryPager(totalRows, totalPages) {
    const root = els.telemetryPager;
    const summaryEl = els.telemetryPagerSummary;
    const controlsEl = els.telemetryPagerControls;

    if (totalRows <= state.telemetryPageSize) {
        root.classList.add('d-none');
        summaryEl.textContent = '';
        controlsEl.innerHTML = '';
        return;
    }

    const currentPage = state.telemetryPage;
    const limit = state.telemetryPageSize;
    const pageStart = ((currentPage - 1) * limit) + 1;
    const pageEnd = Math.min(totalRows, currentPage * limit);
    root.classList.remove('d-none');
    summaryEl.textContent = `${pageStart}–${pageEnd} de ${totalRows}`;
    controlsEl.innerHTML = [
        `<button type="button" class="btn btn-outline-secondary btn-sm" data-action="telemetryPrev" ${currentPage <= 1 ? 'disabled' : ''} aria-label="Página anterior"><i class="fa-solid fa-chevron-left"></i></button>`,
        ...Array.from({length: totalPages}, (_, index) => {
            const page = index + 1;
            return `<button type="button" class="btn ${page === currentPage ? 'btn-primary' : 'btn-outline-secondary'} btn-sm" data-action="telemetryPageGo" data-page="${page}" ${page === currentPage ? 'aria-current="page"' : ''}>${page}</button>`;
        }),
        `<button type="button" class="btn btn-outline-secondary btn-sm" data-action="telemetryNext" ${currentPage >= totalPages ? 'disabled' : ''} aria-label="Página seguinte"><i class="fa-solid fa-chevron-right"></i></button>`,
    ].join('');
}

function renderTelemetryRow(payload) {
    const type = payload?.type || 'telemetry';
    const data = payload?.data && typeof payload.data === 'object' ? payload.data : {};
    const card = uplinkCardContent(type, data);
    const details = telemetryDetails(data, payload);

    return `
        <div class="list-group-item">
        <div class="d-flex justify-content-between gap-3">
        <div class="min-width-0">
        <div class="fw-semibold"><i class="fa-solid ${esc(card.icon)} text-secondary me-2"></i>${esc(featureLabel(type))}</div>
        <div class="small text-secondary">${esc(payload.source?.nativeType || 'telemetria')}</div>
        </div>
        <div class="text-end flex-shrink-0">
        <div class="fw-semibold">${esc(card.value)}</div>
        <div class="small text-secondary">${esc(when(payload.occurredAt || payload.recordedAt) || 'hora desconhecida')}</div>
        </div>
        </div>
        ${details ? `<div class="small text-secondary mt-2 text-break">${details}</div>` : ''}
        </div>`;
}

function telemetryDetails(data, payload) {
    if (payload?.type === 'radar.position') {
        return radarPositionDetails(data);
    }

    const details = [];
    const skipKeys = payload?.type === 'ncs.event' ? new Set(['event', 'alarm']) : new Set();
    if (data && typeof data === 'object') {
        for (const [key, value] of Object.entries(data)) {
            if (value === undefined || value === null || value === '') continue;
            if (skipKeys.has(key)) continue;
            details.push(`${fieldLabel(key)}: ${esc(displayValue(value))}`);
        }
    }
    if (payload?.extra && typeof payload.extra === 'object') {
        details.push(...Object.entries(payload.extra)
            .filter(([, value]) => value !== undefined && value !== null && value !== '')
            .slice(0, 6)
            .map(([key, value]) => `${fieldLabel(key)}: ${esc(displayValue(value))}`));
    }
    return details.join(' · ');
}

function radarPositionDetails(data) {
    const people = Array.isArray(data?.people) ? data.people : [];
    if (!people.length) {
        return 'People: 0';
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
        ].join(' · ');
    });

    return [countLabel, ...personLines].join('<br>');
}

function renderRequestCards(commands, telemetry = []) {
    els.requestCardCount.textContent = commands.length ? `${commands.length} ações` : '';
    els.requestGrid.innerHTML = commands.length
        ? commands.map(command => renderRequestCardShell(command, state.loadingCommands.has(String(command.id || command.feature || command.command || '')), telemetry)).join('')
        : `<div class="col-12">${emptyPanel('Não há pedidos disponíveis para este dispositivo.')}</div>`;
}

function renderDownlinkRequests(commands) {
    els.downlinkRequests.innerHTML = commands.length ? `
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
        <thead>
        <tr><th>Pedido em</th><th>Pedido</th><th>Estado</th><th>Resposta</th><th>Detalhes</th></tr>
        </thead>
        <tbody>
        ${commands.map(renderDownlinkRow).join('')}
        </tbody>
        </table>
        </div>` : emptyPanel('Ainda não há pedidos ao dispositivo.');
}

function renderDownlinkRow(command) {
    const status = String(command.status || 'unknown');
    return `
        <tr>
        <td class="text-nowrap small">${esc(when(command.requestedAt) || '-')}</td>
        <td><div class="fw-semibold">${esc(commandLabel(command) || 'Pedido')}</div><div class="small text-secondary">${esc(command.nativeType || '')}</div></td>
        <td>${statusBadge(status)}</td>
        <td class="small">${esc(command.ackedAt ? when(command.ackedAt) : (command.sentAt ? when(command.sentAt) : '-'))}</td>
        <td class="small text-secondary">${esc(command.error || command.replyNativeType || expectedReplies(command))}</td>
        </tr>`;
}

function renderConnectionTimeline(rows) {
    const events = rows
        .map(rowPayload)
        .filter(event => ['device.connected', 'device.disconnected'].includes(String(event?.type || '')))
        .sort((a, b) => eventTime(a) - eventTime(b));

    const connectedCount = events.filter(e => e.type === 'device.connected').length;
    const disconnectedCount = events.filter(e => e.type === 'device.disconnected').length;

    if (events.length < 2) {
        if (connectionChartRoot) {
            connectionChartRoot.dispose();
            connectionChartRoot = null;
        }
        els.connectionTimeline.innerHTML = events.length === 1
            ? `<div class="text-center text-secondary py-4"><i class="fa-solid fa-circle ${events[0].type === 'device.connected' ? 'text-success' : 'text-secondary'} me-2"></i>${events[0].type === 'device.connected' ? 'Ligado' : 'Desligado'} · ${esc(when(events[0].occurredAt || events[0].recordedAt))}</div>`
            : '';
        return;
    }

    if (connectionChartRoot) {
        connectionChartRoot.dispose();
    }

    connectionChartRoot = am5.Root.new(els.connectionTimeline);
    connectionChartRoot._logo?.dispose();

    connectionChartRoot.setThemes([am5themes_Animated.new(connectionChartRoot)]);

    const chart = connectionChartRoot.container.children.push(am5xy.XYChart.new(connectionChartRoot, {
        panX: false,
        panY: false,
        wheelX: 'none',
        wheelY: 'none',
        paddingTop: 8,
        paddingBottom: 8,
        paddingLeft: 0,
        paddingRight: 0,
    }));

    const dateAxis = chart.xAxes.push(am5xy.DateAxis.new(connectionChartRoot, {
        baseInterval: {timeUnit: 'minute', count: 1},
        renderer: am5xy.AxisRendererX.new(connectionChartRoot, {
            minGridDistance: 60,
        }),
        tooltip: am5.Tooltip.new(connectionChartRoot, {}),
    }));
    dateAxis.get('renderer').grid.template.set('visible', false);

    const valueAxis = chart.yAxes.push(am5xy.ValueAxis.new(connectionChartRoot, {
        renderer: am5xy.AxisRendererY.new(connectionChartRoot, {}),
        min: -0.2,
        max: 0.2,
        strictMinMax: true,
    }));
    valueAxis.get('renderer').grid.template.set('visible', false);
    valueAxis.get('renderer').labels.template.set('forceHidden', true);
    valueAxis.get('renderer').set('visible', false);

    const data = connectionTimelineData(events);
    const series = chart.series.push(am5xy.LineSeries.new(connectionChartRoot, {
        name: 'Ligação',
        xAxis: dateAxis,
        yAxis: valueAxis,
        valueYField: 'value',
        valueXField: 'date',
        stroke: am5.color(0x6c757d),
        strokeWidth: 2,
        tooltip: am5.Tooltip.new(connectionChartRoot, {
            labelText: '{label} em {valueX.formatDate("dd/MM/yyyy HH:mm")}',
        }),
    }));
    series.data.setAll(data);

    series.bullets.push(function (_root, _series, dataItem) {
        const color = dataItem.dataContext?.bulletColor || '#6c757d';
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

    chart.set('cursor', am5xy.XYCursor.new(connectionChartRoot, {
        behavior: 'none',
        xAxis: dateAxis,
    }));
}

function connectionTimelineData(events) {
    return events.map(event => {
        const isConnected = event.type === 'device.connected';
        return {
            date: eventTime(event),
            value: 0,
            label: isConnected ? 'Ligado' : 'Desligado',
            bulletColor: isConnected ? '#198754' : '#dc3545',
        };
    }).filter(point => point.date > 0);
}

function expectedReplies(command) {
    return Array.isArray(command.expectedReplyTypes) && command.expectedReplyTypes.length
        ? `À espera de ${command.expectedReplyTypes.join(', ')}`
        : '';
}

async function requestTelemetryFeature(feature) {
    state.loadingCommands.add(feature);
    renderSelection();
    try {
        const result = await api.requestFeature(state.selectedImei, feature);
        if (result.error) alert(result.error.message || result.error.code);
        if (state.selectedImei) {
            await loadDevice(state.selectedImei);
        }
    } finally {
        state.loadingCommands.delete(feature);
        renderSelection();
    }
}

async function populateCompanySelect() {
    try {
        const data = await api.companies({limit: 500});
        const companies = data.data || [];
        els.deviceCompanySelect.innerHTML = '<option value="">Sem empresa</option>'
            + companies.map(s => `<option value="${esc(s.name)}">${esc(s.name)}</option>`).join('');
    } catch {
        els.deviceCompanySelect.innerHTML = '<option value="">Sem empresa</option>';
    }
}

async function populateLicenseSelectForCompany(companyName) {
    const select = els.deviceLicenseSelect;
    if (!companyName) {
        select.innerHTML = '<option value="0">Nenhuma</option>';
        select.disabled = true;
        els.deviceLicenseId.value = '0';
        return;
    }
    try {
        const data = await api.companies({limit: 500});
        const companyList = data.data || [];
        const company = companyList.find(s => s.name === companyName);
        if (!company) {
            select.innerHTML = '<option value="0">Nenhuma</option>';
            select.disabled = true;
            els.deviceLicenseId.value = '0';
            return;
        }
        const licData = await api.licenses({limit: 500, companyId: company.id});
        const licenses = licData.data || [];
        select.innerHTML = '<option value="0">Nenhuma</option>'
            + licenses.map(l => `<option value="${esc(l.license_id)}">${esc(l.license_id)}${l.name ? ` — ${esc(l.name)}` : ''}</option>`).join('');
        select.disabled = false;
    } catch {
        select.innerHTML = '<option value="0">Nenhuma</option>';
        select.disabled = true;
        els.deviceLicenseId.value = '0';
    }
}

function handleCompanySelect() {
    const companyName = els.deviceCompanySelect.value;
    els.deviceCompany.value = companyName || '';
    if (companyName) {
        void populateLicenseSelectForCompany(companyName);
    } else {
        els.deviceLicenseSelect.innerHTML = '<option value="0">Nenhuma</option>';
        els.deviceLicenseSelect.disabled = true;
        els.deviceLicenseId.value = '0';
    }
}

function handleLicenseSelect() {
    els.deviceLicenseId.value = els.deviceLicenseSelect.value || '0';
}

async function openAddDevice() {
    await Promise.all([ensureModelsLoaded(), ensureSuppliersLoaded()]);
    els.deviceModalLabel.textContent = 'Adicionar dispositivo';
    els.deviceForm.reset();
    delete els.deviceImei.dataset.originalImei;
    resetConfigUiState();
    state.deviceModal = {
        mode: 'create',
        activeTab: 'general',
        activeCategory: '',
        imei: '',
        originalImei: '',
        deviceType: 'watch',
        licenseId: '0',
        simNumber: '',
        deviceId: '',
        supplier: '',
        model: '',
        protocol: '',
        catalog: [],
        configurations: [],
        configUi: {},
        loading: false,
    };
    els.deviceConfigTabBtn?.classList.add('d-none');
    els.deviceConfigPane?.classList.remove('show', 'active');
    els.deviceGeneralTabBtn?.classList.add('active');
    els.deviceGeneralPane?.classList.add('show', 'active');
    els.deleteDeviceBtn.classList.add('d-none');
    renderDeviceSimNumberField('');
    renderDeviceTypeSelector('watch');
    await populateCompanySelect();
    els.deviceCompany.value = '';
    els.deviceLicenseSelect.innerHTML = '<option value="0">Nenhuma</option>';
    els.deviceLicenseSelect.disabled = true;
    els.deviceLicenseId.value = '0';
    els.deviceDeviceId.value = '';
    renderDeviceSelectors();
    deviceModal.show();
}

async function editDevice(imei, supplier, model) {
    await Promise.all([ensureModelsLoaded(), ensureSuppliersLoaded()]);
    els.deviceModalLabel.textContent = 'Editar dispositivo';
    els.deviceImei.value = imei;
    els.deviceImei.dataset.originalImei = imei;
    resetConfigUiState();
    state.deviceModal = {
        mode: 'edit',
        activeTab: 'general',
        activeCategory: '',
        imei,
        originalImei: imei,
        deviceType: 'watch',
        licenseId: '0',
        simNumber: '',
        deviceId: '',
        supplier,
        model,
        protocol: '',
        catalog: [],
        configurations: [],
        configUi: {},
        loading: true,
    };
    els.deviceConfigTabBtn?.classList.remove('d-none');
    els.deleteDeviceBtn.dataset.imei = imei;
    els.deleteDeviceBtn.classList.remove('d-none');
    renderDeviceTypeSelector('watch');
    await populateCompanySelect();
    els.deviceCompany.value = '';
    els.deviceLicenseSelect.innerHTML = '<option value="0">Nenhuma</option>';
    els.deviceLicenseSelect.disabled = true;
    els.deviceLicenseId.value = '0';
    renderDeviceSelectors(supplier, model);
    renderDeviceConfigurationModal();
    renderDeviceSimNumberField('');
    deviceModal.show();

    try {
        const detail = await api.device(imei);
        const device = detail.device || {};
        const deviceModel = detail.model;
        const deviceType = String(deviceModel?.deviceType || 'watch');
        const licenseId = String(device.licenseId || '0');
        const deviceCompany = String(device.company || '');
        renderDeviceTypeSelector(deviceType);
        renderDeviceSelectors(
            String(deviceModel?.supplier || supplier),
            String(deviceModel?.internalModel || model),
            deviceType
        );
        if (deviceCompany !== '' && deviceCompany !== 'null') {
            const optExists = [...els.deviceCompanySelect.options].some(o => o.value === deviceCompany);
            if (!optExists) {
                const opt = document.createElement('option');
                opt.value = deviceCompany;
                opt.textContent = deviceCompany;
                els.deviceCompanySelect.appendChild(opt);
            }
            els.deviceCompanySelect.value = deviceCompany;
            els.deviceCompany.value = deviceCompany;
            await populateLicenseSelectForCompany(deviceCompany);
            if (licenseId !== '0' && licenseId !== '') {
                const licOptExists = [...els.deviceLicenseSelect.options].some(o => o.value === licenseId);
                if (licOptExists) {
                    els.deviceLicenseSelect.value = licenseId;
                    els.deviceLicenseId.value = licenseId;
                }
            }
        }
        state.deviceModal.deviceType = normalizeDeviceType(deviceType);
        state.deviceModal.licenseId = licenseId;
        renderDeviceSimNumberField(String(device.simNumber || ''));
        state.deviceModal.simNumber = String(device.simNumber || '');
        els.deviceDeviceId.value = String(device.deviceId || '');
        applyFourPTouchDeviceIdUi();
        state.deviceModal.deviceId = String(device.deviceId || '');
        state.deviceModal.configurations = detail.configurations || {};
        await refreshDeviceModalConfigurations(false);
    } finally {
        state.deviceModal.loading = false;
        syncDeviceModalContext();
        renderDeviceConfigurationModal();
    }
}

function renderDeviceSelectors(selectedSupplier = '', selectedModel = '', deviceType = '') {
    const currentDeviceType = normalizeDeviceType(deviceType || els.deviceForm.dataset.deviceType || 'watch');
    const suppliers = suppliersForDeviceType(currentDeviceType);
    const supplier = suppliers.includes(selectedSupplier) ? selectedSupplier : (suppliers[0] || '');
    const models = modelsForSupplierAndType(supplier, currentDeviceType);
    const availableModelNames = models.map(model => modelInternalName(model));
    const model = availableModelNames.includes(selectedModel) ? selectedModel : (availableModelNames[0] || '');

    els.deviceForm.dataset.supplier = supplier;
    els.deviceForm.dataset.model = model;

    renderButtonGroup(els.deviceSupplierButtons, suppliers.map(value => ({value, label: value})), supplier, 'selectDeviceSupplier');
    renderButtonGroup(els.deviceModelButtons, models.map(entry => ({value: modelInternalName(entry), label: modelDisplayLabel(entry)})), model, 'selectDeviceModel');
    updateDevicePreview();
    syncDeviceModalContext();
    renderDeviceConfigurationModal();
}

function renderDeviceTypeSelector(selectedType = 'watch') {
    const deviceType = normalizeDeviceType(selectedType);
    els.deviceForm.dataset.deviceType = deviceType;
    renderButtonGroup(els.deviceTypeButtons, deviceTypeOptions, deviceType, 'selectDeviceType');

    const showImeiSim = deviceType === 'watch';
    const showDeviceId = deviceType !== 'watch';
    els.deviceImeiRow?.classList.toggle('d-none', !showImeiSim);
    els.deviceSimRow?.classList.toggle('d-none', !showImeiSim);
    els.deviceDeviceIdRow?.classList.toggle('d-none', !showDeviceId);

    if (deviceType === 'ncs') {
        els.deviceDeviceIdLabel.textContent = 'Device ID (MAC)';
        els.deviceDeviceIdHelp.textContent = 'MAC address do dispositivo NCS (ex.: bea6c3dd8e02). Obrigatório.';
        els.deviceDeviceId.placeholder = 'MAC address (ex.: bea6c3dd8e02)';
    } else if (deviceType === 'radar') {
        els.deviceDeviceIdLabel.textContent = 'Device ID';
        els.deviceDeviceIdHelp.textContent = 'Identificador do dispositivo radar no protocolo.';
        els.deviceDeviceId.placeholder = 'ID do dispositivo';
    } else {
        els.deviceDeviceIdLabel.textContent = 'Device ID';
        els.deviceDeviceIdHelp.textContent = 'Identificador do dispositivo no protocolo (IMEI, MAC, etc.).';
        els.deviceDeviceId.placeholder = 'ID do dispositivo no protocolo';
    }
}

function updateDevicePreview() {
    const supplier = els.deviceForm.dataset.supplier || '';
    const model = els.deviceForm.dataset.model || '';
    const modelInfo = findModelInfo(supplier, model);
    els.devicePreview.innerHTML = modelPreviewHtml(modelInfo, model || 'Selecione um modelo');
    applyFourPTouchDeviceIdUi();
    syncDeviceModalContext();
}

function syncDeviceModalContext() {
    const supplier = els.deviceForm.dataset.supplier || '';
    const model = els.deviceForm.dataset.model || '';
    const protocol = supplierProtocol(supplier, state.summary.models);
    state.deviceModal.supplier = supplier;
    state.deviceModal.model = model;
    state.deviceModal.protocol = protocol;
    state.deviceModal.catalog = catalogForProtocol(protocol);
    state.deviceModal.imei = els.deviceImei.value.trim();
    state.deviceModal.deviceType = normalizeDeviceType(els.deviceForm.dataset.deviceType || 'watch');
    state.deviceModal.licenseId = els.deviceLicenseId.value.trim() || '0';
    state.deviceModal.simNumber = getDeviceSimNumberValue(false);
    state.deviceModal.deviceId = els.deviceDeviceId?.value.trim() || '';
    if (!state.deviceModal.activeCategory || !state.deviceModal.catalog.some(entry => entry.category === state.deviceModal.activeCategory)) {
        state.deviceModal.activeCategory = state.deviceModal.catalog[0]?.category || '';
    }
}

function applyFourPTouchDeviceIdUi() {
    if (!els.deviceDeviceId) {
        return;
    }

    const isFourPTouch = isFourPTouchSelection();
    if (isFourPTouch) {
        const derived = deriveFourPTouchDeviceId(els.deviceImei.value.trim());
        els.deviceDeviceId.value = derived;
        els.deviceDeviceId.readOnly = true;
        els.deviceDeviceIdLabel.textContent = 'Device ID';
        els.deviceDeviceIdHelp.textContent = 'Derivado automaticamente do IMEI para 4P Touch.';
        els.deviceDeviceId.placeholder = 'Derivado do IMEI';
    } else {
        els.deviceDeviceId.readOnly = false;
    }
}

function renderDeviceConfigurationModal() {
    if (!els.deviceConfigRoot) {
        return;
    }

    if (state.deviceModal.loading) {
        els.deviceConfigRoot.innerHTML = emptyPanel('A carregar configurações...');
        return;
    }

    if (!state.deviceModal.imei) {
        els.deviceConfigRoot.innerHTML = emptyPanel('Preencha o IMEI para gerir as configurações.');
        return;
    }

    els.deviceConfigRoot.innerHTML = renderDeviceConfigurationRoot({
        protocol: state.deviceModal.protocol,
        catalog: state.deviceModal.catalog,
        configurations: state.deviceModal.configurations,
        uiByKey: state.deviceModal.configUi,
        supplier: state.deviceModal.supplier,
        model: state.deviceModal.model,
        activeCategory: state.deviceModal.activeCategory,
        disabled: !state.deviceModal.protocol,
    });
    resetPhoneControls(els.deviceConfigRoot);
    armConfigFeedbackAutoClose();
}

async function saveDevice() {
    let imei = els.deviceImei.value.trim();
    let simNumber = '';
    const deviceType = normalizeDeviceType(els.deviceForm.dataset.deviceType || 'watch');
    const licenseId = els.deviceLicenseId.value.trim();
    const supplier = els.deviceForm.dataset.supplier || '';
    const model = els.deviceForm.dataset.model || '';
    const deviceId = isFourPTouchSelection(supplier, model)
        ? deriveFourPTouchDeviceId(imei)
        : els.deviceDeviceId.value.trim();

    if (deviceType === 'ncs' || deviceType === 'radar') {
        if (!deviceId || !supplier || !model) { alert('Device ID, fornecedor e modelo são obrigatórios'); return; }
        imei = deviceId;
        simNumber = '';
    } else {
        try {
            simNumber = getDeviceSimNumberValue(true);
        } catch (error) {
            alert(error instanceof Error ? error.message : 'Número do SIM inválido');
            return;
        }
        if (!imei || !supplier || !model) { alert('IMEI, fornecedor e modelo são obrigatórios'); return; }
        if (isFourPTouchSelection(supplier, model) && !deviceId) { alert('IMEI 4P Touch inválido'); return; }
    }

    const originalImei = els.deviceImei.dataset.originalImei || '';
    const company = els.deviceCompany.value || 'null';
    if (deviceType !== 'watch' && (licenseId === '' || licenseId === '0')) { alert('É necessário selecionar uma licença para este tipo de dispositivo'); return; }

    const result = await api.saveDevice(imei, supplier, model, deviceType, licenseId, simNumber, deviceId, originalImei, company);
    if (result.error) { alert(result.error.message || result.error.code); return; }

    if (state.selectedImei && originalImei && state.selectedImei === originalImei) {
        selectImei(imei);
        saveSelectedDeviceToStorage();
        await loadDevice(imei);
    }
    deviceModal.hide();
    if (isDeviceSelectorOpen()) {
        await loadSummary();
    }
}

async function deleteDevice(imei) {
    if (!confirm(`Apagar o dispositivo ${imei}?`)) return;
    await api.deleteDevice(imei);
    if (state.selectedImei === imei) {
        disconnectDeviceStream();
        clearSelection();
        clearSelectedDeviceFromStorage();
    }
    if (isDeviceSelectorOpen()) {
        await loadSummary();
    } else {
        renderSelection();
    }
}

function handleDeleteDeviceBtnClick() {
    const imei = els.deleteDeviceBtn.dataset.imei;
    if (!imei) return;
    if (!confirm(`Apagar o dispositivo ${imei}?`)) return;
    api.deleteDevice(imei).then(() => {
        deviceModal.hide();
        if (state.selectedImei === imei) {
            disconnectDeviceStream();
            clearSelection();
            clearSelectedDeviceFromStorage();
        }
        if (isDeviceSelectorOpen()) {
            loadSummary();
        } else {
            renderSelection();
        }
    });
}

async function loadSettingsModal(section = state.settingsModal.section || 'suppliers') {
    state.settingsModal.sectionLoaded = {
        suppliers: false,
        models: false,
        capabilities: false,
        company: false,
        apiUsers: false,
    };
    state.settingsModal.suppliersPagination = null;
    state.settingsModal.modelsPagination = null;
    state.settingsModal.companyPagination = null;
    state.settingsModal.licensesPagination = null;
    state.settingsModal.apiUsersPagination = null;
    state.settingsModal.capabilitySupplier = '';
    state.settingsModal.capabilityModelId = null;
    state.settingsModal.capabilityEnabledRequests = [];
    activateSettingsSection(section);
    settingsModal.show();
    if (section === 'suppliers') {
        void loadSettingsSuppliersSection();
    } else if (section === 'models') {
        void loadSettingsModelsSection();
    } else if (section === 'capabilities') {
        void loadSettingsCapabilitiesSection();
    } else if (section === 'company') {
        void loadSettingsCompanySection();
    } else if (section === 'apiUsers') {
        void loadSettingsApiUsersSection();
    }
}

function renderSettingsPagination(pagination, rootEl, summaryEl, controlsEl, action) {
    const total = pagination?.total ?? 0;
    const totalPages = pagination?.total_pages ?? 1;
    const currentPage = pagination?.page ?? 1;
    const limit = pagination?.limit ?? 20;

    if (totalPages <= 1) {
        rootEl.classList.add('d-none');
        summaryEl.textContent = '';
        controlsEl.innerHTML = '';
        return;
    }

    const pageStart = ((currentPage - 1) * limit) + 1;
    const pageEnd = Math.min(total, currentPage * limit);
    rootEl.classList.remove('d-none');
    summaryEl.textContent = `A mostrar de ${pageStart} até ${pageEnd} | ${total}`;
    controlsEl.innerHTML = [
        `<button type="button" class="btn btn-outline-secondary btn-sm" data-action="${esc(action)}Prev" ${currentPage <= 1 ? 'disabled' : ''} aria-label="Página anterior"><i class="fa-solid fa-chevron-left"></i></button>`,
        ...Array.from({length: totalPages}, (_, index) => {
            const page = index + 1;
            return `<button type="button" class="btn ${page === currentPage ? 'btn-primary' : 'btn-outline-secondary'} btn-sm" data-action="${esc(action)}Go" data-page="${page}" ${page === currentPage ? 'aria-current="page"' : ''}>${page}</button>`;
        }),
        `<button type="button" class="btn btn-outline-secondary btn-sm" data-action="${esc(action)}Next" ${currentPage >= totalPages ? 'disabled' : ''} aria-label="Página seguinte"><i class="fa-solid fa-chevron-right"></i></button>`,
    ].join('');
}

function handleSettingsPaginationClick(event, paginationKey, loadFn) {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const action = button.dataset.action;
    const pagination = state.settingsModal[paginationKey];
    const currentPage = pagination?.page ?? 1;
    const totalPages = pagination?.total_pages ?? 1;
    let nextPage;
    if (action.endsWith('Prev')) nextPage = Math.max(1, currentPage - 1);
    else if (action.endsWith('Next')) nextPage = Math.min(totalPages, currentPage + 1);
    else if (action.endsWith('Go')) nextPage = Math.min(Math.max(1, parseInt(button.dataset.page || '1', 10) || 1), totalPages);
    else return;
    void loadFn(nextPage);
}

async function loadSettingsSuppliersSection(page = 1) {
    const response = await api.suppliers({page});
    const suppliers = response.data || [];
    state.settingsModal.suppliersPagination = response.pagination || null;
    state.modelModalSuppliers = suppliers;
    state.settingsModal.sectionLoaded.suppliers = true;
    renderSuppliersSection(suppliers);
    renderSettingsPagination(
        state.settingsModal.suppliersPagination,
        els.settingsSuppliersPagination,
        els.settingsSuppliersPaginationSummary,
        els.settingsSuppliersPaginationControls,
        'settingsSuppliersPage'
    );
}

async function loadSettingsModelsSection(page = 1) {
    if (!state.settingsModal.sectionLoaded.suppliers) {
        await loadSettingsSuppliersSection();
    }
    const response = await api.models({page});
    const models = response.data || [];
    state.settingsModal.modelsPagination = response.pagination || null;
    state.summary.models = models;
    state.settingsModal.sectionLoaded.models = true;
    renderModelsSection(models);
    renderSettingsPagination(
        state.settingsModal.modelsPagination,
        els.settingsModelsPagination,
        els.settingsModelsPaginationSummary,
        els.settingsModelsPaginationControls,
        'settingsModelsPage'
    );
}

async function loadSettingsCapabilitiesSection() {
    if (!state.settingsModal.sectionLoaded.capabilities) {
        const response = await api.models();
        state.summary.models = response.data || [];
        state.settingsModal.sectionLoaded.capabilities = true;
    }
    syncCapabilitiesSelection();
    renderCapabilitiesSection();
}

async function loadSettingsApiUsersSection(page = 1) {
    const response = await api.apiUsers({page});
    const users = response.data || [];
    state.settingsModal.apiUsersPagination = response.pagination || null;
    state.settingsModal.sectionLoaded.apiUsers = true;
    renderApiUsersSection(users);
    renderSettingsPagination(
        state.settingsModal.apiUsersPagination,
        els.settingsApiUsersPagination,
        els.settingsApiUsersPaginationSummary,
        els.settingsApiUsersPaginationControls,
        'settingsApiUsersPage'
    );
}

function renderSuppliersSection(suppliers) {
    els.supplierListBody.innerHTML = (suppliers || []).map(supplier => `
        <tr>
        <td>${esc(supplier.name)}</td>
        <td>${supplier.model_count}</td>
        <td><span class="badge ${supplier.enabled ? 'text-bg-success' : 'text-bg-secondary'}">${supplier.enabled ? 'ativo' : 'inativo'}</span></td>
        <td>
        <button class="btn btn-outline-${supplier.enabled ? 'warning' : 'success'} btn-sm" data-id="${supplier.id}" data-enabled="${supplier.enabled ? '1' : ''}" data-action="toggleSupplier" title="${supplier.enabled ? 'Desativar' : 'Ativar'}"><i class="fa-solid fa-${supplier.enabled ? 'pause' : 'play'}"></i></button>
        </td>
        </tr>`).join('');
}

async function toggleSupplier(id, enabled) {
    const result = await api.updateSupplier(id, {enabled: !enabled});
    if (result.error) { alert(result.error.message || result.error.code); return; }
    state.settingsModal.sectionLoaded.suppliers = false;
    await loadSettingsSuppliersSection();
}

function renderModelsSection(models) {
    resetModelForm();
    els.modelListBody.innerHTML = (models || []).map(model => `
        <tr>
        <td>${modelImageHtml(model)}</td>
        <td>${esc(model.supplier)}</td>
        <td>${esc(modelCommercialName(model))}</td>
        <td>${esc(modelInternalName(model))}</td>
        <td>${esc(deviceTypeLabel(modelDeviceType(model)))}</td>
        <td>
        <button class="btn btn-outline-secondary btn-sm" data-id="${model.id}" data-supplier-id="${model.supplier_id}" data-supplier="${esc(model.supplier)}" data-internal-model="${esc(modelInternalName(model))}" data-commercial-name="${esc(modelCommercialName(model))}" data-device-type="${esc(modelDeviceType(model))}" data-image="${esc(model.image || '')}" data-action="editModel" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${model.id}" data-action="deleteModel" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`).join('');
}

function resetModelForm(selectedSupplierId = '') {
    revokeModelPreviewUrl();
    els.modelForm.reset();
    delete els.modelForm.dataset.modelId;
    delete els.modelForm.dataset.image;
    els.modelForm.dataset.deviceType = 'watch';
    els.saveModelBtn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar';
    els.modelImage.value = '';

    renderButtonGroup(els.modelDeviceTypeButtons, deviceTypeOptions, 'watch', 'selectModelDeviceType');

    const suppliers = state.modelModalSuppliers.map(supplier => ({value: String(supplier.id), label: supplier.name}));
    const supplierId = suppliers.some(supplier => supplier.value === String(selectedSupplierId))
        ? String(selectedSupplierId)
        : (suppliers[0]?.value || '');
    const supplier = state.modelModalSuppliers.find(entry => String(entry.id) === supplierId);
    els.modelForm.dataset.supplierId = supplierId;
    els.modelForm.dataset.supplier = supplier?.name || '';

    renderButtonGroup(els.modelSupplierButtons, suppliers, supplierId, 'selectModelSupplier');
    updateModelProtocolAndPreview();
}

function editModel(id, supplierId, supplier, internalModel, commercialName, deviceType, image) {
    revokeModelPreviewUrl();
    els.modelForm.dataset.modelId = String(id);
    els.modelForm.dataset.supplierId = String(supplierId);
    els.modelForm.dataset.supplier = supplier;
    els.modelForm.dataset.image = image || '';
    els.modelForm.dataset.deviceType = normalizeDeviceType(deviceType);
    els.modelInternalModel.value = internalModel;
    els.modelCommercialName.value = commercialName;
    els.modelImage.value = '';
    els.saveModelBtn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar';
    renderButtonGroup(els.modelDeviceTypeButtons, deviceTypeOptions, els.modelForm.dataset.deviceType, 'selectModelDeviceType');
    renderButtonGroup(
        els.modelSupplierButtons,
        state.modelModalSuppliers.map(entry => ({value: String(entry.id), label: entry.name})),
        String(supplierId),
        'selectModelSupplier'
    );
    updateModelProtocolAndPreview();
}

function selectModelSupplier(supplierId) {
    revokeModelPreviewUrl();
    els.modelImage.value = '';
    const supplier = state.modelModalSuppliers.find(entry => String(entry.id) === String(supplierId));
    els.modelForm.dataset.supplierId = String(supplierId);
    els.modelForm.dataset.supplier = supplier?.name || '';
    delete els.modelForm.dataset.image;
    renderButtonGroup(
        els.modelSupplierButtons,
        state.modelModalSuppliers.map(entry => ({value: String(entry.id), label: entry.name})),
        String(supplierId),
        'selectModelSupplier'
    );
    updateModelProtocolAndPreview();
}

function selectModelDeviceType(deviceType) {
    els.modelForm.dataset.deviceType = normalizeDeviceType(deviceType);
    renderButtonGroup(els.modelDeviceTypeButtons, deviceTypeOptions, els.modelForm.dataset.deviceType, 'selectModelDeviceType');
}

function updateModelProtocolAndPreview() {
    const supplier = els.modelForm.dataset.supplier || '';
    const internalModel = els.modelInternalModel.value.trim();
    const commercialName = els.modelCommercialName.value.trim();
    const image = els.modelForm.dataset.image || '';
    const modelInfo = image ? {image, internal_model: internalModel || '', commercial_name: commercialName || internalModel || 'Modelo'} : null;
    if (!state.modelPreviewObjectUrl) {
        const label = commercialName || internalModel || supplier || 'Novo modelo';
        els.modelPreviewContent.innerHTML = modelInfo?.image
            ? `<img src="${esc(modelInfo.image)}" class="object-fit-contain w-100 h-100" alt="${esc(label)}" style="max-height:180px;">`
            : `<i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${esc(label)}</div>`;
    }
}

async function saveModel() {
    const supplierId = parseInt(els.modelForm.dataset.supplierId || '0');
    const internalModel = els.modelInternalModel.value.trim();
    const commercialName = els.modelCommercialName.value.trim();
    const deviceType = normalizeDeviceType(els.modelForm.dataset.deviceType || 'watch');
    if (!supplierId || !internalModel || !commercialName) { alert('Fornecedor, modelo interno e nome comercial são obrigatórios'); return; }

    const body = new FormData();
    body.append('supplier_id', String(supplierId));
    body.append('internalModel', internalModel);
    body.append('commercialName', commercialName);
    body.append('deviceType', deviceType);
    if (els.modelImage.files[0]) {
        body.append('image', els.modelImage.files[0]);
    }

    const result = await api.saveModel(els.modelForm.dataset.modelId || '', body);
    if (result.error) { alert(result.error.message || result.error.code); return; }

    state.settingsModal.sectionLoaded.models = false;
    state.settingsModal.sectionLoaded.capabilities = false;
    await loadSettingsModelsSection();
}

async function deleteModel(id) {
    if (!confirm('Apagar modelo?')) return;
    await api.deleteModel(id);
    state.settingsModal.sectionLoaded.models = false;
    state.settingsModal.sectionLoaded.capabilities = false;
    await loadSettingsModelsSection();
}

function renderApiUsersSection(users) {
    resetApiUserForm();
    els.apiUserListBody.innerHTML = (users || []).map(user => `
        <tr>
        <td>${esc(user.username)}</td>
        <td><span class="badge text-bg-light border">${esc(apiRoleLabel(user.role))}</span></td>
        <td>${user.role === 'hub_admin' ? '<span class="text-secondary">Todas</span>' : esc(user.license_id || '-')}</td>
        <td><span class="badge ${Number(user.enabled) === 1 ? 'text-bg-success' : 'text-bg-secondary'}">${Number(user.enabled) === 1 ? 'ativo' : 'inativo'}</span></td>
        <td>
        <button class="btn btn-outline-secondary btn-sm" data-action="editApiUser" data-id="${user.id}" data-username="${esc(user.username)}" data-role="${esc(user.role)}" data-license-id="${esc(user.license_id || '')}" data-enabled="${Number(user.enabled) === 1 ? '1' : ''}" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-${Number(user.enabled) === 1 ? 'warning' : 'success'} btn-sm" data-action="toggleApiUser" data-id="${user.id}" data-username="${esc(user.username)}" data-role="${esc(user.role)}" data-license-id="${esc(user.license_id || '')}" data-enabled="${Number(user.enabled) === 1 ? '1' : ''}" title="${Number(user.enabled) === 1 ? 'Desativar' : 'Ativar'}"><i class="fa-solid fa-${Number(user.enabled) === 1 ? 'pause' : 'play'}"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${user.id}" data-action="deleteApiUser" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`).join('');
}

function resetApiUserForm() {
    els.apiUserForm.reset();
    els.apiUserId.value = '';
    els.apiUserRole.value = 'license_client';
    els.apiUserEnabled.checked = true;
    els.apiUserPassword.placeholder = 'Obrigatória para novo utilizador';
    syncApiUserRoleFields();
}

function editApiUser(button) {
    els.apiUserId.value = button.dataset.id || '';
    els.apiUsername.value = button.dataset.username || '';
    els.apiUserRole.value = button.dataset.role || 'license_client';
    els.apiUserLicenseId.value = button.dataset.licenseId || '';
    els.apiUserEnabled.checked = !!button.dataset.enabled;
    els.apiUserPassword.value = '';
    els.apiUserPassword.placeholder = 'Deixar vazio para manter';
    syncApiUserRoleFields();
}

function syncApiUserRoleFields() {
    const isAdmin = els.apiUserRole.value === 'hub_admin';
    els.apiUserLicenseId.disabled = isAdmin;
    if (isAdmin) {
        els.apiUserLicenseId.value = '';
    }
}

async function saveApiUser() {
    const id = els.apiUserId.value.trim();
    const body = {
        username: els.apiUsername.value.trim(),
        password: els.apiUserPassword.value,
        role: els.apiUserRole.value,
        licenseId: els.apiUserLicenseId.value.trim(),
        enabled: els.apiUserEnabled.checked,
    };
    if (!body.username) { alert('Utilizador é obrigatório'); return; }
    if (!id && !body.password.trim()) { alert('Password é obrigatória para novo utilizador'); return; }
    if (body.role === 'license_client' && !body.licenseId) { alert('Licença é obrigatória para clientes'); return; }

    const result = await api.saveApiUser(id, body);
    if (result.error) { alert(result.error.message || result.error.code); return; }

    state.settingsModal.sectionLoaded.apiUsers = false;
    await loadSettingsApiUsersSection();
}

async function toggleApiUser(button) {
    const result = await api.saveApiUser(button.dataset.id, {
        username: button.dataset.username || '',
        role: button.dataset.role || 'license_client',
        licenseId: button.dataset.licenseId || '',
        enabled: !button.dataset.enabled,
    });
    if (result.error) { alert(result.error.message || result.error.code); return; }
    state.settingsModal.sectionLoaded.apiUsers = false;
    await loadSettingsApiUsersSection();
}

async function deleteApiUser(id) {
    if (!confirm('Apagar utilizador API?')) return;
    const result = await api.deleteApiUser(id);
    if (result.error) { alert(result.error.message || result.error.code); return; }
    state.settingsModal.sectionLoaded.apiUsers = false;
    await loadSettingsApiUsersSection();
}

function renderCompanySection(companies) {
    resetCompanyForm();
    els.companyListBody.innerHTML = (companies || []).map(item => `
        <tr>
        <td>${esc(item.name)}</td>
        <td>${item.license_count ?? 0}</td>
        <td>
        <button class="btn btn-outline-secondary btn-sm" data-action="editCompany" data-id="${item.id}" data-name="${esc(item.name)}" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${item.id}" data-action="deleteCompany" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`).join('');
}

function resetCompanyForm() {
    els.companyForm.reset();
    els.companyId.value = '';
}

function editCompany(button) {
    els.companyId.value = button.dataset.id || '';
    els.companyName.value = button.dataset.name || '';
}

async function saveCompany() {
    const id = els.companyId.value.trim();
    const name = els.companyName.value.trim();
    if (!name) { alert('O nome é obrigatório'); return; }
    const result = await api.saveCompany(id, name);
    if (result.error) { alert(result.error.message || result.error.code); return; }
    state.settingsModal.sectionLoaded.company = false;
    await loadSettingsCompanySection();
}

async function deleteCompany(id) {
    if (!confirm('Apagar empresa? Todas as licenças associadas serão apagadas.')) return;
    const result = await api.deleteCompany(id);
    if (result.error) { alert(result.error.message || result.error.code); return; }
    state.settingsModal.sectionLoaded.company = false;
    await loadSettingsCompanySection();
}

function renderLicensesSection(licenses, companies) {
    resetLicenseForm();
    const companyOptions = (companies || []).map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
    els.licenseCompanySelect.innerHTML = '<option value="">Selecionar empresa</option>' + companyOptions;
    els.licenseListBody.innerHTML = (licenses || []).map(item => `
        <tr>
        <td>${esc(item.company_name || '-')}</td>
        <td>${esc(item.license_id)}</td>
        <td>${esc(item.name || '-')}</td>
        <td>
        <button class="btn btn-outline-secondary btn-sm" data-action="editLicense" data-id="${item.id}" data-company-id="${item.company_id}" data-company-name="${esc(item.company_name || '')}" data-license-id="${esc(item.license_id)}" data-name="${esc(item.name || '')}" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${item.id}" data-action="deleteLicense" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`).join('');
}

function resetLicenseForm() {
    els.licenseForm.reset();
    els.licenseId.value = '';
}

function editLicense(button) {
    els.licenseId.value = button.dataset.id || '';
    els.licenseCompanySelect.value = button.dataset.companyId || '';
    els.licenseLicenseId.value = button.dataset.licenseId || '';
    els.licenseName.value = button.dataset.name || '';
}

async function saveLicense() {
    const id = els.licenseId.value.trim();
    const companyId = els.licenseCompanySelect.value;
    const licenseId = els.licenseLicenseId.value.trim();
    const name = els.licenseName.value.trim();
    if (!companyId) { alert('Selecione uma empresa'); return; }
    if (!licenseId) { alert('O ID da licença é obrigatório'); return; }
    const body = {companyId: Number(companyId), licenseId, name};
    const result = await api.saveLicense(id, body);
    if (result.error) { alert(result.error.message || result.error.code); return; }
    state.settingsModal.sectionLoaded.company = false;
    await loadSettingsCompanySection();
}

async function deleteLicense(id) {
    if (!confirm('Apagar licença?')) return;
    const result = await api.deleteLicense(id);
    if (result.error) { alert(result.error.message || result.error.code); return; }
    state.settingsModal.sectionLoaded.company = false;
    await loadSettingsCompanySection();
}

async function loadSettingsCompanySection(companiesPage = 1, licensesPage = 1) {
    const [companyData, licensesData] = await Promise.all([
        api.companies({page: companiesPage}),
        api.licenses({page: licensesPage}),
    ]);
    const companies = companyData.data || [];
    const licenses = licensesData.data || [];
    state.settingsModal.sectionLoaded.company = true;
    state.settingsModal.companyPagination = companyData.pagination || null;
    state.settingsModal.licensesPagination = licensesData.pagination || null;
    renderCompanySection(companies);
    renderLicensesSection(licenses, companies);
    renderSettingsPagination(
        state.settingsModal.companyPagination,
        els.settingsCompanyPagination,
        els.settingsCompanyPaginationSummary,
        els.settingsCompanyPaginationControls,
        'settingsCompanyPage'
    );
    renderSettingsPagination(
        state.settingsModal.licensesPagination,
        els.settingsLicensesPagination,
        els.settingsLicensesPaginationSummary,
        els.settingsLicensesPaginationControls,
        'settingsLicensesPage'
    );
}

function handleCompanyListClick(event) {
    const button = event.target.closest('button');
    if (!button) return;
    if (button.dataset.action === 'editCompany') {
        editCompany(button);
    } else if (button.dataset.action === 'deleteCompany') {
        void deleteCompany(Number(button.dataset.id));
    }
}

function handleLicenseListClick(event) {
    const button = event.target.closest('button');
    if (!button) return;
    if (button.dataset.action === 'editLicense') {
        editLicense(button);
    } else if (button.dataset.action === 'deleteLicense') {
        void deleteLicense(Number(button.dataset.id));
    }
}

function activateSettingsSection(section) {
    state.settingsModal.section = section;
    const button = {
        suppliers: els.settingsSuppliersTabBtn,
        models: els.settingsModelsTabBtn,
        capabilities: els.settingsCapabilitiesTabBtn,
        company: els.settingsCompanyTabBtn,
        apiUsers: els.settingsApiUsersTabBtn,
    }[section] || els.settingsSuppliersTabBtn;
    bootstrap.Tab.getOrCreateInstance(button).show();
}

function syncCapabilitiesSelection() {
    const deviceType = state.settingsModal.capabilityDeviceType || '';
    const models = state.summary.models.filter(model => !deviceType || modelDeviceType(model) === deviceType);

    let currentSupplier = state.settingsModal.capabilitySupplier || '';
    const availableSuppliers = [...new Set(models.map(model => model.supplier).filter(Boolean))];
    if (!availableSuppliers.includes(currentSupplier)) {
        currentSupplier = availableSuppliers[0] || '';
    }
    state.settingsModal.capabilitySupplier = currentSupplier;

    const supplierModels = modelsForCapabilitySupplier(currentSupplier).filter(m => !deviceType || modelDeviceType(m) === deviceType);
    const currentModel = supplierModels.find(model => Number(model.id) === Number(state.settingsModal.capabilityModelId))
        || supplierModels[0]
        || null;

    state.settingsModal.capabilityModelId = currentModel ? Number(currentModel.id) : null;
    state.settingsModal.capabilityEnabledCapabilities = flattenedCapabilityKeys(currentModel?.capabilities || {});
}

let capabilityDebounceTimer = null;

function renderCapabilitiesSection() {
    const supplier = state.settingsModal.capabilitySupplier || '';
    const deviceType = state.settingsModal.capabilityDeviceType || '';
    const filteredModels = (state.summary.models || []).filter(model =>
        (!deviceType || modelDeviceType(model) === deviceType) &&
        (!supplier || model.supplier === supplier)
    );
    const selectedModel = filteredModels.find(model => Number(model.id) === Number(state.settingsModal.capabilityModelId)) || null;
    const enabled = new Set(state.settingsModal.capabilityEnabledCapabilities || []);

    renderButtonGroup(
        els.capabilityDeviceTypeButtons,
        deviceTypeOptions.map(entry => ({value: entry.value, label: entry.label})),
        deviceType || 'watch',
        'selectCapabilityDeviceType'
    );

    const suppliers = suppliersFromModels(state.summary.models).filter(s => {
        if (!deviceType) return true;
        return state.summary.models.some(m => m.supplier === s && modelDeviceType(m) === deviceType);
    });
    renderButtonGroup(
        els.capabilitySupplierButtons,
        suppliers.map(entry => ({value: entry, label: entry})),
        supplier,
        'selectCapabilitySupplier'
    );
    renderButtonGroup(
        els.capabilityModelButtons,
        filteredModels.map(entry => ({value: String(entry.id), label: modelDisplayLabel(entry)})),
        selectedModel ? String(selectedModel.id) : '',
        'selectCapabilityModel'
    );

    if (selectedModel) {
        const label = modelCommercialName(selectedModel);
        els.capabilityModelPreview.innerHTML = modelImageHtml(selectedModel)
            ? modelImageHtml(selectedModel).replace('style="width:40px;height:40px;"', 'style="max-height:100px;" class="object-fit-contain"')
            : `<div class="text-center text-secondary w-100"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${esc(label)}</div></div>`;
        els.capabilityModelName.textContent = label;
    } else {
        els.capabilityModelPreview.innerHTML = `<div class="text-center text-secondary w-100"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">Modelo</div></div>`;
        els.capabilityModelName.textContent = 'Modelo';
    }

    const capabilities = selectedModel?.capabilities && typeof selectedModel.capabilities === 'object'
        ? selectedModel.capabilities
        : {};
    els.capabilitySelectionEmpty.classList.toggle('d-none', !!selectedModel);
    els.capabilityEditor.classList.toggle('d-none', !selectedModel);
    if (!selectedModel) {
        els.capabilityGroups.innerHTML = '';
        els.capabilitySummary.textContent = '';
        return;
    }

    els.capabilityTitle.textContent = modelCommercialName(selectedModel);
    els.capabilitySubtitle.textContent = String(selectedModel.supplier || '');
    const totalCapabilities = Object.values(capabilities).reduce((count, entries) => count + Object.keys(entries || {}).length, 0);
    els.capabilitySummary.textContent = `${enabled.size}/${totalCapabilities} ativos`;

    els.capabilityGroups.innerHTML = Object.entries(capabilitySectionLabels).map(([section, label]) => {
        const entries = Object.keys(capabilities[section] || {});
        if (entries.length === 0) {
            return '';
        }

        return `
        <section class="border rounded bg-body-tertiary p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="h6 mb-0">${esc(label)}</h3>
                <span class="small text-secondary">${entries.filter(f => enabled.has(f)).length}/${entries.length} ativos</span>
            </div>
            <div class="d-flex flex-column gap-2">
                ${entries.map(feature => `
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" data-action="toggleCapabilityRequest" data-feature="${esc(feature)}" id="cap-${esc(feature)}" ${enabled.has(feature) ? 'checked' : ''}>
                            <label class="form-check-label" for="cap-${esc(feature)}">${esc(capabilityLabelByKey(feature))}</label>
                        </div>`
                ).join('')}
            </div>
        </section>`;
    }).join('');
}

function selectCapabilityDeviceType(deviceType) {
    clearTimeout(capabilityDebounceTimer);
    capabilityDebounceTimer = setTimeout(() => {
        state.settingsModal.capabilityDeviceType = deviceType;
        state.settingsModal.capabilitySupplier = '';
        state.settingsModal.capabilityModelId = null;
        syncCapabilitiesSelection();
        renderCapabilitiesSection();
    }, 150);
}

function selectCapabilitySupplier(supplier) {
    state.settingsModal.capabilitySupplier = supplier;
    state.settingsModal.capabilityModelId = null;
    syncCapabilitiesSelection();
    renderCapabilitiesSection();
}

function selectCapabilityModel(modelId) {
    state.settingsModal.capabilityModelId = Number(modelId);
    syncCapabilitiesSelection();
    renderCapabilitiesSection();
}

async function saveCapabilities() {
    const model = state.summary.models.find(entry => Number(entry.id) === Number(state.settingsModal.capabilityModelId)) || null;
    if (!model) {
        alert('Selecione um modelo');
        return;
    }

    const body = new FormData();
    body.append('supplier_id', String(model.supplier_id));
    body.append('internalModel', String(modelInternalName(model)));
    body.append('commercialName', String(modelCommercialName(model)));
    body.append('deviceType', String(modelDeviceType(model)));
    body.append('protocol', String(model.protocol || ''));
    body.append('capabilitiesConfigured', '1');
    for (const feature of state.settingsModal.capabilityEnabledCapabilities || []) {
        body.append('capabilities[]', String(feature));
    }

    const result = await api.saveModel(model.id, body);
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }

    state.settingsModal.sectionLoaded.models = false;
    state.settingsModal.sectionLoaded.capabilities = false;
    await loadSettingsCapabilitiesSection();
}

function revokeModelPreviewUrl() {
    if (state.modelPreviewObjectUrl) {
        URL.revokeObjectURL(state.modelPreviewObjectUrl);
        state.modelPreviewObjectUrl = null;
    }
}

function cacheElements() {
    els = {
        deviceColumn: document.getElementById('deviceColumn'),
        deviceList: document.getElementById('deviceList'),
        deviceListLimit: document.getElementById('deviceListLimit'),
        deviceListSearch: document.getElementById('deviceListSearch'),
        deviceListPagination: document.getElementById('deviceListPagination'),
        deviceListPaginationSummary: document.getElementById('deviceListPaginationSummary'),
        deviceListPaginationControls: document.getElementById('deviceListPaginationControls'),
        detailColumn: document.getElementById('detailColumn'),
        deviceSelectionEmptyState: document.getElementById('deviceSelectionEmptyState'),
        selectedDevicePanel: document.getElementById('selectedDevicePanel'),
        selectedDevicePreview: document.getElementById('selectedDevicePreview'),
        selectedDeviceTitle: document.getElementById('selectedDeviceTitle'),
        selectedDeviceMeta: document.getElementById('selectedDeviceMeta'),
        selectedDeviceBadge: document.getElementById('selectedDeviceBadge'),
        selectedDeviceEditBtn: document.getElementById('selectedDeviceEditBtn'),
        selectedDeviceFacts: document.getElementById('selectedDeviceFacts'),
        detailEmptyState: document.getElementById('detailEmptyState'),
        deviceDetail: document.getElementById('deviceDetail'),
        telemetryCount: document.getElementById('telemetryCount'),
        telemetryList: document.getElementById('telemetryList'),
        telemetryPager: document.getElementById('telemetry'),
        telemetryPagerSummary: document.getElementById('telemetrySummary'),
        telemetryPagerControls: document.getElementById('telemetryControls'),
        requestCardCount: document.getElementById('requestCardCount'),
        requestGrid: document.getElementById('requestGrid'),
        downlinkRequests: document.getElementById('downlinkRequests'),
        connectionTimeline: document.getElementById('connectionTimeline'),
        detailFiltersPanel: document.getElementById('detailFiltersPanel'),
        detailFilterFrom: document.getElementById('detailFilterFrom'),
        detailFilterTo: document.getElementById('detailFilterTo'),
        detailFilterType: document.getElementById('detailFilterType'),
        applyDetailFiltersBtn: document.getElementById('applyDetailFiltersBtn'),
        clearDetailFiltersBtn: document.getElementById('clearDetailFiltersBtn'),
        addDeviceBtn: document.getElementById('addDeviceBtn'),
        openDeviceSelectorBtn: document.getElementById('openDeviceSelectorBtn'),
        emptyStateSelectDeviceBtn: document.getElementById('emptyStateSelectDeviceBtn'),
        openAddDeviceFromSelectorBtn: document.getElementById('openAddDeviceFromSelectorBtn'),
        deviceActiveFilters: document.getElementById('deviceActiveFilters'),
        deviceModalLabel: document.getElementById('deviceModalLabel'),
        deviceForm: document.getElementById('deviceForm'),
        deviceImei: document.getElementById('deviceImei'),
        deviceTypeFilter: document.getElementById('deviceTypeFilter'),
        deviceLicenseFilter: document.getElementById('deviceLicenseFilter'),
        deviceSupplierFilter: document.getElementById('deviceSupplierFilter'),
        deviceModelFilter: document.getElementById('deviceModelFilter'),
        clearDeviceFiltersBtn: document.getElementById('clearDeviceFiltersBtn'),
        deviceSimNumberRoot: document.getElementById('deviceSimNumberRoot'),
        deviceDeviceIdRow: document.getElementById('deviceDeviceIdRow'),
        deviceDeviceId: document.getElementById('deviceDeviceId'),
        deviceDeviceIdLabel: document.getElementById('deviceDeviceIdLabel'),
        deviceDeviceIdHelp: document.getElementById('deviceDeviceIdHelp'),
        deviceTypeButtons: document.getElementById('deviceTypeButtons'),
        deviceLicenseId: document.getElementById('deviceLicenseId'),
        deviceLicenseSelect: document.getElementById('deviceLicenseSelect'),
        deviceCompany: document.getElementById('deviceCompany'),
        deviceCompanySelect: document.getElementById('deviceCompanySelect'),
        devicePreview: document.getElementById('devicePreview'),
        deviceSupplierButtons: document.getElementById('deviceSupplierButtons'),
        deviceModelButtons: document.getElementById('deviceModelButtons'),
        deviceConfigRoot: document.getElementById('deviceConfigRoot'),
        saveDeviceBtn: document.getElementById('saveDeviceBtn'),
        deviceImeiRow: document.getElementById('deviceImeiRow'),
        deviceSimRow: document.getElementById('deviceSimRow'),
        deviceConfigTabBtn: document.getElementById('deviceConfigTabBtn'),
        deviceConfigPane: document.getElementById('deviceConfigPane'),
        deviceGeneralTabBtn: document.getElementById('deviceGeneralTabBtn'),
        deviceGeneralPane: document.getElementById('deviceGeneralPane'),
        manageSettingsBtn: document.getElementById('manageSettingsBtn'),
        settingsSuppliersTabBtn: document.getElementById('settingsSuppliersTabBtn'),
        settingsModelsTabBtn: document.getElementById('settingsModelsTabBtn'),
        settingsCapabilitiesTabBtn: document.getElementById('settingsCapabilitiesTabBtn'),
        settingsApiUsersTabBtn: document.getElementById('settingsApiUsersTabBtn'),
        supplierListBody: document.getElementById('supplierListBody'),
        settingsSuppliersPagination: document.getElementById('settingsSuppliers'),
        settingsSuppliersPaginationSummary: document.getElementById('settingsSuppliersSummary'),
        settingsSuppliersPaginationControls: document.getElementById('settingsSuppliersControls'),
        modelForm: document.getElementById('modelForm'),
        modelPreview: document.getElementById('modelPreview'),
        modelSupplierButtons: document.getElementById('modelSupplierButtons'),
        modelInternalModel: document.getElementById('modelInternalModel'),
        modelCommercialName: document.getElementById('modelCommercialName'),
        modelDeviceTypeButtons: document.getElementById('modelDeviceTypeButtons'),
        modelImage: document.getElementById('modelImage'),
        modelPreviewContent: document.getElementById('modelPreviewContent'),
        modelListBody: document.getElementById('modelListBody'),
        settingsModelsPagination: document.getElementById('settingsModels'),
        settingsModelsPaginationSummary: document.getElementById('settingsModelsSummary'),
        settingsModelsPaginationControls: document.getElementById('settingsModelsControls'),
        resetModelBtn: document.getElementById('resetModelBtn'),
        deleteDeviceBtn: document.getElementById('deleteDeviceBtn'),
        saveModelBtn: document.getElementById('saveModelBtn'),
        capabilityDeviceTypeButtons: document.getElementById('capabilityDeviceTypeButtons'),
        capabilitySupplierButtons: document.getElementById('capabilitySupplierButtons'),
        capabilityModelButtons: document.getElementById('capabilityModelButtons'),
        capabilitySelectionEmpty: document.getElementById('capabilitySelectionEmpty'),
        capabilityEditor: document.getElementById('capabilityEditor'),
        capabilityTitle: document.getElementById('capabilityTitle'),
        capabilitySubtitle: document.getElementById('capabilitySubtitle'),
        capabilitySummary: document.getElementById('capabilitySummary'),
        saveCapabilitiesBtn: document.getElementById('saveCapabilitiesBtn'),
        capabilityGroups: document.getElementById('capabilityGroups'),
        capabilityModelPreview: document.getElementById('capabilityModelPreview'),
        capabilityModelName: document.getElementById('capabilityModelName'),
        apiUserForm: document.getElementById('apiUserForm'),
        apiUserId: document.getElementById('apiUserId'),
        apiUsername: document.getElementById('apiUsername'),
        apiUserPassword: document.getElementById('apiUserPassword'),
        apiUserRole: document.getElementById('apiUserRole'),
        apiUserLicenseId: document.getElementById('apiUserLicenseId'),
        apiUserEnabled: document.getElementById('apiUserEnabled'),
        resetApiUserBtn: document.getElementById('resetApiUserBtn'),
        saveApiUserBtn: document.getElementById('saveApiUserBtn'),
        apiUserListBody: document.getElementById('apiUserListBody'),
        settingsApiUsersPagination: document.getElementById('settingsApiUsers'),
        settingsApiUsersPaginationSummary: document.getElementById('settingsApiUsersSummary'),
        settingsApiUsersPaginationControls: document.getElementById('settingsApiUsersControls'),
        settingsCompanyTabBtn: document.getElementById('settingsCompanyTabBtn'),
        companyForm: document.getElementById('companyForm'),
        companyId: document.getElementById('companyId'),
        companyName: document.getElementById('companyName'),
        resetCompanyBtn: document.getElementById('resetCompanyBtn'),
        saveCompanyBtn: document.getElementById('saveCompanyBtn'),
        companyListBody: document.getElementById('companyListBody'),
        licenseForm: document.getElementById('licenseForm'),
        licenseId: document.getElementById('licenseId'),
        licenseCompanySelect: document.getElementById('licenseCompanySelect'),
        licenseLicenseId: document.getElementById('licenseLicenseId'),
        licenseName: document.getElementById('licenseName'),
        resetLicenseBtn: document.getElementById('resetLicenseBtn'),
        saveLicenseBtn: document.getElementById('saveLicenseBtn'),
        licenseListBody: document.getElementById('licenseListBody'),
        settingsCompanyPagination: document.getElementById('settingsCompany'),
        settingsCompanyPaginationSummary: document.getElementById('settingsCompanySummary'),
        settingsCompanyPaginationControls: document.getElementById('settingsCompanyControls'),
        settingsLicensesPagination: document.getElementById('settingsLicenses'),
        settingsLicensesPaginationSummary: document.getElementById('settingsLicensesSummary'),
        settingsLicensesPaginationControls: document.getElementById('settingsLicensesControls'),
    };
}

function bindEvents() {
    els.addDeviceBtn.addEventListener('click', () => { void openAddDevice(); });
    els.openAddDeviceFromSelectorBtn.addEventListener('click', () => {
        deviceSelectorModal?.hide();
        void openAddDevice();
    });
    els.openDeviceSelectorBtn.addEventListener('click', () => { void openDeviceSelector(); });
    els.emptyStateSelectDeviceBtn.addEventListener('click', () => { void openDeviceSelector(); });
    els.selectedDeviceEditBtn.addEventListener('click', () => {
        if (!state.selectedDetail?.device) return;
        const m = state.selectedDetail.model;
        void editDevice(
            state.selectedDetail.device.imei,
            m?.supplier || '',
            m?.internalModel || ''
        );
    });
    els.saveDeviceBtn.addEventListener('click', saveDevice);
    els.deviceForm.addEventListener('submit', event => { event.preventDefault(); saveDevice(); });
    els.deviceCompanySelect.addEventListener('change', handleCompanySelect);
    els.deviceLicenseSelect.addEventListener('change', handleLicenseSelect);
    els.deviceListLimit.addEventListener('change', handleDeviceListLimitChange);
    els.deviceListSearch.addEventListener('input', handleDeviceListSearchInput);
    els.deviceTypeFilter.addEventListener('change', handleDeviceFilterChange);
    els.deviceLicenseFilter.addEventListener('change', handleDeviceFilterChange);
    els.deviceSupplierFilter.addEventListener('change', handleDeviceFilterChange);
    els.deviceModelFilter.addEventListener('change', handleDeviceFilterChange);
    els.clearDeviceFiltersBtn.addEventListener('click', clearDeviceFilters);
    els.deviceActiveFilters.addEventListener('click', handleActiveDeviceFiltersClick);
    els.deviceImei.addEventListener('input', handleDeviceImeiInput);
    els.deviceLicenseId.addEventListener('input', handleDeviceImeiInput);
    els.deviceDeviceId.addEventListener('input', handleDeviceImeiInput);
    els.deviceForm.addEventListener('input', handleDeviceFormInput);
    els.deviceForm.addEventListener('change', handleDeviceFormChange);
    els.manageSettingsBtn.addEventListener('click', () => { void loadSettingsModal('suppliers'); });

    els.saveModelBtn.addEventListener('click', saveModel);
    els.resetModelBtn.addEventListener('click', () => resetModelForm());
    els.modelForm.addEventListener('submit', event => { event.preventDefault(); saveModel(); });
    els.modelInternalModel.addEventListener('input', () => updateModelProtocolAndPreview());
    els.modelCommercialName.addEventListener('input', () => updateModelProtocolAndPreview());
    els.modelImage.addEventListener('change', handleModelImageChange);
    els.saveCapabilitiesBtn.addEventListener('click', () => { void saveCapabilities(); });
    els.saveApiUserBtn.addEventListener('click', () => { void saveApiUser(); });
    els.resetApiUserBtn.addEventListener('click', resetApiUserForm);
    els.apiUserForm.addEventListener('submit', event => { event.preventDefault(); saveApiUser(); });
    els.apiUserRole.addEventListener('change', syncApiUserRoleFields);
    els.telemetryPager.addEventListener('click', handleTelemetryPagerClick);
    els.applyDetailFiltersBtn.addEventListener('click', applyDetailFilters);
    els.clearDetailFiltersBtn.addEventListener('click', clearDetailFilters);
    els.deleteDeviceBtn.addEventListener('click', handleDeleteDeviceBtnClick);
    els.deviceSupplierButtons.addEventListener('click', handleDeviceSupplierClick);
    els.deviceTypeButtons.addEventListener('click', handleDeviceTypeClick);
    els.deviceModelButtons.addEventListener('click', handleDeviceModelClick);
    els.modelSupplierButtons.addEventListener('click', handleModelSupplierClick);
    els.modelDeviceTypeButtons.addEventListener('click', handleModelDeviceTypeClick);
    els.capabilityDeviceTypeButtons.addEventListener('click', handleCapabilityDeviceTypeClick);
    els.capabilitySupplierButtons.addEventListener('click', handleCapabilitySupplierClick);
    els.capabilityModelButtons.addEventListener('click', handleCapabilityModelClick);
    els.capabilityGroups.addEventListener('change', handleCapabilityGroupsChange);
    els.settingsSuppliersTabBtn.addEventListener('shown.bs.tab', () => {
        state.settingsModal.section = 'suppliers';
        if (!state.settingsModal.sectionLoaded.suppliers) {
            void loadSettingsSuppliersSection();
        }
    });
    els.settingsModelsTabBtn.addEventListener('shown.bs.tab', () => {
        state.settingsModal.section = 'models';
        if (!state.settingsModal.sectionLoaded.models) {
            void loadSettingsModelsSection();
        }
    });
    els.settingsCapabilitiesTabBtn.addEventListener('shown.bs.tab', () => {
        state.settingsModal.section = 'capabilities';
        if (!state.settingsModal.sectionLoaded.capabilities) {
            void loadSettingsCapabilitiesSection();
        } else {
            syncCapabilitiesSelection();
            renderCapabilitiesSection();
        }
    });
    els.settingsApiUsersTabBtn.addEventListener('shown.bs.tab', () => {
        state.settingsModal.section = 'apiUsers';
        if (!state.settingsModal.sectionLoaded.apiUsers) {
            void loadSettingsApiUsersSection();
        }
    });
    els.settingsCompanyTabBtn.addEventListener('shown.bs.tab', () => {
        state.settingsModal.section = 'company';
        if (!state.settingsModal.sectionLoaded.company) {
            void loadSettingsCompanySection();
        }
    });
    els.saveCompanyBtn.addEventListener('click', () => { void saveCompany(); });
    els.resetCompanyBtn.addEventListener('click', resetCompanyForm);
    els.companyForm.addEventListener('submit', event => { event.preventDefault(); saveCompany(); });
    els.saveLicenseBtn.addEventListener('click', () => { void saveLicense(); });
    els.resetLicenseBtn.addEventListener('click', resetLicenseForm);
    els.licenseForm.addEventListener('submit', event => { event.preventDefault(); saveLicense(); });
    els.settingsSuppliersPagination?.addEventListener('click', event => handleSettingsPaginationClick(event, 'suppliersPagination', loadSettingsSuppliersSection));
    els.settingsModelsPagination?.addEventListener('click', event => handleSettingsPaginationClick(event, 'modelsPagination', loadSettingsModelsSection));
    els.settingsApiUsersPagination?.addEventListener('click', event => handleSettingsPaginationClick(event, 'apiUsersPagination', loadSettingsApiUsersSection));
    els.settingsCompanyPagination?.addEventListener('click', event => handleSettingsPaginationClick(event, 'companyPagination', (page) => loadSettingsCompanySection(page, 1)));
    els.settingsLicensesPagination?.addEventListener('click', event => handleSettingsPaginationClick(event, 'licensesPagination', (page) => loadSettingsCompanySection(1, page)));
    els.deviceList.addEventListener('click', handleDeviceListClick);
    els.deviceListPagination.addEventListener('click', handleDevicePaginationClick);
    els.requestGrid.addEventListener('click', handleRequestGridClick);
    els.supplierListBody.addEventListener('click', handleSupplierListClick);
    els.modelListBody.addEventListener('click', handleModelListClick);
    els.apiUserListBody.addEventListener('click', handleApiUserListClick);
    els.companyListBody.addEventListener('click', handleCompanyListClick);
    els.licenseListBody.addEventListener('click', handleLicenseListClick);
    els.deviceConfigRoot.addEventListener('click', handleDeviceConfigClick);
    els.deviceConfigRoot.addEventListener('input', handleDeviceConfigInput);
    els.deviceConfigRoot.addEventListener('change', handleDeviceConfigChange);
    els.deviceConfigRoot.addEventListener('closed.bs.alert', handleConfigFeedbackClosed);
}

function handleModelImageChange() {
    revokeModelPreviewUrl();
    const file = els.modelImage.files[0];
    if (file) {
        state.modelPreviewObjectUrl = URL.createObjectURL(file);
        const label = els.modelCommercialName.value.trim() || els.modelInternalModel.value.trim() || 'Modelo';
        els.modelPreviewContent.innerHTML = `<img src="${esc(state.modelPreviewObjectUrl)}" class="object-fit-contain w-100 h-100" alt="${esc(label)}" style="max-height:180px;">`;
    } else {
        updateModelProtocolAndPreview();
    }
}

function handleDeviceImeiInput() {
    syncDeviceModalContext();
    renderDeviceConfigurationModal();
}

function handleDeviceFormInput(event) {
    if (event.target.matches('[data-phone-local]')) {
        syncPhoneControl(event.target);
        syncDeviceModalContext();
    }
}

function handleDeviceFormChange(event) {
    if (event.target.matches('[data-phone-country]')) {
        syncPhoneControl(event.target);
        syncDeviceModalContext();
    }
}

async function handleDeviceFilterChange() {
    state.deviceFilters = {
        deviceType: normalizeFilterValue(els.deviceTypeFilter.value),
        licenseId: normalizeFilterValue(els.deviceLicenseFilter.value),
        supplier: normalizeFilterValue(els.deviceSupplierFilter.value),
        model: normalizeFilterValue(els.deviceModelFilter.value),
    };
    state.deviceListPage = 1;
    saveFiltersToStorage();
    await loadSummary();
}

async function handleActiveDeviceFiltersClick(event) {
    const button = event.target.closest('[data-action="removeDeviceFilter"]');
    if (!button) return;

    const key = button.dataset.filterKey;
    if (!key || !(key in state.deviceFilters)) return;

    state.deviceFilters = {
        ...state.deviceFilters,
        [key]: null,
    };
    state.deviceListPage = 1;
    saveFiltersToStorage();
    await loadSummary();
}

function loadFiltersFromStorage() {
    try {
        const stored = localStorage.getItem(FILTERS_STORAGE_KEY);
        if (stored) {
            const parsed = JSON.parse(stored);
            if (parsed && typeof parsed === 'object') {
                return {
                    deviceType: normalizeFilterValue(parsed.deviceType),
                    licenseId: normalizeFilterValue(parsed.licenseId),
                    supplier: normalizeFilterValue(parsed.supplier),
                    model: normalizeFilterValue(parsed.model),
                };
            }
        }
    } catch {
    }
    return null;
}

function saveFiltersToStorage() {
    try {
        localStorage.setItem(FILTERS_STORAGE_KEY, JSON.stringify(state.deviceFilters));
    } catch {
    }
}

function clearFiltersFromStorage() {
    try {
        localStorage.removeItem(FILTERS_STORAGE_KEY);
    } catch {
    }
}

function loadSelectedDeviceFromStorage() {
    try {
        const stored = localStorage.getItem(SELECTED_DEVICE_STORAGE_KEY);
        return stored ? String(stored) : null;
    } catch {
        return null;
    }
}

function saveSelectedDeviceToStorage() {
    try {
        if (state.selectedImei) {
            localStorage.setItem(SELECTED_DEVICE_STORAGE_KEY, state.selectedImei);
        }
    } catch {
    }
}

function clearSelectedDeviceFromStorage() {
    try {
        localStorage.removeItem(SELECTED_DEVICE_STORAGE_KEY);
    } catch {
    }
}

async function clearDeviceFilters() {
    const defaults = {
        deviceType: null,
        licenseId: null,
        supplier: null,
        model: null,
    };
    state.deviceFilters = {...defaults};
    state.deviceListPage = 1;
    clearFiltersFromStorage();
    await loadSummary();
}

function handleTelemetryPagerClick(event) {
    const button = event.target.closest('[data-action]');
    if (!button || !state.selectedDetail) return;
    const allItems = allDetailItems();
    const filtered = filterDetailItems(allItems);
    const telemetryRows = filtered.filter(item => ['telemetry', 'event'].includes(item._source)).map(item => item.raw);
    const totalPages = Math.max(1, Math.ceil(telemetryRows.length / state.telemetryPageSize));
    if (button.dataset.action === 'telemetryPrev') setTelemetryPage(state.telemetryPage - 1, totalPages);
    if (button.dataset.action === 'telemetryNext') setTelemetryPage(state.telemetryPage + 1, totalPages);
    if (button.dataset.action === 'telemetryPageGo') setTelemetryPage(parseInt(button.dataset.page || '1', 10), totalPages);
    renderTelemetryList(telemetryRows);
}

function handleDeviceConfigClick(event) {
    const button = event.target.closest('[data-config-category], [data-action]');
    if (!button) return;

    if (button.dataset.configCategory) {
        event.preventDefault();
        state.deviceModal.activeCategory = button.dataset.configCategory;
        renderDeviceConfigurationModal();
        return;
    }

    const section = button.closest('[data-config-section]');
    if (!section) return;

    if (button.dataset.action === 'saveConfig') {
        void saveDeviceConfiguration(section);
        return;
    }

    if (button.dataset.action === 'addContactRow') {
        appendContactRow(section);
        return;
    }

    if (button.dataset.action === 'removeContactRow') {
        removeConfigRow(button.closest('[data-repeat-row="contacts"]'));
        return;
    }

    if (button.dataset.action === 'addReminderRow') {
        appendReminderRow(section);
        return;
    }

    if (button.dataset.action === 'removeReminderRow') {
        removeConfigRow(button.closest('[data-repeat-row="reminders"]'));
    }
}

function handleDeviceConfigChange(event) {
    if (event.target.matches('[data-phone-country]')) {
        syncPhoneControl(event.target);
        return;
    }

    const section = event.target.closest('[data-config-section]');
    if (!section) return;

    if (event.target.matches('[data-config-field="mode"]')) {
        const extra = section.querySelector('[data-working-mode-extra]');
        if (extra) {
            extra.classList.toggle('d-none', String(event.target.value) !== '8');
        }
    }

    if (event.target.matches('.form-check-input[type="checkbox"][role="switch"]')) {
        const label = event.target.parentElement?.querySelector('[data-switch-label]');
        if (label) {
            label.textContent = event.target.checked
                ? (label.dataset.switchOn || 'Ligado')
                : (label.dataset.switchOff || 'Desligado');
        }
    }
}

function handleDeviceConfigInput(event) {
    if (event.target.matches('[data-phone-local]')) {
        syncPhoneControl(event.target);
    }
}

function handleConfigFeedbackClosed(event) {
    const alertEl = event.target.closest('[data-config-feedback-key]');
    if (!alertEl) return;

    const key = alertEl.dataset.configFeedbackKey || '';
    clearTimeout(configFeedbackTimers.get(key));
    configFeedbackTimers.delete(key);
    clearConfigFeedback(key);
}

function handleDeviceSupplierClick(event) {
    const button = event.target.closest('[data-action="selectDeviceSupplier"]');
    if (button) renderDeviceSelectors(button.dataset.value, '');
}

function handleDeviceTypeClick(event) {
    const button = event.target.closest('[data-action="selectDeviceType"]');
    if (!button) return;

    const deviceType = normalizeDeviceType(button.dataset.value);
    renderDeviceTypeSelector(deviceType);
    renderDeviceSelectors('', '', deviceType);
}

function handleDeviceModelClick(event) {
    const button = event.target.closest('[data-action="selectDeviceModel"]');
    if (!button) return;
    els.deviceForm.dataset.model = button.dataset.value;
    renderDeviceSelectors(els.deviceForm.dataset.supplier, button.dataset.value);
}

function handleModelSupplierClick(event) {
    const button = event.target.closest('[data-action="selectModelSupplier"]');
    if (button) selectModelSupplier(button.dataset.value);
}

function handleModelDeviceTypeClick(event) {
    const button = event.target.closest('[data-action="selectModelDeviceType"]');
    if (button) selectModelDeviceType(button.dataset.value);
}

function handleCapabilityDeviceTypeClick(event) {
    const button = event.target.closest('[data-action="selectCapabilityDeviceType"]');
    if (button) selectCapabilityDeviceType(button.dataset.value);
}

function handleCapabilitySupplierClick(event) {
    const button = event.target.closest('[data-action="selectCapabilitySupplier"]');
    if (button) selectCapabilitySupplier(button.dataset.value);
}

function handleCapabilityModelClick(event) {
    const button = event.target.closest('[data-action="selectCapabilityModel"]');
    if (button) selectCapabilityModel(button.dataset.value);
}

function handleCapabilityGroupsChange(event) {
    const checkbox = event.target.closest('[data-action="toggleCapabilityRequest"]');
    if (!checkbox) return;

    const feature = String(checkbox.dataset.feature || '');
    if (!feature) return;

    const enabled = new Set(state.settingsModal.capabilityEnabledCapabilities || []);
    if (checkbox.checked) {
        enabled.add(feature);
    } else {
        enabled.delete(feature);
    }
    state.settingsModal.capabilityEnabledCapabilities = [...enabled];
    renderCapabilitiesSection();
}

function handleDeviceListClick(event) {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const {action, imei} = button.dataset;
    if (action === 'select') selectDevice(imei);
}

function handleRequestGridClick(event) {
    const button = event.target.closest('[data-action="requestFeature"]');
    if (button) requestTelemetryFeature(String(button.dataset.feature || ''));
}

function handleSupplierListClick(event) {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const {id, action, enabled} = button.dataset;
    if (action === 'toggleSupplier') toggleSupplier(parseInt(id), !!enabled);
}

function handleModelListClick(event) {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    if (button.dataset.action === 'editModel') {
        editModel(parseInt(button.dataset.id), parseInt(button.dataset.supplierId), button.dataset.supplier, button.dataset.internalModel, button.dataset.commercialName, button.dataset.deviceType, button.dataset.image);
    }
    if (button.dataset.action === 'deleteModel') {
        deleteModel(parseInt(button.dataset.id));
    }
}

function handleApiUserListClick(event) {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    if (button.dataset.action === 'editApiUser') {
        editApiUser(button);
    }
    if (button.dataset.action === 'toggleApiUser') {
        toggleApiUser(button);
    }
    if (button.dataset.action === 'deleteApiUser') {
        deleteApiUser(parseInt(button.dataset.id));
    }
}

async function saveDeviceConfiguration(section) {
    const key = section.dataset.configKey || '';
    if (!key) return;

    let payload;
    try {
        payload = readConfigPayload(section);
    } catch (error) {
        alert(error instanceof Error ? error.message : 'Configuração inválida');
        return;
    }

    setConfigUi(key, {phase: 'submitting'});
    renderDeviceConfigurationModal();

    try {
        const result = await api.saveConfiguration(
            state.deviceModal.imei,
            {[key]: payload},
            state.deviceModal.supplier,
            state.deviceModal.model
        );
        if (result.error) {
            setConfigUi(key, {
                phase: 'idle',
                feedback: {tone: 'danger', message: result.error.message || result.error.code || 'Falha ao enviar configuração'},
            });
            renderDeviceConfigurationModal();
            return;
        }

        state.deviceModal.configurations = result.configuration || state.deviceModal.configurations;

        setConfigUi(key, {
            phase: 'sent',
            feedback: {tone: 'success', message: 'Configuração enviada ao dispositivo.'},
        });
        renderDeviceConfigurationModal();
        transitionConfigPhase(key, 'sent', 1200, () => {
            clearConfigUiPhase(key, 'sent');
            renderDeviceConfigurationModal();
        });
    } catch (error) {
        setConfigUi(key, {
            phase: 'idle',
            feedback: {tone: 'danger', message: error instanceof Error ? error.message : 'Falha ao enviar configuração'},
        });
        renderDeviceConfigurationModal();
    }
}

async function refreshDeviceModalConfigurations(shouldRender = true) {
    if (!state.deviceModal.imei || !state.deviceModal.supplier || !state.deviceModal.model) {
        return null;
    }

    if (deviceConfigRefreshPromise) {
        return deviceConfigRefreshPromise;
    }

    const snapshot = [
        state.deviceModal.imei,
        state.deviceModal.supplier,
        state.deviceModal.model,
    ].join('|');

    deviceConfigRefreshPromise = api.device(state.deviceModal.imei).then(result => {
        const current = [
            state.deviceModal.imei,
            state.deviceModal.supplier,
            state.deviceModal.model,
        ].join('|');
        if (snapshot !== current) {
            return result;
        }

        state.deviceModal.configurations = result?.configurations || {};
        if (shouldRender) {
            renderDeviceConfigurationModal();
        }
        return result;
    }).finally(() => {
        deviceConfigRefreshPromise = null;
    });

    return deviceConfigRefreshPromise;
}

function setConfigUi(key, updates) {
    state.deviceModal.configUi[key] = {
        ...(state.deviceModal.configUi[key] || {}),
        ...updates,
    };
}

function clearConfigUiPhase(key, phase) {
    const current = state.deviceModal.configUi[key];
    if (!current || current.phase !== phase) {
        return;
    }

    const next = {...current};
    delete next.phase;
    if (Object.keys(next).length === 0) {
        delete state.deviceModal.configUi[key];
        return;
    }
    state.deviceModal.configUi[key] = next;
}

function clearConfigFeedback(key) {
    const current = state.deviceModal.configUi[key];
    if (!current) {
        return;
    }

    const next = {...current};
    delete next.feedback;
    if (Object.keys(next).length === 0) {
        delete state.deviceModal.configUi[key];
        return;
    }
    state.deviceModal.configUi[key] = next;
}

function transitionConfigPhase(key, phase, delayMs, callback) {
    clearTimeout(configPhaseTimers.get(key));
    configPhaseTimers.set(key, setTimeout(() => {
        const current = state.deviceModal.configUi[key];
        if (current?.phase === phase) {
            callback();
        }
        configPhaseTimers.delete(key);
    }, delayMs));
}

function armConfigFeedbackAutoClose() {
    const alerts = Array.from(els.deviceConfigRoot.querySelectorAll('[data-config-feedback-key]'));
    for (const alertEl of alerts) {
        const key = alertEl.dataset.configFeedbackKey || '';
        if (!key || configFeedbackTimers.has(key)) {
            continue;
        }

        configFeedbackTimers.set(key, setTimeout(() => {
            const liveAlert = els.deviceConfigRoot.querySelector(`[data-config-feedback-key="${CSS.escape(key)}"]`);
            if (liveAlert) {
                bootstrap.Alert.getOrCreateInstance(liveAlert).close();
            } else {
                clearConfigFeedback(key);
            }
            configFeedbackTimers.delete(key);
        }, 3500));
    }
}



function resetConfigUiState() {
    for (const timer of configFeedbackTimers.values()) {
        clearTimeout(timer);
    }
    configFeedbackTimers.clear();

    for (const timer of configPhaseTimers.values()) {
        clearTimeout(timer);
    }
    configPhaseTimers.clear();

    deviceConfigRefreshPromise = null;
}

function renderDeviceSimNumberField(value = '') {
    if (!els.deviceSimNumberRoot) {
        return;
    }

    els.deviceSimNumberRoot.innerHTML = renderPhoneControl({
        value,
        placeholder: 'Número do SIM',
    });
    resetPhoneControls(els.deviceSimNumberRoot);
}

function getDeviceSimNumberValue(strict = false) {
    const control = els.deviceSimNumberRoot?.querySelector('[data-phone-control]') || null;
    if (!control) {
        return '';
    }

    if (!strict) {
        try {
            return normalizePhoneControl(control);
        } catch {
            return '';
        }
    }

    return normalizePhoneControl(control);
}

function appendContactRow(section) {
    const list = section.querySelector('[data-repeat-limit]');
    if (!list) return;

    const limit = parseInt(list.dataset.repeatLimit || '10', 10);
    const rows = list.querySelectorAll('[data-repeat-row="contacts"]');
    if (rows.length >= limit) return;

    const template = rows[rows.length - 1] || createContactRow();
    const clone = template.cloneNode(true);
    clone.querySelectorAll('input').forEach(input => {
        if (input.matches('[data-phone-local]')) {
            input.value = '';
            return;
        }
        input.value = '';
    });
    const countrySelect = clone.querySelector('[data-phone-country]');
    if (countrySelect) {
        countrySelect.value = 'PT';
    }
    resetPhoneControls(clone);
    list.appendChild(clone);
}

function appendReminderRow(section) {
    const list = section.querySelector('[data-reminders-list]');
    if (!list) return;

    const clone = createReminderRow();
    list.appendChild(clone);
}

function removeConfigRow(row) {
    if (!row) return;
    const parent = row.parentElement;
    if (!parent) return;
    if (parent.children.length <= 1) {
        row.querySelectorAll('input, select').forEach(input => {
            if (input.type === 'checkbox') {
                input.checked = false;
            } else if (input.matches('[data-phone-country]')) {
                input.value = 'PT';
            } else {
                input.value = '';
            }
        });
        resetPhoneControls(row);
        return;
    }
    row.remove();
}

function createContactRow() {
    const wrapper = document.createElement('div');
    wrapper.className = 'row g-2 align-items-end';
    wrapper.dataset.repeatRow = 'contacts';
    wrapper.innerHTML = `
        <div class="col-md-6">
            <input class="form-control" type="text" placeholder="Nome" data-repeat-field="name">
        </div>
        <div class="col-md-6">
            <div class="d-flex gap-2">
                <div class="flex-grow-1">
                    ${renderPhoneControl({repeatField: 'phone', placeholder: 'Telefone'})}
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeContactRow">-</button>
            </div>
        </div>`;
    resetPhoneControls(wrapper);
    return wrapper;
}

function createReminderRow() {
    const uid = `reminder-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
    const wrapper = document.createElement('div');
    wrapper.className = 'border rounded p-3 bg-body';
    wrapper.dataset.repeatRow = 'reminders';
    wrapper.innerHTML = `
        <div class="row g-3 align-items-end">
            <div class="col-sm-6 col-lg-2">
                <label class="form-label form-label-sm">Hora</label>
                <input class="form-control" type="time" data-repeat-field="time">
            </div>
            <div class="col-sm-6 col-lg-2">
                <div class="form-check form-switch mt-4">
                    <input class="form-check-input" type="checkbox" role="switch" data-repeat-field="enabled" checked>
                    <label class="form-check-label" data-switch-label>Ligado</label>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <label class="form-label form-label-sm d-block">Dias</label>
                <div class="d-flex flex-wrap gap-1" role="group" aria-label="Dias da semana">
                    <input class="btn-check" type="checkbox" id="${uid}-day-1" data-repeat-field="days" value="1">
                    <label class="btn btn-outline-secondary btn-sm" for="${uid}-day-1">Seg</label>
                    <input class="btn-check" type="checkbox" id="${uid}-day-2" data-repeat-field="days" value="2">
                    <label class="btn btn-outline-secondary btn-sm" for="${uid}-day-2">Ter</label>
                    <input class="btn-check" type="checkbox" id="${uid}-day-3" data-repeat-field="days" value="3">
                    <label class="btn btn-outline-secondary btn-sm" for="${uid}-day-3">Qua</label>
                    <input class="btn-check" type="checkbox" id="${uid}-day-4" data-repeat-field="days" value="4">
                    <label class="btn btn-outline-secondary btn-sm" for="${uid}-day-4">Qui</label>
                    <input class="btn-check" type="checkbox" id="${uid}-day-5" data-repeat-field="days" value="5">
                    <label class="btn btn-outline-secondary btn-sm" for="${uid}-day-5">Sex</label>
                    <input class="btn-check" type="checkbox" id="${uid}-day-6" data-repeat-field="days" value="6">
                    <label class="btn btn-outline-secondary btn-sm" for="${uid}-day-6">Sab</label>
                    <input class="btn-check" type="checkbox" id="${uid}-day-7" data-repeat-field="days" value="7">
                    <label class="btn btn-outline-secondary btn-sm" for="${uid}-day-7">Dom</label>
                </div>
            </div>
            <div class="col-12 col-lg-3">
                <label class="form-label form-label-sm d-block">Tipo</label>
                <div class="row g-2" role="group" aria-label="Tipo de lembrete">
                    <div class="col-12">
                        <input class="btn-check" type="radio" name="${uid}-type" id="${uid}-type-1" data-repeat-field="type" value="1" checked>
                        <label class="btn btn-outline-primary btn-sm w-100 text-start" for="${uid}-type-1"><i class="fa-solid fa-pills me-1"></i>Medicação</label>
                    </div>
                    <div class="col-12">
                        <input class="btn-check" type="radio" name="${uid}-type" id="${uid}-type-2" data-repeat-field="type" value="2">
                        <label class="btn btn-outline-info btn-sm w-100 text-start" for="${uid}-type-2"><i class="fa-solid fa-glass-water me-1"></i>Água</label>
                    </div>
                    <div class="col-12">
                        <input class="btn-check" type="radio" name="${uid}-type" id="${uid}-type-3" data-repeat-field="type" value="3">
                        <label class="btn btn-outline-warning btn-sm w-100 text-start" for="${uid}-type-3"><i class="fa-solid fa-person-walking me-1"></i>Sedentarismo</label>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-1 d-flex justify-content-lg-end">
                <button type="button" class="btn btn-outline-danger btn-sm mt-lg-4" data-action="removeReminderRow" title="Remover" aria-label="Remover">
                    <i class="fa-solid fa-trash-can"></i>
                </button>
            </div>
        </div>`;
    return wrapper;
}

export function startDashboard() {
    cacheElements();
    deviceModal = new bootstrap.Modal(document.getElementById('deviceModal'));
    deviceSelectorModal = new bootstrap.Modal(document.getElementById('deviceSelectorModal'));
    settingsModal = new bootstrap.Modal(document.getElementById('settingsModal'));
    bindEvents();

    const stored = loadFiltersFromStorage();
    if (stored) {
        state.deviceFilters = stored;
    }
    const storedSelectedImei = loadSelectedDeviceFromStorage();
    if (storedSelectedImei) {
        state.selectedImei = storedSelectedImei;
        void loadDevice(storedSelectedImei);
    } else {
        renderSelection();
    }

    setInterval(() => {
        if (state.selectedImei) {
            api.device(state.selectedImei).then(detail => {
                if (detail?.error) return;
                if (state.selectedImei !== detail.device?.imei) return;
                const recent = state.selectedDetail?.recent;
                state.selectedDetail = detail;
                state.selectedDetail.recent = recent;
                renderSelection();
            });
        }
    }, 30000);
}
