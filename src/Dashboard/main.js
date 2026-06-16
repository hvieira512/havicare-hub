let summary = {devices: [], models: [], counts: {}};
let selectedImei = null;
let selectedDetail = null;

let els = {};
let deviceModal = null;
let supplierModal = null;
let modelModal = null;
let modelModalSuppliers = [];
let modelPreviewObjectUrl = null;
const loadingCommands = new Set();
const supplierProtocolDefaults = {
    Wonlex: 'wonlex-json',
    Vivistar: 'vivistar-iw',
    '4P Touch': 'four-p-touch',
};

const request = (url, options = {}) => fetch(url, Object.assign({headers: {'Content-Type': 'application/json'}}, options)).then(r => r.json());
const formRequest = (url, formData, options = {}) => fetch(url, Object.assign({method: 'POST', body: formData}, options)).then(r => r.json());

const ago = value => {
    if (!value) return 'nunca';
    const seconds = Math.max(0, Math.floor((Date.now() - Date.parse(value)) / 1000));
    if (seconds < 60) return `há ${seconds}s`;
    if (seconds < 3600) return `há ${Math.floor(seconds / 60)}m`;
    if (seconds < 86400) return `há ${Math.floor(seconds / 3600)}h`;
    return `há ${Math.floor(seconds / 86400)}d`;
};

const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));

