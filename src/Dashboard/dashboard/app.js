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

let els = {};
let deviceModal = null;
let supplierModal = null;
let modelModal = null;
const configFeedbackTimers = new Map();
const configPhaseTimers = new Map();
const configPollTimers = new Map();
let deviceConfigRefreshPromise = null;
let deviceSearchTimer = null;
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
    const [devicesResponse, modelsResponse] = await Promise.all([
        api.devices({
            page: state.deviceListPage,
            limit: state.deviceListPageSize,
            deviceType: state.deviceFilters.deviceType,
            licenseId: state.deviceFilters.licenseId,
            supplier: state.deviceFilters.supplier,
            model: state.deviceFilters.model,
            q: state.deviceSearchQuery,
        }),
        api.models({limit: 500}),
    ]);
    state.summary = {
        devices: devicesResponse.data || [],
        models: modelsResponse.data || [],
        devicePagination: devicesResponse.pagination || {limit: state.deviceListPageSize, page: 1, total_pages: 1, total: 0},
        deviceFiltersAvailable: devicesResponse.filters?.available || {deviceType: [], licenseId: [], supplier: [], model: []},
    };
    state.deviceListPageSize = state.summary.devicePagination.limit || state.deviceListPageSize;
    state.deviceListPage = state.summary.devicePagination.page || 1;
    renderSummary();
    if (state.selectedImei) {
        await loadDevice(state.selectedImei);
    }
}

function renderSummary() {
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
                <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom${state.selectedImei === device.imei ? ' bg-primary-subtle' : ''}" data-imei="${esc(device.imei)}" data-action="select">
                <div style="width:40px;text-align:center;flex-shrink:0">${modelImageHtml(modelInfo)}</div>
                <div class="flex-grow-1 min-width-0 d-flex align-items-center gap-2">
                <span class="rounded-circle ${device.online ? 'bg-success' : 'bg-danger'} d-inline-block flex-shrink-0" style="width:.55rem;height:.55rem;"></span>
                <strong class="small text-break">${esc(device.imei)}</strong>
                </div>
                <div class="btn-group btn-group-sm" style="flex-shrink:0">
                <button class="btn btn-outline-secondary" data-imei="${esc(device.imei)}" data-supplier="${esc(device.supplier)}" data-model="${esc(device.model)}" data-action="edit" title="Editar">
                <i class="fa-solid fa-pen"></i>
                </button>
                </div>
                </div>`).join('')}
            </div>`;
    }).join('');

    els.deviceList.innerHTML = groupMarkup || emptyPanel('Não há dispositivos para o filtro selecionado.');
    renderDevicePagination(state.summary.devicePagination);

    renderSelection();
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

    els.deviceFiltersPanel.classList.toggle('d-none', !state.filtersOpen);
    els.toggleDeviceFiltersBtn.classList.toggle('btn-outline-secondary', !state.filtersOpen);
    els.toggleDeviceFiltersBtn.classList.toggle('btn-secondary', state.filtersOpen);
    els.toggleDeviceFiltersBtn.setAttribute('aria-expanded', state.filtersOpen ? 'true' : 'false');

    renderSelectOptions(els.deviceTypeFilter, options.deviceType || [], state.pendingDeviceFilters.deviceType, value => deviceTypeLabel(value));
    renderSelectOptions(els.deviceLicenseFilter, options.licenseId || [], state.pendingDeviceFilters.licenseId, value => value);
    renderSelectOptions(els.deviceSupplierFilter, options.supplier || [], state.pendingDeviceFilters.supplier, value => value);
    renderSelectOptions(els.deviceModelFilter, options.model || [], state.pendingDeviceFilters.model, value => value);
}

function syncPendingDeviceFiltersFromControls() {
    state.pendingDeviceFilters = {
        deviceType: normalizeFilterValue(els.deviceTypeFilter.value),
        licenseId: normalizeFilterValue(els.deviceLicenseFilter.value),
        supplier: normalizeFilterValue(els.deviceSupplierFilter.value),
        model: normalizeFilterValue(els.deviceModelFilter.value),
    };
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
    await loadDevice(imei);
}

async function loadDevice(imei) {
    const detail = await api.device(imei);
    state.selectedDetail = detail;
    renderSelection();
}

