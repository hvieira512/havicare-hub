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

let connectionChartRoot = null;

let els = {};
let deviceModal = null;
let deviceSelectorModal = null;
let supplierModal = null;
let modelModal = null;
const configFeedbackTimers = new Map();
const configPhaseTimers = new Map();
const configPollTimers = new Map();
let deviceConfigRefreshPromise = null;
let deviceSearchTimer = null;
const FILTERS_STORAGE_KEY = 'hub-dashboard-device-filters';
const SELECTED_DEVICE_STORAGE_KEY = 'hub-dashboard-selected-device';
const deviceTypeOptions = [
    {value: 'watch', label: 'Relógios'},
    {value: 'ncs', label: 'NCS'},
    {value: 'radar', label: 'Radars'},
];

function deviceTypeLabel(deviceType) {
    return deviceTypeOptions.find(option => option.value === deviceType)?.label || deviceType;
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

function supplierProtocol(supplier, models = state.summary.models) {
    const existing = models.find(model => model.supplier === supplier && model.protocol);
    return existing?.protocol || '';
}

function suppliersFromModels(models = state.summary.models) {
    return [...new Set(models.map(model => model.supplier).filter(Boolean))];
}

function modelsForSupplier(supplier, models = state.summary.models) {
    return models.filter(model => model.supplier === supplier);
}

function findModelInfo(supplier, model, models = state.summary.models) {
    return models.find(entry => entry.supplier === supplier && entry.model === model) || null;
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
        modelLookup[`${model.supplier}:${model.model}`] = model;
    }

    const groups = {};

    for (const device of state.summary.devices) {
        const deviceType = normalizeDeviceType(device.deviceType);
        const key = `${normalizeLicenseId(device.licenseId)}:${deviceType}:${device.supplier} / ${device.model}`;
        if (!groups[key]) {
            groups[key] = {
                supplier: device.supplier,
                model: device.model,
                deviceType,
                licenseId: normalizeLicenseId(device.licenseId),
                devices: [],
            };
        }
        groups[key].devices.push(device);
    }

    const groupMarkup = Object.values(groups).map(group => {
        const modelInfo = modelLookup[`${group.supplier}:${group.model}`];
        return `
            <div class="list-group list-group-flush">
            <div class="small fw-semibold text-secondary px-3 py-1 bg-body-tertiary border-bottom">Licença ${esc(group.licenseId)} · ${esc(deviceTypeLabel(group.deviceType))} · ${esc(group.supplier)} ${esc(group.model)}</div>
            ${group.devices.map(device => `
                <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom${state.selectedImei === device.imei ? ' bg-primary-subtle' : ''}">
                <div style="width:40px;text-align:center;flex-shrink:0">${modelImageHtml(modelInfo)}</div>
                <div class="flex-grow-1 min-width-0 d-flex align-items-center gap-2">
                <span class="rounded-circle ${device.online ? 'bg-success' : 'bg-danger'} d-inline-block flex-shrink-0" style="width:.55rem;height:.55rem;"></span>
                <strong class="small text-break">${esc(device.imei)}</strong>
                </div>
                <div class="btn-group btn-group-sm" style="flex-shrink:0">
                <button class="btn ${state.selectedImei === device.imei ? 'btn-primary' : 'btn-outline-primary'}" data-imei="${esc(device.imei)}" data-action="select" title="Escolher dispositivo">
                <i class="fa-solid fa-check me-1"></i>Escolher
                </button>
                </div>
                </div>`).join('')}
            </div>`;
    }).join('');

    els.deviceList.innerHTML = groupMarkup || emptyPanel('Não há dispositivos para o filtro selecionado.');
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
    renderSelectOptions(els.deviceLicenseFilter, options.licenseId || [], state.deviceFilters.licenseId, value => value);
    renderSelectOptions(els.deviceSupplierFilter, options.supplier || [], state.deviceFilters.supplier, value => value);
    renderSelectOptions(els.deviceModelFilter, options.model || [], state.deviceFilters.model, value => value);
    renderAppliedDeviceFilters();
}

