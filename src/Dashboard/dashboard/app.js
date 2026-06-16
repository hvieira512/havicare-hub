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
    supplierProtocolDefaults,
    uplinkCardContent,
} from './renderers.js';
import {
    catalogForProtocol,
    readConfigPayload,
    renderDeviceConfigurationRoot,
} from './config.js';

let els = {};
let deviceModal = null;
let supplierModal = null;
let modelModal = null;

function supplierProtocol(supplier, models = state.summary.models) {
    const existing = models.find(model => model.supplier === supplier && model.protocol);
    return existing?.protocol || supplierProtocolDefaults[supplier] || '';
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
    state.summary = await api.summary();
    renderSummary();
    if (state.selectedImei) {
        await loadDevice(state.selectedImei);
    }
}

function renderSummary() {
    els.hubCounts.textContent = `${state.summary.counts?.online ?? 0} ligados / ${state.summary.counts?.offline ?? 0} desligados`;

    const modelLookup = {};
    for (const model of state.summary.models) {
        modelLookup[`${model.supplier}:${model.model}`] = model;
    }

    const groups = {};
    for (const device of state.summary.devices) {
        const key = `${device.supplier} / ${device.model}`;
        if (!groups[key]) groups[key] = {supplier: device.supplier, model: device.model, devices: []};
        groups[key].devices.push(device);
    }

    els.deviceList.innerHTML = Object.values(groups).map(group => {
        const modelInfo = modelLookup[`${group.supplier}:${group.model}`];
        return `
            <div class="list-group list-group-flush">
            <div class="small fw-semibold text-secondary px-3 py-1 bg-body-tertiary border-bottom">${esc(group.supplier)} ${esc(group.model)}</div>
            ${group.devices.map(device => `
                <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom${state.selectedImei === device.imei ? ' bg-primary-subtle' : ''}" data-imei="${esc(device.imei)}" data-action="select">
                <div style="width:40px;text-align:center;flex-shrink:0">${modelImageHtml(modelInfo)}</div>
                <div class="flex-grow-1 min-width-0">
                <div class="d-flex justify-content-between align-items-center">
                <strong class="small text-break">${esc(device.imei)}</strong>
                </div>
                <div class="small text-secondary d-flex align-items-center gap-1"><span class="rounded-circle ${device.online ? 'bg-success' : 'bg-danger'} d-inline-block" style="width:.55rem;height:.55rem;"></span><span>visto ${ago(device.lastSeenAt)}</span></div>
                </div>
                <div class="btn-group btn-group-sm" style="flex-shrink:0">
                <button class="btn btn-outline-secondary" data-imei="${esc(device.imei)}" data-supplier="${esc(device.supplier)}" data-model="${esc(device.model)}" data-action="edit" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-outline-danger" data-imei="${esc(device.imei)}" data-action="delete" title="Apagar"><i class="fa-solid fa-trash"></i></button>
                </div>
                </div>`).join('')}
            </div>`;
    }).join('');

    renderSelection();
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
    els.deviceColumn.className = state.selectedImei ? 'col-12 col-lg-3' : 'col-12 col-lg-4';
    els.detailColumn.className = state.selectedImei ? 'col-12 col-lg-9' : 'col-12 col-lg-8';
    els.emptyState.classList.toggle('d-none', !!state.selectedDetail);
    els.deviceDetail.classList.toggle('d-none', !state.selectedDetail);
    if (!state.selectedDetail) return;

    const device = state.selectedDetail.device;
    els.detailTitle.textContent = device.imei;
    els.detailMeta.textContent = `${device.supplier ?? ''} ${device.model ?? ''} · ${device.protocol ?? 'desconhecido'} · visto ${ago(device.lastSeenAt)}`;
    els.detailBadge.className = `badge ${device.online ? 'text-bg-success' : 'text-bg-secondary'}`;
    els.detailBadge.textContent = device.online ? 'ligado' : 'desligado';
    renderTelemetryList(state.selectedDetail.recent.telemetry || []);
    renderRequestCards(state.selectedDetail.commands || []);
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
    if (totalRows <= state.telemetryPageSize) {
        els.telemetryPager.classList.add('d-none');
        els.telemetryPager.innerHTML = '';
        return;
    }

    els.telemetryPager.classList.remove('d-none');
    els.telemetryPager.innerHTML = `
        <button type="button" class="btn btn-outline-secondary btn-sm" data-action="telemetryPrev" ${state.telemetryPage <= 1 ? 'disabled' : ''}>Anterior</button>
        <span class="small text-secondary">Página ${state.telemetryPage} de ${totalPages}</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-action="telemetryNext" ${state.telemetryPage >= totalPages ? 'disabled' : ''}>Seguinte</button>`;
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
    if (data && typeof data === 'object') {
        for (const [key, value] of Object.entries(data)) {
            if (value === undefined || value === null || value === '') continue;
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

function renderRequestCards(commands) {
    els.requestCardCount.textContent = commands.length ? `${commands.length} ações` : '';
    els.requestGrid.innerHTML = commands.length
        ? commands.map(command => renderRequestCardShell(command, state.loadingCommands.has(command.command))).join('')
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
    state.deviceModal = {
        mode: 'create',
        activeTab: 'general',
        activeCategory: '',
        imei: '',
        originalImei: '',
        supplier: '',
        model: '',
        protocol: '',
        catalog: [],
        configurations: [],
        loading: false,
    };
    renderDeviceSelectors();
    renderDeviceConfigurationModal();
    deviceModal.show();
}

async function editDevice(imei, supplier, model) {
    els.deviceModalLabel.textContent = 'Editar dispositivo';
    els.deviceImei.value = imei;
    els.deviceImei.dataset.originalImei = imei;
    state.deviceModal = {
        mode: 'edit',
        activeTab: 'general',
        activeCategory: '',
        imei,
        originalImei: imei,
        supplier,
        model,
        protocol: '',
        catalog: [],
        configurations: [],
        loading: true,
    };
    renderDeviceSelectors(supplier, model);
    renderDeviceConfigurationModal();
    deviceModal.show();

    try {
        const configuration = await api.configuration(imei, supplier, model);
        state.deviceModal.configurations = configuration.configurations || [];
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

function updateDevicePreview() {
    const supplier = els.deviceForm.dataset.supplier || '';
    const model = els.deviceForm.dataset.model || '';
    const modelInfo = findModelInfo(supplier, model);
    els.devicePreview.innerHTML = modelPreviewHtml(modelInfo, model || 'Selecione um modelo');
    els.deviceProtocolText.textContent = modelInfo?.protocol || supplierProtocol(supplier) || '-';
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
        supplier: state.deviceModal.supplier,
        model: state.deviceModal.model,
        activeCategory: state.deviceModal.activeCategory,
        disabled: !state.deviceModal.protocol,
    });
}

async function saveDevice() {
    const imei = els.deviceImei.value.trim();
    const supplier = els.deviceForm.dataset.supplier || '';
    const model = els.deviceForm.dataset.model || '';
    if (!imei || !supplier || !model) { alert('Todos os campos são obrigatórios'); return; }

    const result = await api.saveDevice(imei, supplier, model, els.deviceImei.dataset.originalImei || '');
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

async function loadSuppliers() {
    const data = await api.suppliers();
    els.supplierForm.reset();
    els.supplierListBody.innerHTML = data.suppliers.map(supplier => `
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
    const [modelsData, suppliersData] = await Promise.all([api.models(), api.suppliers()]);
    state.summary.models = modelsData.models;
    state.modelModalSuppliers = suppliersData.suppliers;
    resetModelForm();
    els.modelListBody.innerHTML = modelsData.models.map(model => `
        <tr>
        <td>${modelImageHtml(model)}</td>
        <td>${esc(model.supplier)}</td>
        <td>${esc(model.model)}</td>
        <td>${esc(model.protocol)}</td>
        <td>
        <button class="btn btn-outline-secondary btn-sm" data-id="${model.id}" data-supplier-id="${model.supplier_id}" data-supplier="${esc(model.supplier)}" data-model="${esc(model.model)}" data-protocol="${esc(model.protocol)}" data-image="${esc(model.image || '')}" data-action="editModel" title="Editar"><i class="fa-solid fa-pen"></i></button>
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

function editModel(id, supplierId, supplier, model, protocol, image) {
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
    updateModelProtocolAndPreview(protocol);
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

function updateModelProtocolAndPreview(protocolOverride = '') {
    const supplier = els.modelForm.dataset.supplier || '';
    const model = els.modelModel.value.trim();
    const protocol = protocolOverride || supplierProtocol(supplier) || '';
    const image = els.modelForm.dataset.image || '';
    const modelInfo = image ? {image, model: model || 'Modelo'} : null;
    els.modelProtocolText.textContent = protocol || '-';
    if (!state.modelPreviewObjectUrl) {
        els.modelPreview.innerHTML = modelPreviewHtml(modelInfo, model || supplier || 'Novo modelo');
    }
}

async function saveModel() {
    const supplierId = parseInt(els.modelForm.dataset.supplierId || '0');
    const supplier = els.modelForm.dataset.supplier || '';
    const model = els.modelModel.value.trim();
    const protocol = supplierProtocol(supplier);
    if (!supplierId || !model || !protocol) { alert('Todos os campos são obrigatórios'); return; }

    const body = new FormData();
    body.append('supplier_id', String(supplierId));
    body.append('model', model);
    body.append('protocol', protocol);
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
        hubCounts: document.getElementById('hubCounts'),
        deviceColumn: document.getElementById('deviceColumn'),
        deviceList: document.getElementById('deviceList'),
        detailColumn: document.getElementById('detailColumn'),
        emptyState: document.getElementById('emptyState'),
        deviceDetail: document.getElementById('deviceDetail'),
        detailTitle: document.getElementById('detailTitle'),
        detailMeta: document.getElementById('detailMeta'),
        detailBadge: document.getElementById('detailBadge'),
        telemetryCount: document.getElementById('telemetryCount'),
        telemetryList: document.getElementById('telemetryList'),
        telemetryPager: document.getElementById('telemetryPager'),
        requestCardCount: document.getElementById('requestCardCount'),
        requestGrid: document.getElementById('requestGrid'),
        downlinkRequests: document.getElementById('downlinkRequests'),
        connectionLogs: document.getElementById('connectionLogs'),
        addDeviceBtn: document.getElementById('addDeviceBtn'),
        deviceModalLabel: document.getElementById('deviceModalLabel'),
        deviceForm: document.getElementById('deviceForm'),
        deviceImei: document.getElementById('deviceImei'),
        devicePreview: document.getElementById('devicePreview'),
        deviceSupplierButtons: document.getElementById('deviceSupplierButtons'),
        deviceModelButtons: document.getElementById('deviceModelButtons'),
        deviceProtocolText: document.getElementById('deviceProtocolText'),
        deviceConfigRoot: document.getElementById('deviceConfigRoot'),
        saveDeviceBtn: document.getElementById('saveDeviceBtn'),
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
        modelProtocolText: document.getElementById('modelProtocolText'),
        modelImage: document.getElementById('modelImage'),
        modelListBody: document.getElementById('modelListBody'),
        resetModelBtn: document.getElementById('resetModelBtn'),
        saveModelBtn: document.getElementById('saveModelBtn'),
    };
}

function bindEvents() {
    els.addDeviceBtn.addEventListener('click', openAddDevice);
    els.saveDeviceBtn.addEventListener('click', saveDevice);
    els.deviceForm.addEventListener('submit', event => { event.preventDefault(); saveDevice(); });
    els.deviceImei.addEventListener('input', handleDeviceImeiInput);
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
    els.deviceSupplierButtons.addEventListener('click', handleDeviceSupplierClick);
    els.deviceModelButtons.addEventListener('click', handleDeviceModelClick);
    els.modelSupplierButtons.addEventListener('click', handleModelSupplierClick);
    els.deviceList.addEventListener('click', handleDeviceListClick);
    els.requestGrid.addEventListener('click', handleRequestGridClick);
    els.supplierListBody.addEventListener('click', handleSupplierListClick);
    els.modelListBody.addEventListener('click', handleModelListClick);
    els.deviceConfigRoot.addEventListener('click', handleDeviceConfigClick);
    els.deviceConfigRoot.addEventListener('change', handleDeviceConfigChange);
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

function handleTelemetryPagerClick(event) {
    const button = event.target.closest('[data-action]');
    if (!button || !state.selectedDetail) return;
    const rows = (state.selectedDetail.recent.telemetry || []).filter(row => rowPayload(row) && !rowPayload(row).debug);
    const totalPages = Math.max(1, Math.ceil(rows.length / state.telemetryPageSize));
    if (button.dataset.action === 'telemetryPrev') setTelemetryPage(state.telemetryPage - 1, totalPages);
    if (button.dataset.action === 'telemetryNext') setTelemetryPage(state.telemetryPage + 1, totalPages);
    renderTelemetryList(state.selectedDetail.recent.telemetry || []);
}

function handleDeviceConfigClick(event) {
    const button = event.target.closest('[data-action]');
    if (!button) return;

    if (button.dataset.configCategory) {
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
    const select = event.target.closest('[data-working-mode-select]');
    if (!select) return;

    const section = select.closest('[data-config-section]');
    if (!section) return;

    const extra = section.querySelector('[data-working-mode-extra]');
    if (extra) {
        extra.classList.toggle('d-none', String(select.value) !== '8');
    }
}

function handleDeviceSupplierClick(event) {
    const button = event.target.closest('[data-action="selectDeviceSupplier"]');
    if (button) renderDeviceSelectors(button.dataset.value, '');
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
    if (action === 'delete') { event.stopPropagation(); deleteDevice(imei); }
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
        editModel(parseInt(button.dataset.id), parseInt(button.dataset.supplierId), button.dataset.supplier, button.dataset.model, button.dataset.protocol, button.dataset.image);
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

    const result = await api.saveConfiguration(
        state.deviceModal.imei,
        {[key]: payload},
        state.deviceModal.supplier,
        state.deviceModal.model
    );
    if (result.error) {
        alert(result.error.message || result.error.code);
        return;
    }

    state.deviceModal.configurations = result.configuration?.configurations || state.deviceModal.configurations;
    renderDeviceConfigurationModal();
}

function appendContactRow(section) {
    const list = section.querySelector('[data-repeat-limit]');
    if (!list) return;

    const limit = parseInt(list.dataset.repeatLimit || '10', 10);
    const rows = list.querySelectorAll('[data-repeat-row="contacts"]');
    if (rows.length >= limit) return;

    const template = rows[rows.length - 1] || createContactRow();
    const clone = template.cloneNode(true);
    clone.querySelectorAll('input').forEach(input => { input.value = ''; });
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
            } else {
                input.value = '';
            }
        });
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
                <input class="form-control" type="text" placeholder="Telefone" data-repeat-field="phone">
                <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeContactRow">-</button>
            </div>
        </div>`;
    return wrapper;
}

function createReminderRow() {
    const wrapper = document.createElement('div');
    wrapper.className = 'border rounded p-3 bg-body';
    wrapper.dataset.repeatRow = 'reminders';
    wrapper.innerHTML = `
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label form-label-sm">Hora</label>
                <input class="form-control" type="text" placeholder="08:30" data-repeat-field="time">
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm">Dias</label>
                <input class="form-control" type="text" placeholder="1234567" data-repeat-field="days">
            </div>
            <div class="col-md-2">
                <div class="form-check form-switch mt-4">
                    <input class="form-check-input" type="checkbox" role="switch" data-repeat-field="enabled" checked>
                    <label class="form-check-label">Ativo</label>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm">Tipo</label>
                <select class="form-select" data-repeat-field="type">
                    <option value="1">Tipo 1</option>
                    <option value="2">Tipo 2</option>
                    <option value="3">Tipo 3</option>
                </select>
            </div>
            <div class="col-md-1 text-end">
                <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeReminderRow">-</button>
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
    loadSummary();
    setInterval(loadSummary, 5000);
}