function renderSelection() {
    els.deviceColumn.className = 'col-12 col-lg-4';
    els.detailColumn.className = 'col-12 col-lg-8';
    els.emptyState.classList.toggle('d-none', !!state.selectedDetail);
    els.deviceDetail.classList.toggle('d-none', !state.selectedDetail);
    els.requestColumn.classList.toggle('d-none', !state.selectedDetail);
    if (!state.selectedDetail) return;

    const device = state.selectedDetail.device;
    els.detailTitle.textContent = device.imei;
    els.detailMeta.textContent = `${deviceTypeLabel(normalizeDeviceType(device.deviceType))} · licença ${device.licenseId ?? '0'} · ${device.supplier ?? ''} ${device.model ?? ''} · visto ${ago(device.lastSeenAt)}`;
    els.detailBadge.className = `badge ${device.online ? 'text-bg-success' : 'text-bg-secondary'}`;
    els.detailBadge.textContent = device.online ? 'ligado' : 'desligado';
    const ncsEvents = (state.selectedDetail.recent.events || [])
        .map(rowPayload)
        .filter(p => p?.type === 'ncs.event');
    const allTelemetry = [...(state.selectedDetail.recent.telemetry || []), ...ncsEvents];
    renderTelemetryList(allTelemetry);
    renderRequestCards(state.selectedDetail.commands || [], state.selectedDetail.recent.telemetry || []);
    renderDownlinkRequests(state.selectedDetail.recent.commands || []);
    renderConnectionLogs(state.selectedDetail.recent.events || []);
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

function renderConnectionLogs(events) {
    const logs = events
        .map(rowPayload)
        .filter(event => ['device.connected', 'device.disconnected'].includes(String(event?.type || '')))
        .sort((a, b) => eventTime(b) - eventTime(a));

    els.connectionLogs.innerHTML = logs.length ? `
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
        <thead>
        <tr><th>Quando</th><th>Estado</th><th>Fornecedor</th><th>Modelo</th></tr>
        </thead>
        <tbody>
        ${logs.map(renderConnectionLogRow).join('')}
        </tbody>
        </table>
        </div>` : emptyPanel('Ainda não há registos de ligação.');
}

function renderConnectionLogRow(event) {
    const connected = event.type === 'device.connected';
    const device = event.device || {};
    return `
        <tr>
        <td class="text-nowrap small">${esc(when(event.occurredAt || event.recordedAt) || '-')}</td>
        <td><span class="badge ${connected ? 'text-bg-success' : 'text-bg-secondary'}">${connected ? 'ligado' : 'desligado'}</span></td>
        <td>${esc(device.supplier || '-')}</td>
        <td>${esc(device.model || '-')}</td>
        </tr>`;
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
        await loadSummary();
    } finally {
        state.loadingCommands.delete(command);
        renderSelection();
    }
}

function openAddDevice() {
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

    if (deviceType !== 'watch' && !licenseId) { alert('A licença é obrigatória para NCS e Radars'); return; }

    const result = await api.saveDevice(imei, supplier, model, deviceType, licenseId, simNumber, deviceId, els.deviceImei.dataset.originalImei || '');
    if (result.error) { alert(result.error.message || result.error.code); return; }

    deviceModal.hide();
    await loadSummary();
}

async function deleteDevice(imei) {
    if (!confirm(`Apagar o dispositivo ${imei}?`)) return;
    await api.deleteDevice(imei);
    if (state.selectedImei === imei) {
        clearSelection();
    }
    await loadSummary();
}