const titleize = value => String(value ?? 'desconhecido').replace(/[_-]+/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

const featureLabel = type => ({
    heart_rate: 'Frequência cardíaca',
    blood_pressure: 'Tensão arterial',
    blood_oxygen: 'Oxigénio no sangue',
    blood_sugar: 'Glicemia',
    temperature: 'Temperatura',
    battery: 'Bateria',
    activity: 'Atividade',
    location: 'Localização',
    alarm: 'Alarme',
    heartbeat: 'Sinal de vida',
    sleep: 'Sono',
    ecg: 'ECG',
    hrv: 'VFC',
    weather: 'Meteorologia',
    device_config: 'Configuração',
}[type] || titleize(type));

const fieldLabel = key => ({
    distanceMeters: 'Distância',
    caloriesKcal: 'Calorias',
    source: 'Origem',
    gpsValid: 'GPS válido',
    speedKmh: 'Velocidade',
    accuracyMeters: 'Precisão',
    code: 'Código',
    lowBattery: 'Bateria fraca',
    fall: 'Queda',
    wearingNotice: 'Aviso de utilização',
}[key] || titleize(key));

const when = value => {
    if (!value) return '';
    const parsed = Date.parse(value);
    if (Number.isNaN(parsed)) return String(value);
    return new Date(parsed).toLocaleString('pt-PT');
};

const rowPayload = row => row?.payload && typeof row.payload === 'object' ? row.payload : row;

const commandLabel = command => ({
    'Heart rate': 'Frequência cardíaca',
    'Blood pressure': 'Tensão arterial',
    'Blood oxygen': 'Oxigénio no sangue',
    'Temperature': 'Temperatura',
    'Temperature variant': 'Temperatura',
    'Breath rate': 'Frequência respiratória',
    'Location': 'Localização',
    'Sleep data': 'Sono',
    'ECG': 'ECG',
    'HRV': 'VFC',
    'PPG': 'PPG',
    'RR interval': 'Intervalo RR',
    'Weather': 'Meteorologia',
}[command.label] || command.label || command.command);

function modelImageHtml(modelInfo) {
    return modelInfo?.image
        ? `<img src="${esc(modelInfo.image)}" class="object-fit-contain" alt="${esc(modelInfo.model)}" style="width:40px;height:40px;">`
        : `<i class="fa-solid fa-microchip fa-xl text-secondary" style="width:40px"></i>`;
}

function modelPreviewHtml(modelInfo, label = 'Modelo') {
    return modelInfo?.image
        ? `<img src="${esc(modelInfo.image)}" class="object-fit-contain" alt="${esc(modelInfo.model || label)}">`
        : `<div class="text-center text-secondary"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${esc(label)}</div></div>`;
}

function supplierProtocol(supplier, models = summary.models) {
    const existing = models.find(m => m.supplier === supplier && m.protocol);
    return existing?.protocol || supplierProtocolDefaults[supplier] || '';
}

function suppliersFromModels(models = summary.models) {
    return [...new Set(models.map(m => m.supplier).filter(Boolean))];
}

function modelsForSupplier(supplier, models = summary.models) {
    return models.filter(m => m.supplier === supplier);
}

function findModelInfo(supplier, model, models = summary.models) {
    return models.find(m => m.supplier === supplier && m.model === model) || null;
}

function renderButtonGroup(container, items, selected, action, valueKey = 'value', labelKey = 'label') {
    container.innerHTML = items.length ? items.map(item => {
        const value = String(item[valueKey] ?? '');
        const label = String(item[labelKey] ?? value);
        return `<button type="button" class="btn btn-sm ${value === selected ? 'btn-primary' : 'btn-outline-primary'}" data-action="${esc(action)}" data-value="${esc(value)}">${esc(label)}</button>`;
    }).join('') : '<div class="text-secondary border rounded bg-body-tertiary px-3 py-2 small">Sem opções disponíveis</div>';
}

async function loadSummary() {
    summary = await request('/api/dashboard/summary');
    renderSummary();
    if (selectedImei) await loadDevice(selectedImei);
}

function renderSummary() {
    els.hubCounts.textContent = `${summary.counts?.online ?? 0} ligados / ${summary.counts?.offline ?? 0} desligados`;

    const modelLookup = {};
    for (const m of summary.models) {
        modelLookup[`${m.supplier}:${m.model}`] = m;
    }

    const groups = {};
    for (const d of summary.devices) {
        const key = `${d.supplier} / ${d.model}`;
        if (!groups[key]) groups[key] = {supplier: d.supplier, model: d.model, devices: []};
        groups[key].devices.push(d);
    }

    els.deviceList.innerHTML = Object.entries(groups).map(([key, group]) => {
        const modelInfo = modelLookup[`${group.supplier}:${group.model}`];
        return `
            <div class="list-group list-group-flush">
            <div class="small fw-semibold text-secondary px-3 py-1 bg-body-tertiary border-bottom">${esc(group.supplier)} ${esc(group.model)}</div>
            ${group.devices.map(d => `
                <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom${selectedImei === d.imei ? ' bg-primary-subtle' : ''}" data-imei="${esc(d.imei)}" data-action="select">
                <div style="width:40px;text-align:center;flex-shrink:0">${modelImageHtml(modelInfo)}</div>
                <div class="flex-grow-1 min-width-0">
                <div class="d-flex justify-content-between align-items-center">
                <strong class="small text-break">${esc(d.imei)}</strong>
                </div>
                <div class="small text-secondary d-flex align-items-center gap-1"><span class="rounded-circle ${d.online ? 'bg-success' : 'bg-danger'} d-inline-block" style="width:.55rem;height:.55rem;"></span><span>visto ${ago(d.lastSeenAt)}</span></div>
                </div>
                <div class="btn-group btn-group-sm" style="flex-shrink:0">
                <button class="btn btn-outline-secondary" data-imei="${esc(d.imei)}" data-supplier="${esc(d.supplier)}" data-model="${esc(d.model)}" data-action="edit" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-outline-danger" data-imei="${esc(d.imei)}" data-action="delete" title="Apagar"><i class="fa-solid fa-trash"></i></button>
                </div>
                </div>`).join('')}
            </div>`;
    }).join('');

    renderSelection();
}

async function selectDevice(imei) {
    selectedImei = imei;
    await loadDevice(imei);
}

async function loadDevice(imei) {
    selectedDetail = await request(`/api/devices/${encodeURIComponent(imei)}`);
    renderSelection();
}

function renderSelection() {
    els.deviceColumn.className = selectedImei ? 'col-12 col-lg-3' : 'col-12 col-lg-4';
    els.detailColumn.className = selectedImei ? 'col-12 col-lg-9' : 'col-12 col-lg-8';
    els.emptyState.classList.toggle('d-none', !!selectedDetail);
    els.deviceDetail.classList.toggle('d-none', !selectedDetail);
    if (!selectedDetail) return;
    const d = selectedDetail.device;
    els.detailTitle.textContent = d.imei;
    els.detailMeta.textContent = `${d.supplier ?? ''} ${d.model ?? ''} · ${d.protocol ?? 'desconhecido'} · visto ${ago(d.lastSeenAt)}`;
    els.detailBadge.className = `badge ${d.online ? 'text-bg-success' : 'text-bg-secondary'}`;
    els.detailBadge.textContent = d.online ? 'ligado' : 'desligado';
    renderTelemetryList(selectedDetail.recent.telemetry || []);
    renderRequestCards(selectedDetail.commands || []);
    renderDownlinkRequests(selectedDetail.recent.commands || []);
    renderConnectionLogs(selectedDetail.recent.events || []);
}

function renderTelemetryList(telemetryRows) {
    const telemetry = telemetryRows
        .map(rowPayload)
        .filter(payload => payload && !payload.debug)
        .sort((a, b) => eventTime(b) - eventTime(a));
    els.telemetryCount.textContent = telemetry.length ? `${telemetry.length} eventos` : '';
    els.telemetryList.innerHTML = telemetry.length ? `
        <div class="list-group">
        ${telemetry.map(renderTelemetryRow).join('')}
        </div>` : '<div class="text-secondary border rounded bg-body-tertiary p-3">Ainda não há eventos recebidos.</div>';
}

function renderTelemetryRow(payload) {
    const type = payload?.type || 'telemetry';
    const data = payload?.data && typeof payload.data === 'object' ? payload.data : {};
    const card = uplinkCardContent(type, data, payload || {});
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
        const extra = Object.entries(payload.extra)
            .filter(([, value]) => value !== undefined && value !== null && value !== '')
            .slice(0, 6)
            .map(([key, value]) => `${fieldLabel(key)}: ${esc(displayValue(value))}`);
        details.push(...extra);
    }
    return details.join(' · ');
}