function renderAppliedDeviceFilters() {
    const labels = [];

    if (state.deviceFilters.deviceType) {
        labels.push({key: 'deviceType', label: `Tipo: ${deviceTypeLabel(state.deviceFilters.deviceType)}`});
    }
    if (state.deviceFilters.licenseId) {
        labels.push({key: 'licenseId', label: `Licença: ${state.deviceFilters.licenseId}`});
    }
    if (state.deviceFilters.supplier) {
        labels.push({key: 'supplier', label: `Fornecedor: ${state.deviceFilters.supplier}`});
    }
    if (state.deviceFilters.model) {
        labels.push({key: 'model', label: `Modelo: ${state.deviceFilters.model}`});
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
            clearSelection();
            clearSelectedDeviceFromStorage();
        }
        renderSelection();
        return false;
    }
    state.selectedDetail = detail;
    renderSelection();
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
    renderSelectedDeviceSummary(device);
    els.detailTitle.textContent = device.imei;
    els.detailMeta.textContent = `${deviceTypeLabel(normalizeDeviceType(device.deviceType))} · licença ${device.licenseId ?? '0'} · ${device.supplier ?? ''} ${device.model ?? ''} · visto ${ago(device.lastSeenAt)}`;
    els.detailBadge.className = `badge ${device.online ? 'text-bg-success' : 'text-bg-secondary'}`;
    els.detailBadge.textContent = device.online ? 'ligado' : 'desligado';

    if (!state.detailFilters.from) {
        const sevenDaysAgo = new Date();
        sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);
        state.detailFilters.from = sevenDaysAgo.toISOString().slice(0, 16);
    }

    els.detailFiltersPanel.classList.toggle('d-none', !state.detailFiltersOpen);
    els.toggleDetailFiltersBtn.classList.toggle('btn-outline-secondary', !state.detailFiltersOpen);
    els.toggleDetailFiltersBtn.classList.toggle('btn-secondary', state.detailFiltersOpen);

    populateDetailFilterTypes();
    syncDetailFilterControls();

    const allItems = allDetailItems();
    const filtered = filterDetailItems(allItems);
    const ncsEvents = filtered.filter(item => item._source === 'event' && item.payload?.type === 'ncs.event').map(item => item.raw);
    const telemetry = filtered.filter(item => item._source === 'telemetry').map(item => item.raw);
    const commands = filtered.filter(item => item._source === 'command').map(item => item.raw);
    const connectionEvents = filtered.filter(item => item._source === 'connection').map(item => item.raw);

    renderTelemetryList([...telemetry, ...ncsEvents]);
    renderRequestCards(state.selectedDetail.commands || [], telemetry);
    renderDownlinkRequests(commands);
    renderConnectionTimeline(connectionEvents);
}