function handleDeleteDeviceBtnClick() {
    const imei = els.deleteDeviceBtn.dataset.imei;
    if (!imei) return;
    if (!confirm(`Apagar o dispositivo ${imei}?`)) return;
    api.deleteDevice(imei).then(() => {
        deviceModal.hide();
        if (state.selectedImei === imei) {
            clearSelection();
        }
        loadSummary();
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
        requestColumn: document.getElementById('requestColumn'),
        deviceList: document.getElementById('deviceList'),
        deviceListLimit: document.getElementById('deviceListLimit'),
        deviceListSearch: document.getElementById('deviceListSearch'),
        deviceListPagination: document.getElementById('deviceListPagination'),
        deviceListPaginationSummary: document.getElementById('deviceListPaginationSummary'),
        deviceListPaginationControls: document.getElementById('deviceListPaginationControls'),
        detailColumn: document.getElementById('detailColumn'),
        emptyState: document.getElementById('emptyState'),
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
        connectionLogs: document.getElementById('connectionLogs'),
        addDeviceBtn: document.getElementById('addDeviceBtn'),
        toggleDeviceFiltersBtn: document.getElementById('toggleDeviceFiltersBtn'),
        deviceFiltersPanel: document.getElementById('deviceFiltersPanel'),
        deviceModalLabel: document.getElementById('deviceModalLabel'),
        deviceForm: document.getElementById('deviceForm'),
        deviceImei: document.getElementById('deviceImei'),
        deviceTypeFilter: document.getElementById('deviceTypeFilter'),
        deviceLicenseFilter: document.getElementById('deviceLicenseFilter'),
        deviceSupplierFilter: document.getElementById('deviceSupplierFilter'),
        deviceModelFilter: document.getElementById('deviceModelFilter'),
        applyDeviceFiltersBtn: document.getElementById('applyDeviceFiltersBtn'),
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
    els.addDeviceBtn.addEventListener('click', openAddDevice);
    els.saveDeviceBtn.addEventListener('click', saveDevice);
    els.deviceForm.addEventListener('submit', event => { event.preventDefault(); saveDevice(); });
    els.deviceListLimit.addEventListener('change', handleDeviceListLimitChange);
    els.deviceListSearch.addEventListener('input', handleDeviceListSearchInput);
    els.toggleDeviceFiltersBtn.addEventListener('click', toggleDeviceFilters);
    els.deviceTypeFilter.addEventListener('change', handlePendingDeviceFilterChange);
    els.deviceLicenseFilter.addEventListener('change', handlePendingDeviceFilterChange);
    els.deviceSupplierFilter.addEventListener('change', handlePendingDeviceFilterChange);
    els.deviceModelFilter.addEventListener('change', handlePendingDeviceFilterChange);
    els.applyDeviceFiltersBtn.addEventListener('click', applyDeviceFilters);
    els.clearDeviceFiltersBtn.addEventListener('click', clearDeviceFilters);
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

function toggleDeviceFilters() {
    state.filtersOpen = !state.filtersOpen;
    renderDeviceFilterControls();
}

function handlePendingDeviceFilterChange() {
    syncPendingDeviceFiltersFromControls();
    renderDeviceFilterControls();
}

const STORAGE_KEY = 'hub-dashboard-device-filters';

function loadFiltersFromStorage() {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
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
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state.deviceFilters));
    } catch {
    }
}

function clearFiltersFromStorage() {
    try {
        localStorage.removeItem(STORAGE_KEY);
    } catch {
    }
}

async function applyDeviceFilters() {
    syncPendingDeviceFiltersFromControls();
    state.deviceFilters = {...state.pendingDeviceFilters};
    state.deviceListPage = 1;
    saveFiltersToStorage();
    await loadSummary();
}

async function clearDeviceFilters() {
    const defaults = {
        deviceType: null,
        licenseId: null,
        supplier: null,
        model: null,
    };
    state.deviceFilters = {...defaults};
    state.pendingDeviceFilters = {...defaults};
    state.deviceListPage = 1;
    clearFiltersFromStorage();
    await loadSummary();
}

function handleTelemetryPagerClick(event) {
    const button = event.target.closest('[data-action]');
    if (!button || !state.selectedDetail) return;
    const allRows = [
        ...(state.selectedDetail.recent.telemetry || []),
        ...(state.selectedDetail.recent.events || []).map(rowPayload).filter(p => p?.type === 'ncs.event'),
    ];
    const filtered = allRows.filter(row => rowPayload(row) && !rowPayload(row).debug);
    const totalPages = Math.max(1, Math.ceil(filtered.length / state.telemetryPageSize));
    if (button.dataset.action === 'telemetryPrev') setTelemetryPage(state.telemetryPage - 1, totalPages);
    if (button.dataset.action === 'telemetryNext') setTelemetryPage(state.telemetryPage + 1, totalPages);
    if (button.dataset.action === 'telemetryPageGo') setTelemetryPage(parseInt(button.dataset.page || '1', 10), totalPages);
    renderTelemetryList(state.selectedDetail.recent.telemetry || []);
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
    const {action, imei, supplier, model} = button.dataset;
    if (action === 'select') selectDevice(imei);
    if (action === 'edit') { event.stopPropagation(); void editDevice(imei, supplier, model); }
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
    supplierModal = new bootstrap.Modal(document.getElementById('supplierModal'));
    modelModal = new bootstrap.Modal(document.getElementById('modelModal'));
    bindEvents();

    const stored = loadFiltersFromStorage();
    if (stored) {
        state.deviceFilters = stored;
        state.pendingDeviceFilters = {...stored};
    }

    loadSummary();
    setInterval(loadSummary, 5000);
}