function displayValue(value) {
    if (Array.isArray(value)) return String(value.length);
    if (value && typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

function eventTime(payload) {
    const time = Date.parse(payload?.occurredAt || payload?.recordedAt || '');
    return Number.isNaN(time) ? 0 : time;
}

function renderRequestCards(commands) {
    els.requestCardCount.textContent = commands.length ? `${commands.length} ações` : '';
    els.requestGrid.innerHTML = commands.length ? commands.map(renderRequestCard).join('') : '<div class="col-12"><div class="text-secondary border rounded bg-body-tertiary p-3">Não há pedidos disponíveis para este dispositivo.</div></div>';
}

function renderRequestCard(command) {
    const type = commandFeature(command);
    const card = requestCardContent(type);
    const tone = cardTone(type, command);
    const loading = loadingCommands.has(command.command);

    return `
        <div class="col-12 col-md-6 col-xl-4">
        <div class="card position-relative overflow-hidden h-100 border-${tone.border} ${tone.bg} bg-opacity-10">
        <div class="position-absolute top-0 end-0 bg-white bg-opacity-75 rounded-bottom-start px-3 py-2">
        <i class="fa-solid ${esc(command.icon || card.icon)} fs-4 ${tone.text}"></i>
        </div>
        <div class="card-body position-relative">
        <div class="small text-secondary">${esc(command.command)}</div>
        <h2 class="h6 mb-3">${esc(commandLabel(command))}</h2>
        <div class="${tone.text} fw-semibold fs-5">${esc(card.value)}</div>
        ${card.details ? `<div class="small text-secondary mt-1">${card.details}</div>` : ''}
        <div class="small text-secondary mt-3 mb-3">Pedir dados ao dispositivo</div>
        <button class="btn btn-primary btn-sm" data-command="${esc(command.command)}" data-action="sendCommand" ${loading ? 'disabled' : ''}>${loading ? '<span class="spinner-border spinner-border-sm me-3"></span>A pedir' : '<i class="fa-solid fa-paper-plane me-3"></i>Pedir'}</button>
        </div>
        </div>
        </div>`;
}

function requestCardContent(type) {
    if (type === 'heart_rate') return {icon: 'fa-heart-pulse', value: 'Frequência cardíaca'};
    if (type === 'blood_pressure') return {icon: 'fa-stethoscope', value: 'Tensão arterial'};
    if (type === 'blood_oxygen') return {icon: 'fa-droplet', value: 'Oxigénio no sangue'};
    if (type === 'temperature') return {icon: 'fa-temperature-half', value: 'Temperatura'};
    if (type === 'location') return {icon: 'fa-location-dot', value: 'Localização'};
    if (type === 'sleep') return {icon: 'fa-bed', value: 'Sono'};
    if (type === 'ecg') return {icon: 'fa-wave-square', value: 'ECG'};
    if (type === 'hrv') return {icon: 'fa-chart-line', value: 'VFC'};
    if (type === 'weather') return {icon: 'fa-cloud-sun', value: 'Meteorologia'};
    return {icon: 'fa-circle-info', value: featureLabel(type)};
}

function commandFeature(command) {
    const haystack = `${command.command || ''} ${command.label || ''}`.toLowerCase();
    if (haystack.includes('heart')) return 'heart_rate';
    if (haystack.includes('pressure') || haystack.includes('bp')) return 'blood_pressure';
    if (haystack.includes('oxygen') || haystack.includes('bo')) return 'blood_oxygen';
    if (haystack.includes('temp')) return 'temperature';
    if (haystack.includes('location')) return 'location';
    if (haystack.includes('sleep')) return 'sleep';
    if (haystack.includes('ecg')) return 'ecg';
    if (haystack.includes('hrv')) return 'hrv';
    if (haystack.includes('weather')) return 'weather';
    return 'device_config';
}

function cardTone(type, command) {
    const key = type || commandFeature(command);
    if (['heart_rate', 'blood_pressure', 'ecg', 'hrv'].includes(key)) return {border: 'danger', bg: 'bg-danger', text: 'text-danger'};
    if (key === 'blood_oxygen') return {border: 'info', bg: 'bg-info', text: 'text-info'};
    if (key === 'blood_sugar') return {border: 'warning', bg: 'bg-warning', text: 'text-warning'};
    if (key === 'temperature') return {border: 'warning', bg: 'bg-warning', text: 'text-warning'};
    if (key === 'battery') return {border: 'success', bg: 'bg-success', text: 'text-success'};
    if (key === 'activity') return {border: 'primary', bg: 'bg-primary', text: 'text-primary'};
    if (key === 'location') return {border: 'success', bg: 'bg-success', text: 'text-success'};
    if (key === 'heartbeat') return {border: 'info', bg: 'bg-info', text: 'text-info'};
    if (key === 'sleep') return {border: 'primary', bg: 'bg-primary', text: 'text-primary'};
    if (key === 'weather') return {border: 'secondary', bg: 'bg-secondary', text: 'text-secondary'};
    return {border: 'secondary', bg: 'bg-secondary', text: 'text-secondary'};
}

function uplinkCardContent(type, data, payload) {
    if (type === 'heart_rate') return {icon: 'fa-heart-pulse', value: `${data.bpm ?? '-'} bpm`};
    if (type === 'blood_pressure') return {icon: 'fa-stethoscope', value: `${data.systolicMmHg ?? '-'} / ${data.diastolicMmHg ?? '-'} mmHg`, details: data.pulseBpm ? `Pulso ${esc(data.pulseBpm)} bpm` : ''};
    if (type === 'blood_oxygen') return {icon: 'fa-droplet', value: `${data.spo2Percent ?? '-'}% SpO2`};
    if (type === 'blood_sugar') return {icon: 'fa-vial', value: `${data.value ?? '-'} mg/dL`};
    if (type === 'temperature') return {icon: 'fa-temperature-half', value: `${data.bodyCelsius ?? '-'} °C`};
    if (type === 'battery') return {icon: 'fa-battery-three-quarters', value: `${data.percent ?? '-'}%`, details: data.charging === true ? 'A carregar' : (data.charging === false ? 'Não está a carregar' : '')};
    if (type === 'activity') return {icon: 'fa-person-walking', value: `${data.steps ?? 0} passos`, details: compactDetails(data, ['distanceMeters', 'caloriesKcal'])};
    if (type === 'location') return {icon: 'fa-location-dot', value: data.lat && data.lon ? `${data.lat}, ${data.lon}` : 'Atualização de localização', details: compactDetails(data, ['source', 'gpsValid', 'speedKmh', 'accuracyMeters'])};
    if (type === 'alarm') return {icon: 'fa-triangle-exclamation', value: alarmValue(data), details: compactDetails(data, ['code', 'lowBattery', 'fall', 'wearingNotice'])};
    if (type === 'heartbeat') return {icon: 'fa-signal', value: 'Sinal de vida'};
    if (type === 'sleep') return {icon: 'fa-bed', value: 'Dados de sono'};
    if (type === 'ecg') return {icon: 'fa-wave-square', value: 'Dados de ECG'};
    if (type === 'hrv') return {icon: 'fa-chart-line', value: 'Dados de VFC'};
    if (type === 'weather') return {icon: 'fa-cloud-sun', value: 'Dados meteorológicos'};
    return {icon: 'fa-circle-info', value: featureLabel(type), details: compactDetails(data, Object.keys(data).slice(0, 4))};
}

function alarmValue(data) {
    if (data.sos) return 'SOS';
    if (data.fall) return 'Queda detetada';
    if (data.lowBattery) return 'Bateria fraca';
    return 'Alarme';
}

function compactDetails(data, keys) {
    return keys
        .filter(key => data[key] !== undefined && data[key] !== null && data[key] !== '')
        .map(key => `${esc(fieldLabel(key))}: ${esc(data[key])}`)
        .join(' · ');
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
        </div>` : '<div class="text-secondary border rounded bg-body-tertiary p-3">Ainda não há pedidos ao dispositivo.</div>';
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
        </div>` : '<div class="text-secondary border rounded bg-body-tertiary p-3">Ainda não há registos de ligação.</div>';
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

function statusBadge(status) {
    const cls = {
        queued: 'text-bg-secondary',
        sent: 'text-bg-primary',
        waiting: 'text-bg-warning',
        acked: 'text-bg-success',
        failed: 'text-bg-danger',
        dropped: 'text-bg-danger',
    }[status] || 'text-bg-light';
    const label = {
        queued: 'em fila',
        sent: 'enviado',
        waiting: 'à espera',
        acked: 'confirmado',
        failed: 'falhou',
        dropped: 'descartado',
        unknown: 'desconhecido',
    }[status] || titleize(status).toLowerCase();
    return `<span class="badge ${cls}">${esc(label)}</span>`;
}

function expectedReplies(command) {
    return Array.isArray(command.expectedReplyTypes) && command.expectedReplyTypes.length
        ? `À espera de ${command.expectedReplyTypes.join(', ')}`
        : '';
}

async function sendCommand(command) {
    loadingCommands.add(command);
    renderSelection();
    try {
        const result = await request(`/api/devices/${encodeURIComponent(selectedImei)}/commands`, {method: 'POST', body: JSON.stringify({command})});
        if (result.error) alert(result.error.message || result.error.code);
        await loadSummary();
    } finally {
        loadingCommands.delete(command);
        renderSelection();
    }
}

function openAddDevice() {
    els.deviceModalLabel.textContent = 'Adicionar dispositivo';
    els.deviceForm.reset();
    delete els.deviceImei.dataset.originalImei;
    renderDeviceSelectors();
    deviceModal.show();
}

function editDevice(imei, supplier, model) {
    els.deviceModalLabel.textContent = 'Editar dispositivo';
    els.deviceImei.value = imei;
    els.deviceImei.dataset.originalImei = imei;
    renderDeviceSelectors(supplier, model);
    deviceModal.show();
}

function renderDeviceSelectors(selectedSupplier = '', selectedModel = '') {
    const suppliers = suppliersFromModels();
    const supplier = suppliers.includes(selectedSupplier) ? selectedSupplier : (suppliers[0] || '');
    const models = modelsForSupplier(supplier);
    const availableModelNames = models.map(m => m.model);
    const model = availableModelNames.includes(selectedModel) ? selectedModel : (availableModelNames[0] || '');

    els.deviceForm.dataset.supplier = supplier;
    els.deviceForm.dataset.model = model;

    renderButtonGroup(
        els.deviceSupplierButtons,
        suppliers.map(value => ({value, label: value})),
        supplier,
        'selectDeviceSupplier'
    );
    renderButtonGroup(
        els.deviceModelButtons,
        models.map(m => ({value: m.model, label: m.model})),
        model,
        'selectDeviceModel'
    );
    updateDevicePreview();
}

function updateDevicePreview() {
    const supplier = els.deviceForm.dataset.supplier || '';
    const model = els.deviceForm.dataset.model || '';
    const modelInfo = findModelInfo(supplier, model);
    els.devicePreview.innerHTML = modelPreviewHtml(modelInfo, model || 'Selecione um modelo');
    els.deviceProtocolText.textContent = modelInfo?.protocol || supplierProtocol(supplier) || '-';
}

async function saveDevice() {
    const imei = els.deviceImei.value.trim();
    const supplier = els.deviceForm.dataset.supplier || '';
    const model = els.deviceForm.dataset.model || '';
    if (!imei || !supplier || !model) { alert('Todos os campos são obrigatórios'); return; }

    const originalImei = els.deviceImei.dataset.originalImei;
    const isEdit = !!originalImei;
    const url = isEdit ? `/api/devices/${encodeURIComponent(originalImei)}` : '/api/devices';
    const method = isEdit ? 'PUT' : 'POST';

    const result = await request(url, {method, body: JSON.stringify({imei, supplier, model})});
    if (result.error) { alert(result.error.message || result.error.code); return; }

    deviceModal.hide();
    await loadSummary();
}

async function deleteDevice(imei) {
    if (!confirm(`Apagar o dispositivo ${imei}?`)) return;
    await request(`/api/devices/${encodeURIComponent(imei)}`, {method: 'DELETE'});
    if (selectedImei === imei) { selectedImei = null; selectedDetail = null; }
    await loadSummary();
}

// --- Supplier management ---

async function loadSuppliers() {
    const data = await request('/api/suppliers');
    els.supplierForm.reset();
    els.supplierListBody.innerHTML = data.suppliers.map(s => `
        <tr>
        <td>${esc(s.name)}</td>
        <td>${s.model_count}</td>
        <td><span class="badge ${s.enabled ? 'text-bg-success' : 'text-bg-secondary'}">${s.enabled ? 'ativo' : 'inativo'}</span></td>
        <td>
        <button class="btn btn-outline-${s.enabled ? 'warning' : 'success'} btn-sm" data-id="${s.id}" data-enabled="${s.enabled ? '1' : ''}" data-action="toggleSupplier" title="${s.enabled ? 'Desativar' : 'Ativar'}"><i class="fa-solid fa-${s.enabled ? 'pause' : 'play'}"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${s.id}" data-action="deleteSupplier" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`).join('');
    supplierModal.show();
}

async function saveSupplier() {
    const name = els.supplierName.value.trim();
    if (!name) { alert('O nome é obrigatório'); return; }
    const result = await request('/api/suppliers', {method: 'POST', body: JSON.stringify({name})});
    if (result.error) { alert(result.error.message || result.error.code); return; }
    els.supplierName.value = '';
    await loadSuppliers();
}

async function toggleSupplier(id, enabled) {
    const result = await request(`/api/suppliers/${id}`, {method: 'PUT', body: JSON.stringify({enabled: !enabled})});
    if (result.error) { alert(result.error.message || result.error.code); return; }
    await loadSuppliers();
}

async function deleteSupplier(id) {
    if (!confirm('Apagar fornecedor?')) return;
    const result = await request(`/api/suppliers/${id}`, {method: 'DELETE'});
    if (result.error) { alert(result.error.message || result.error.code); return; }
    await loadSuppliers();
}

// --- Model management ---

async function loadModels() {
    const [modelsData, suppliersData] = await Promise.all([
        request('/api/models'),
        request('/api/suppliers'),
    ]);
    summary.models = modelsData.models;
    modelModalSuppliers = suppliersData.suppliers;
    resetModelForm();
    els.modelListBody.innerHTML = modelsData.models.map(m => `
        <tr>
        <td>${modelImageHtml(m)}</td>
        <td>${esc(m.supplier)}</td>
        <td>${esc(m.model)}</td>
        <td>${esc(m.protocol)}</td>
        <td>
        <button class="btn btn-outline-secondary btn-sm" data-id="${m.id}" data-supplier-id="${m.supplier_id}" data-supplier="${esc(m.supplier)}" data-model="${esc(m.model)}" data-protocol="${esc(m.protocol)}" data-image="${esc(m.image || '')}" data-action="editModel" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${m.id}" data-action="deleteModel" title="Apagar"><i class="fa-solid fa-trash"></i></button>
        </td>
        </tr>`).join('');
    modelModal.show();
}

function resetModelForm(selectedSupplierId = '') {
    if (modelPreviewObjectUrl) {
        URL.revokeObjectURL(modelPreviewObjectUrl);
        modelPreviewObjectUrl = null;
    }
    els.modelForm.reset();
    delete els.modelForm.dataset.modelId;
    delete els.modelForm.dataset.image;
    els.modelModalLabel.textContent = 'Modelos';
    els.saveModelBtn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar';

    const suppliers = modelModalSuppliers.map(s => ({value: String(s.id), label: s.name}));
    const supplierId = suppliers.some(s => s.value === String(selectedSupplierId))
        ? String(selectedSupplierId)
        : (suppliers[0]?.value || '');
    const supplier = modelModalSuppliers.find(s => String(s.id) === supplierId);
    els.modelForm.dataset.supplierId = supplierId;
    els.modelForm.dataset.supplier = supplier?.name || '';

    renderButtonGroup(els.modelSupplierButtons, suppliers, supplierId, 'selectModelSupplier');
    updateModelProtocolAndPreview();
}

function editModel(id, supplierId, supplier, model, protocol, image) {
    if (modelPreviewObjectUrl) {
        URL.revokeObjectURL(modelPreviewObjectUrl);
        modelPreviewObjectUrl = null;
    }
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
        modelModalSuppliers.map(s => ({value: String(s.id), label: s.name})),
        String(supplierId),
        'selectModelSupplier'
    );
    updateModelProtocolAndPreview(protocol);
}

function selectModelSupplier(supplierId) {
    if (modelPreviewObjectUrl) {
        URL.revokeObjectURL(modelPreviewObjectUrl);
        modelPreviewObjectUrl = null;
        els.modelImage.value = '';
    }
    const supplier = modelModalSuppliers.find(s => String(s.id) === String(supplierId));
    els.modelForm.dataset.supplierId = String(supplierId);
    els.modelForm.dataset.supplier = supplier?.name || '';
    delete els.modelForm.dataset.image;
    renderButtonGroup(
        els.modelSupplierButtons,
        modelModalSuppliers.map(s => ({value: String(s.id), label: s.name})),
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
    if (modelPreviewObjectUrl) {
        return;
    }
    els.modelPreview.innerHTML = modelPreviewHtml(modelInfo, model || supplier || 'Novo modelo');
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

    const modelId = els.modelForm.dataset.modelId;
    const result = await formRequest(modelId ? `/api/models/${encodeURIComponent(modelId)}` : '/api/models', body, {
        method: modelId ? 'PUT' : 'POST',
    });
    if (result.error) { alert(result.error.message || result.error.code); return; }

    await loadModels();
}

async function deleteModel(id) {
    if (!confirm('Apagar modelo?')) return;
    await request(`/api/models/${id}`, {method: 'DELETE'});
    await loadModels();
}

document.addEventListener('DOMContentLoaded', () => {
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

    deviceModal = new bootstrap.Modal(document.getElementById('deviceModal'));
    supplierModal = new bootstrap.Modal(document.getElementById('supplierModal'));
    modelModal = new bootstrap.Modal(document.getElementById('modelModal'));

    els.addDeviceBtn.addEventListener('click', openAddDevice);
    els.saveDeviceBtn.addEventListener('click', saveDevice);
    els.deviceForm.addEventListener('submit', e => { e.preventDefault(); saveDevice(); });
    els.manageSuppliersBtn.addEventListener('click', loadSuppliers);
    els.saveSupplierBtn.addEventListener('click', saveSupplier);
    els.supplierForm.addEventListener('submit', e => { e.preventDefault(); saveSupplier(); });
    els.manageModelsBtn.addEventListener('click', loadModels);
    els.saveModelBtn.addEventListener('click', saveModel);
    els.resetModelBtn.addEventListener('click', () => resetModelForm());
    els.modelForm.addEventListener('submit', e => { e.preventDefault(); saveModel(); });
    els.modelModel.addEventListener('input', () => updateModelProtocolAndPreview());
    els.modelImage.addEventListener('change', () => {
        if (modelPreviewObjectUrl) {
            URL.revokeObjectURL(modelPreviewObjectUrl);
            modelPreviewObjectUrl = null;
        }
        const file = els.modelImage.files[0];
        if (file) {
            modelPreviewObjectUrl = URL.createObjectURL(file);
            els.modelPreview.innerHTML = `<img src="${esc(modelPreviewObjectUrl)}" class="object-fit-contain" alt="${esc(els.modelModel.value.trim() || 'Modelo')}">`;
        } else {
            updateModelProtocolAndPreview();
        }
    });

    els.deviceSupplierButtons.addEventListener('click', e => {
        const btn = e.target.closest('[data-action="selectDeviceSupplier"]');
        if (!btn) return;
        renderDeviceSelectors(btn.dataset.value, '');
    });

    els.deviceModelButtons.addEventListener('click', e => {
        const btn = e.target.closest('[data-action="selectDeviceModel"]');
        if (!btn) return;
        els.deviceForm.dataset.model = btn.dataset.value;
        renderDeviceSelectors(els.deviceForm.dataset.supplier, btn.dataset.value);
    });

    els.modelSupplierButtons.addEventListener('click', e => {
        const btn = e.target.closest('[data-action="selectModelSupplier"]');
        if (!btn) return;
        selectModelSupplier(btn.dataset.value);
    });

    els.deviceList.addEventListener('click', e => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const { action, imei, supplier, model } = btn.dataset;
        if (action === 'select') selectDevice(imei);
        if (action === 'edit') { e.stopPropagation(); editDevice(imei, supplier, model); }
        if (action === 'delete') { e.stopPropagation(); deleteDevice(imei); }
    });

    els.requestGrid.addEventListener('click', e => {
        const btn = e.target.closest('[data-action="sendCommand"]');
        if (!btn) return;
        sendCommand(btn.dataset.command);
    });

    els.supplierListBody.addEventListener('click', e => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const { id, action, enabled } = btn.dataset;
        if (action === 'toggleSupplier') toggleSupplier(parseInt(id), !!enabled);
        if (action === 'deleteSupplier') deleteSupplier(parseInt(id));
    });

    els.modelListBody.addEventListener('click', e => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        if (btn.dataset.action === 'editModel') {
            editModel(parseInt(btn.dataset.id), parseInt(btn.dataset.supplierId), btn.dataset.supplier, btn.dataset.model, btn.dataset.protocol, btn.dataset.image);
        }
        if (btn.dataset.action === 'deleteModel') {
            deleteModel(parseInt(btn.dataset.id));
        }
    });

    loadSummary();
    setInterval(loadSummary, 5000);
});