function renderSelectedDeviceSummary(device) {
    const supplier = String(device.supplier || '');
    const model = String(device.model || '');
    const modelInfo = findModelInfo(supplier, model);
    const facts = [
        {label: 'Tipo', value: deviceTypeLabel(normalizeDeviceType(device.deviceType))},
        {label: 'Licença', value: String(device.licenseId || '0')},
        {label: 'Fornecedor', value: supplier || '-'},
        {label: 'Modelo', value: model || '-'},
        {label: 'Última ligação', value: when(device.lastSeenAt) || 'Sem registo'},
    ];

    if (device.protocol) {
        facts.push({label: 'Protocolo', value: String(device.protocol)});
    }
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
    for (const row of (state.selectedDetail.recent.telemetry || [])) {
        const payload = rowPayload(row);
        if (payload && !payload.debug) items.push({_source: 'telemetry', raw: row, payload});
    }
    for (const row of (state.selectedDetail.recent.events || [])) {
        const payload = rowPayload(row);
        if (!payload) continue;
        if (payload.type === 'ncs.event') items.push({_source: 'event', raw: row, payload});
        if (payload.type === 'device.connected' || payload.type === 'device.disconnected') items.push({_source: 'connection', raw: row, payload});
    }
    for (const row of (state.selectedDetail.recent.commands || [])) {
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

function toggleDetailFilters() {
    state.detailFiltersOpen = !state.detailFiltersOpen;
    renderSelection();
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
        <div class="small text-secondary">${esc(payload.source?.nativeType || 'telemetria')}${payload.source?.protocol ? ` · ${esc(payload.source.protocol)}` : ''}</div>
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

function renderRequestCards(commands, telemetry = []) {
    els.requestCardCount.textContent = commands.length ? `${commands.length} ações` : '';
    els.requestGrid.innerHTML = commands.length
        ? commands.map(command => renderRequestCardShell(command, state.loadingCommands.has(command.command), telemetry)).join('')
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
    els.connectionStats.textContent = events.length ? `${connectedCount} ligações · ${disconnectedCount} desligamentos` : '';

    if (events.length < 2) {
        if (connectionChartRoot) {
            connectionChartRoot.dispose();
            connectionChartRoot = null;
        }
        els.connectionTimeline.innerHTML = events.length === 1
            ? `<div class="text-center text-secondary py-4"><i class="fa-solid fa-circle ${events[0].type === 'device.connected' ? 'text-success' : 'text-secondary'} me-2"></i>${events[0].type === 'device.connected' ? 'Ligado' : 'Desligado'} · ${esc(when(events[0].occurredAt || events[0].recordedAt))}</div>`
            : emptyPanel('Ainda não há registos de ligação.');
        return;
    }

    if (connectionChartRoot) {
        connectionChartRoot.dispose();
    }

    connectionChartRoot = am5.Root.new(els.connectionTimeline);

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

    const valueAxis = chart.yAxes.push(am5xy.ValueAxis.new(connectionChartRoot, {
        renderer: am5xy.AxisRendererY.new(connectionChartRoot, {}),
        min: -0.5,
        max: 1.5,
        strictMinMax: true,
        numberFormat: "#'##",
    }));
    valueAxis.get('renderer').labels.template.set('text', '');
    valueAxis.get('renderer').setAll({minGridDistance: Infinity});

    const data = connectionStepData(events);
    const series = chart.series.push(am5xy.StepLineSeries.new(connectionChartRoot, {
        name: 'Ligação',
        xAxis: dateAxis,
        yAxis: valueAxis,
        valueYField: 'value',
        valueXField: 'date',
        stroke: am5.color(0x6c757d),
        strokeWidth: 2,
        noRisers: false,
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

function connectionStepData(events) {
    const data = [];
    let currentState = events.length > 0 && events[0].type === 'device.disconnected' ? 1 : 0;

    for (const event of events) {
        const time = eventTime(event);
        if (!time) continue;
        const newState = event.type === 'device.connected' ? 1 : 0;
        const label = newState ? 'Ligado' : 'Desligado';
        const color = newState ? '#198754' : '#dc3545';
        data.push({date: time, value: currentState, label, bulletColor: color});
        currentState = newState;
        data.push({date: time, value: currentState, label, bulletColor: color});
    }

    return data;
}

function expectedReplies(command) {
    return Array.isArray(command.expectedReplyTypes) && command.expectedReplyTypes.length
        ? `À espera de ${command.expectedReplyTypes.join(', ')}`
        : '';
}

async function sendCommand(command) {
    state.loadingCommands.add(command);
    renderSelection();
    try {
        const result = await api.sendCommand(state.selectedImei, command);
        if (result.error) alert(result.error.message || result.error.code);
        if (state.selectedImei) {
            await loadDevice(state.selectedImei);
        }
    } finally {
        state.loadingCommands.delete(command);
        renderSelection();
    }
}

async function openAddDevice() {
    await ensureModelsLoaded();
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
    els.deviceLicenseId.value = '0';
    els.deviceLicenseId.disabled = true;
    els.deviceDeviceId.value = '';
    renderDeviceSelectors();
    deviceModal.show();
}

async function editDevice(imei, supplier, model) {
    await ensureModelsLoaded();
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
    els.deviceLicenseId.value = '0';
    els.deviceLicenseId.disabled = true;
    renderDeviceSelectors(supplier, model);
    renderDeviceConfigurationModal();
    renderDeviceSimNumberField('');
    deviceModal.show();

    try {
        const detail = await api.device(imei);
        const device = detail.device || {};
        renderDeviceTypeSelector(String(device.deviceType || 'watch'));
        els.deviceLicenseId.value = String(device.licenseId || '0');
        els.deviceLicenseId.disabled = normalizeDeviceType(String(device.deviceType || 'watch')) === 'watch';
        state.deviceModal.deviceType = normalizeDeviceType(String(device.deviceType || 'watch'));
        state.deviceModal.licenseId = String(device.licenseId || '0');
        renderDeviceSimNumberField(String(device.simNumber || ''));
        state.deviceModal.simNumber = String(device.simNumber || '');
        els.deviceDeviceId.value = String(device.deviceId || '');
        state.deviceModal.deviceId = String(device.deviceId || '');
        await refreshDeviceModalConfigurations(false);
    } finally {
        state.deviceModal.loading = false;
        syncDeviceModalContext();
        renderDeviceConfigurationModal();
    }
}

function renderDeviceSelectors(selectedSupplier = '', selectedModel = '') {
    const suppliers = suppliersFromModels();
    const supplier = suppliers.includes(selectedSupplier) ? selectedSupplier : (suppliers[0] || '');
    const models = modelsForSupplier(supplier);
    const availableModelNames = models.map(model => model.model);
    const model = availableModelNames.includes(selectedModel) ? selectedModel : (availableModelNames[0] || '');

    els.deviceForm.dataset.supplier = supplier;
    els.deviceForm.dataset.model = model;

    renderButtonGroup(els.deviceSupplierButtons, suppliers.map(value => ({value, label: value})), supplier, 'selectDeviceSupplier');
    renderButtonGroup(els.deviceModelButtons, models.map(entry => ({value: entry.model, label: entry.model})), model, 'selectDeviceModel');
    updateDevicePreview();
    syncDeviceModalContext();
    renderDeviceConfigurationModal();
}

function renderDeviceTypeSelector(selectedType = 'watch') {
    const deviceType = normalizeDeviceType(selectedType);
    els.deviceForm.dataset.deviceType = deviceType;
    renderButtonGroup(els.deviceTypeButtons, deviceTypeOptions, deviceType, 'selectDeviceType');

    const showImeiSim = deviceType === 'watch';
    els.deviceImeiRow?.classList.toggle('d-none', !showImeiSim);
    els.deviceSimRow?.classList.toggle('d-none', !showImeiSim);

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
    state.deviceModal.deviceId = els.deviceDeviceId.value.trim();
    if (!state.deviceModal.activeCategory || !state.deviceModal.catalog.some(entry => entry.category === state.deviceModal.activeCategory)) {
        state.deviceModal.activeCategory = state.deviceModal.catalog[0]?.category || '';
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
    const rawLicenseId = els.deviceLicenseId.value.trim();
    const licenseId = deviceType === 'watch' ? '0' : rawLicenseId;
    const deviceId = els.deviceDeviceId.value.trim();
    const supplier = els.deviceForm.dataset.supplier || '';
    const model = els.deviceForm.dataset.model || '';

    if (deviceType === 'ncs') {
        if (!deviceId || !supplier || !model) { alert('Device ID (MAC), fornecedor e modelo são obrigatórios'); return; }
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
    }

    const originalImei = els.deviceImei.dataset.originalImei || '';
    if (deviceType !== 'watch' && !licenseId) { alert('A licença é obrigatória para NCS e Radars'); return; }

    const result = await api.saveDevice(imei, supplier, model, deviceType, licenseId, simNumber, deviceId, originalImei);
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

async function loadSuppliers() {
    const data = await api.suppliers({limit: 500});
    els.supplierForm.reset();
    els.supplierListBody.innerHTML = (data.data || []).map(supplier => `
        <tr>
        <td>${esc(supplier.name)}</td>
        <td>${supplier.model_count}</td>
        <td><span class="badge ${supplier.enabled ? 'text-bg-success' : 'text-bg-secondary'}">${supplier.enabled ? 'ativo' : 'inativo'}</span></td>
        <td>
        <button class="btn btn-outline-${supplier.enabled ? 'warning' : 'success'} btn-sm" data-id="${supplier.id}" data-enabled="${supplier.enabled ? '1' : ''}" data-action="toggleSupplier" title="${supplier.enabled ? 'Desativar' : 'Ativar'}"><i class="fa-solid fa-${supplier.enabled ? 'pause' : 'play'}"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${supplier.id}" data-action="deleteSupplier" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`).join('');
    supplierModal.show();
}

async function saveSupplier() {
    const name = els.supplierName.value.trim();
    if (!name) { alert('O nome é obrigatório'); return; }
    const result = await api.saveSupplier(name);
    if (result.error) { alert(result.error.message || result.error.code); return; }
    els.supplierName.value = '';
    await loadSuppliers();
}

async function toggleSupplier(id, enabled) {
    const result = await api.updateSupplier(id, !enabled);
    if (result.error) { alert(result.error.message || result.error.code); return; }
    await loadSuppliers();
}

async function deleteSupplier(id) {
    if (!confirm('Apagar fornecedor?')) return;
    const result = await api.deleteSupplier(id);
    if (result.error) { alert(result.error.message || result.error.code); return; }
    await loadSuppliers();
}

async function loadModels() {
    const [modelsData, suppliersData] = await Promise.all([api.models({limit: 500}), api.suppliers({limit: 500})]);
    state.summary.models = modelsData.data || [];
    state.modelModalSuppliers = suppliersData.data || [];
    resetModelForm();
    els.modelListBody.innerHTML = (modelsData.data || []).map(model => `
        <tr>
        <td>${modelImageHtml(model)}</td>
        <td>${esc(model.supplier)}</td>
        <td>${esc(model.model)}</td>
        <td>
        <button class="btn btn-outline-secondary btn-sm" data-id="${model.id}" data-supplier-id="${model.supplier_id}" data-supplier="${esc(model.supplier)}" data-model="${esc(model.model)}" data-image="${esc(model.image || '')}" data-action="editModel" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${model.id}" data-action="deleteModel" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`).join('');
    modelModal.show();
}

function resetModelForm(selectedSupplierId = '') {
    revokeModelPreviewUrl();
    els.modelForm.reset();
    delete els.modelForm.dataset.modelId;
    delete els.modelForm.dataset.image;
    els.modelModalLabel.textContent = 'Modelos';
    els.saveModelBtn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar';

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

function editModel(id, supplierId, supplier, model, image) {
    revokeModelPreviewUrl();
    els.modelForm.dataset.modelId = String(id);
    els.modelForm.dataset.supplierId = String(supplierId);
    els.modelForm.dataset.supplier = supplier;
    els.modelForm.dataset.image = image || '';
    els.modelModel.value = model;
    els.modelImage.value = '';
    els.modelModalLabel.textContent = 'Editar modelo';
    els.saveModelBtn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar';
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

function updateModelProtocolAndPreview() {
    const supplier = els.modelForm.dataset.supplier || '';
    const model = els.modelModel.value.trim();
    const image = els.modelForm.dataset.image || '';
    const modelInfo = image ? {image, model: model || 'Modelo'} : null;
    if (!state.modelPreviewObjectUrl) {
        els.modelPreview.innerHTML = modelPreviewHtml(modelInfo, model || supplier || 'Novo modelo');
    }
}

async function saveModel() {
    const supplierId = parseInt(els.modelForm.dataset.supplierId || '0');
    const model = els.modelModel.value.trim();
    if (!supplierId || !model) { alert('Fornecedor e modelo são obrigatórios'); return; }

    const body = new FormData();
    body.append('supplier_id', String(supplierId));
    body.append('model', model);
    if (els.modelImage.files[0]) {
        body.append('image', els.modelImage.files[0]);
    }

    const result = await api.saveModel(els.modelForm.dataset.modelId || '', body);
    if (result.error) { alert(result.error.message || result.error.code); return; }

    await loadModels();
}

async function deleteModel(id) {
    if (!confirm('Apagar modelo?')) return;
    await api.deleteModel(id);
    await loadModels();
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
        detailTitle: document.getElementById('detailTitle'),
        detailMeta: document.getElementById('detailMeta'),
        detailBadge: document.getElementById('detailBadge'),
        telemetryCount: document.getElementById('telemetryCount'),
        telemetryList: document.getElementById('telemetryList'),
        telemetryPager: document.getElementById('telemetry'),
        telemetryPagerSummary: document.getElementById('telemetrySummary'),
        telemetryPagerControls: document.getElementById('telemetryControls'),
        requestCardCount: document.getElementById('requestCardCount'),
        requestGrid: document.getElementById('requestGrid'),
        downlinkRequests: document.getElementById('downlinkRequests'),
        connectionTimeline: document.getElementById('connectionTimeline'),
        connectionStats: document.getElementById('connectionStats'),
        toggleDetailFiltersBtn: document.getElementById('toggleDetailFiltersBtn'),
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
        deviceDeviceId: document.getElementById('deviceDeviceId'),
        deviceDeviceIdLabel: document.getElementById('deviceDeviceIdLabel'),
        deviceDeviceIdHelp: document.getElementById('deviceDeviceIdHelp'),
        deviceTypeButtons: document.getElementById('deviceTypeButtons'),
        deviceLicenseId: document.getElementById('deviceLicenseId'),
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
        manageSuppliersBtn: document.getElementById('manageSuppliersBtn'),
        manageModelsBtn: document.getElementById('manageModelsBtn'),
        supplierForm: document.getElementById('supplierForm'),
        supplierName: document.getElementById('supplierName'),
        supplierListBody: document.getElementById('supplierListBody'),
        saveSupplierBtn: document.getElementById('saveSupplierBtn'),
        modelModalLabel: document.getElementById('modelModalLabel'),
        modelForm: document.getElementById('modelForm'),
        modelPreview: document.getElementById('modelPreview'),
        modelSupplierButtons: document.getElementById('modelSupplierButtons'),
        modelModel: document.getElementById('modelModel'),
        modelImage: document.getElementById('modelImage'),
        modelListBody: document.getElementById('modelListBody'),
        resetModelBtn: document.getElementById('resetModelBtn'),
        deleteDeviceBtn: document.getElementById('deleteDeviceBtn'),
        saveModelBtn: document.getElementById('saveModelBtn'),
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
        void editDevice(
            state.selectedDetail.device.imei,
            state.selectedDetail.device.supplier || '',
            state.selectedDetail.device.model || ''
        );
    });
    els.saveDeviceBtn.addEventListener('click', saveDevice);
    els.deviceForm.addEventListener('submit', event => { event.preventDefault(); saveDevice(); });
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
    els.manageSuppliersBtn.addEventListener('click', loadSuppliers);
    els.saveSupplierBtn.addEventListener('click', saveSupplier);
    els.supplierForm.addEventListener('submit', event => { event.preventDefault(); saveSupplier(); });
    els.manageModelsBtn.addEventListener('click', loadModels);
    els.saveModelBtn.addEventListener('click', saveModel);
    els.resetModelBtn.addEventListener('click', () => resetModelForm());
    els.modelForm.addEventListener('submit', event => { event.preventDefault(); saveModel(); });
    els.modelModel.addEventListener('input', () => updateModelProtocolAndPreview());
    els.modelImage.addEventListener('change', handleModelImageChange);
    els.telemetryPager.addEventListener('click', handleTelemetryPagerClick);
    els.toggleDetailFiltersBtn.addEventListener('click', toggleDetailFilters);
    els.applyDetailFiltersBtn.addEventListener('click', applyDetailFilters);
    els.clearDetailFiltersBtn.addEventListener('click', clearDetailFilters);
    els.deleteDeviceBtn.addEventListener('click', handleDeleteDeviceBtnClick);
    els.deviceSupplierButtons.addEventListener('click', handleDeviceSupplierClick);
    els.deviceTypeButtons.addEventListener('click', handleDeviceTypeClick);
    els.deviceModelButtons.addEventListener('click', handleDeviceModelClick);
    els.modelSupplierButtons.addEventListener('click', handleModelSupplierClick);
    els.deviceList.addEventListener('click', handleDeviceListClick);
    els.deviceListPagination.addEventListener('click', handleDevicePaginationClick);
    els.requestGrid.addEventListener('click', handleRequestGridClick);
    els.supplierListBody.addEventListener('click', handleSupplierListClick);
    els.modelListBody.addEventListener('click', handleModelListClick);
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
        els.modelPreview.innerHTML = `<img src="${esc(state.modelPreviewObjectUrl)}" class="object-fit-contain" alt="${esc(els.modelModel.value.trim() || 'Modelo')}">`;
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
    if (deviceType === 'watch') {
        els.deviceLicenseId.value = '0';
        els.deviceLicenseId.disabled = true;
    } else {
        els.deviceLicenseId.disabled = false;
        if (els.deviceLicenseId.value.trim() === '0') {
            els.deviceLicenseId.value = '';
        }
    }
    syncDeviceModalContext();
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

function handleDeviceListClick(event) {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const {action, imei} = button.dataset;
    if (action === 'select') selectDevice(imei);
}

function handleRequestGridClick(event) {
    const button = event.target.closest('[data-action="sendCommand"]');
    if (button) sendCommand(button.dataset.command);
}

function handleSupplierListClick(event) {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const {id, action, enabled} = button.dataset;
    if (action === 'toggleSupplier') toggleSupplier(parseInt(id), !!enabled);
    if (action === 'deleteSupplier') deleteSupplier(parseInt(id));
}

function handleModelListClick(event) {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    if (button.dataset.action === 'editModel') {
        editModel(parseInt(button.dataset.id), parseInt(button.dataset.supplierId), button.dataset.supplier, button.dataset.model, button.dataset.image);
    }
    if (button.dataset.action === 'deleteModel') {
        deleteModel(parseInt(button.dataset.id));
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

        state.deviceModal.configurations = result.configuration?.configurations || state.deviceModal.configurations;
        const row = state.deviceModal.configurations.find(entry => entry.config_key === key);
        const rowStatus = String(row?.last_status || '');
        if (['failed', 'dropped'].includes(rowStatus)) {
            setConfigUi(key, {
                phase: 'idle',
                feedback: {tone: 'danger', message: 'O envio da configuração falhou.'},
            });
            renderDeviceConfigurationModal();
            return;
        }

        setConfigUi(key, {
            phase: 'sent',
            trackStatus: true,
            feedback: {tone: 'success', message: 'Configuração enviada ao dispositivo.'},
        });
        renderDeviceConfigurationModal();
        transitionConfigPhase(key, 'sent', 1200, () => {
            clearConfigUiPhase(key, 'sent');
            renderDeviceConfigurationModal();
        });
        scheduleConfigPolling(key);
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

    deviceConfigRefreshPromise = api.configuration(
        state.deviceModal.imei,
        state.deviceModal.supplier,
        state.deviceModal.model
    ).then(result => {
        const current = [
            state.deviceModal.imei,
            state.deviceModal.supplier,
            state.deviceModal.model,
        ].join('|');
        if (snapshot !== current) {
            return result;
        }

        state.deviceModal.configurations = result.configurations || [];
        syncConfigUiWithRows();
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

function syncConfigUiWithRows() {
    for (const row of state.deviceModal.configurations || []) {
        const key = String(row.config_key || '');
        if (!key) continue;

        const ui = state.deviceModal.configUi[key];
        if (ui?.phase === 'submitting' || ui?.phase === 'sent') {
            continue;
        }

        const status = String(row.last_status || '');
        if (!ui?.trackStatus) {
            if (['acked', 'failed', 'dropped'].includes(status)) {
                stopConfigPolling(key);
            }
            continue;
        }

        if (status === 'acked' && !ui?.feedback) {
            setConfigUi(key, {
                feedback: {tone: 'success', message: 'Dispositivo confirmou a configuração.'},
            });
        }
        if (['failed', 'dropped'].includes(status) && !ui?.feedback) {
            setConfigUi(key, {
                feedback: {tone: 'danger', message: 'O dispositivo não confirmou a configuração.'},
            });
        }

        if (['acked', 'failed', 'dropped'].includes(status)) {
            stopConfigPolling(key);
        }
    }
}

function scheduleConfigPolling(key, attempt = 0) {
    stopConfigPolling(key);
    configPollTimers.set(key, setTimeout(async () => {
        if (!document.getElementById('deviceModal')?.classList.contains('show')) {
            stopConfigPolling(key);
            return;
        }

        await refreshDeviceModalConfigurations(true);
        const row = (state.deviceModal.configurations || []).find(entry => entry.config_key === key);
        const status = String(row?.last_status || '');
        if (['acked', 'failed', 'dropped'].includes(status) || attempt >= 14) {
            stopConfigPolling(key);
            return;
        }
        scheduleConfigPolling(key, attempt + 1);
    }, 2000));
}

function stopConfigPolling(key) {
    clearTimeout(configPollTimers.get(key));
    configPollTimers.delete(key);
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

    for (const timer of configPollTimers.values()) {
        clearTimeout(timer);
    }
    configPollTimers.clear();

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
    supplierModal = new bootstrap.Modal(document.getElementById('supplierModal'));
    modelModal = new bootstrap.Modal(document.getElementById('modelModal'));
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
}
