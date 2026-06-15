let summary = {devices: [], models: [], counts: {}};
let selectedImei = null;
let selectedDetail = null;

let els = {};
let deviceModal = null;
let supplierModal = null;
let modelModal = null;
const loadingCommands = new Set();

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
                <span class="badge ${d.online ? 'text-bg-success' : 'text-bg-secondary'}">${d.online ? 'ligado' : 'desligado'}</span>
              </div>
              <div class="small text-secondary">visto ${ago(d.lastSeenAt)}</div>
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
  renderRequestCards(selectedDetail.commands || [], selectedDetail.recent.telemetry || []);
  renderDownlinkRequests(selectedDetail.recent.commands || []);
}

function renderRequestCards(commands, telemetryRows) {
  const telemetry = telemetryRows.map(rowPayload).filter(payload => payload && !payload.debug);
  els.requestCardCount.textContent = commands.length ? `${commands.length} ações` : '';
  els.requestGrid.innerHTML = commands.length ? commands.map(command => renderRequestCard(command, telemetry)).join('') : '<div class="col-12"><div class="text-secondary border rounded bg-body-tertiary p-3">Não há pedidos disponíveis para este dispositivo.</div></div>';
}

function renderRequestCard(command, telemetry) {
  const result = latestResultForCommand(command, telemetry);
  const type = result?.type || commandFeature(command);
  const data = result?.data && typeof result.data === 'object' ? result.data : {};
  const card = uplinkCardContent(type, data, result || {});
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
          <div class="${tone.text} fw-semibold fs-5">${esc(result ? card.value : 'Sem dados')}</div>
          ${result && card.details ? `<div class="small text-secondary mt-1">${card.details}</div>` : ''}
          <div class="small text-secondary mt-3 mb-3">${result ? `${esc(when(result.occurredAt || result.recordedAt) || 'hora desconhecida')}${result.source?.nativeType ? ` · ${esc(result.source.nativeType)}` : ''}` : 'Pedir dados ao dispositivo'}</div>
          <button class="btn btn-primary btn-sm" data-command="${esc(command.command)}" data-action="sendCommand" ${loading ? 'disabled' : ''}>${loading ? '<span class="spinner-border spinner-border-sm me-3"></span>A pedir' : '<i class="fa-solid fa-paper-plane me-3"></i>Pedir'}</button>
        </div>
      </div>
    </div>`;
}

function latestResultForCommand(command, telemetry) {
  const expected = Array.isArray(command.expectedReplyTypes) ? command.expectedReplyTypes : [];
  const feature = commandFeature(command);
  return telemetry.find(payload => {
    if (!payload || !payload.data || payload.debug) return false;
    if (payload.type === feature) return true;
    return expected.includes(payload.source?.nativeType);
  }) || null;
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
  if (key === 'temperature') return {border: 'warning', bg: 'bg-warning', text: 'text-warning'};
  if (key === 'location') return {border: 'success', bg: 'bg-success', text: 'text-success'};
  if (key === 'sleep') return {border: 'primary', bg: 'bg-primary', text: 'text-primary'};
  if (key === 'weather') return {border: 'secondary', bg: 'bg-secondary', text: 'text-secondary'};
  return {border: 'secondary', bg: 'bg-secondary', text: 'text-secondary'};
}

function uplinkCardContent(type, data, payload) {
  if (type === 'heart_rate') return {icon: 'fa-heart-pulse', value: `${data.bpm ?? '-'} bpm`};
  if (type === 'blood_pressure') return {icon: 'fa-stethoscope', value: `${data.systolicMmHg ?? '-'} / ${data.diastolicMmHg ?? '-'} mmHg`, details: data.pulseBpm ? `Pulso ${esc(data.pulseBpm)} bpm` : ''};
  if (type === 'blood_oxygen') return {icon: 'fa-droplet', value: `${data.spo2Percent ?? '-'}% SpO2`};
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

function populateModelOptions() {
  const suppliers = [...new Set(summary.models.map(m => m.supplier))];
  const models = [...new Set(summary.models.map(m => m.model))];
  els.supplierList.innerHTML = suppliers.map(s => `<option value="${esc(s)}">`).join('');
  els.modelList.innerHTML = models.map(m => `<option value="${esc(m)}">`).join('');
}

function openAddDevice() {
  els.deviceModalLabel.textContent = 'Adicionar dispositivo';
  els.deviceForm.reset();
  delete els.deviceImei.dataset.originalImei;
  populateModelOptions();
  deviceModal.show();
}

function editDevice(imei, supplier, model) {
  els.deviceModalLabel.textContent = 'Editar dispositivo';
  els.deviceImei.value = imei;
  els.deviceImei.dataset.originalImei = imei;
  els.deviceSupplier.value = supplier;
  els.deviceModel.value = model;
  populateModelOptions();
  deviceModal.show();
}

async function saveDevice() {
  const imei = els.deviceImei.value.trim();
  const supplier = els.deviceSupplier.value.trim();
  const model = els.deviceModel.value.trim();
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
  els.modelForm.reset();
  delete els.modelForm.dataset.modelId;
  els.modelModalLabel.textContent = 'Modelos';
  els.saveModelBtn.innerHTML = '<i class="fa-solid fa-plus"></i>';
  els.modelSupplier.innerHTML = '<option value="">Fornecedor...</option>' + suppliersData.suppliers.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  els.modelListBody.innerHTML = modelsData.models.map(m => `
    <tr>
      <td>${modelImageHtml(m)}</td>
      <td>${esc(m.supplier)}</td>
      <td>${esc(m.model)}</td>
      <td>${esc(m.protocol)}</td>
      <td>
        <button class="btn btn-outline-secondary btn-sm" data-id="${m.id}" data-supplier-id="${m.supplier_id}" data-model="${esc(m.model)}" data-protocol="${esc(m.protocol)}" data-action="editModel" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${m.id}" data-action="deleteModel" title="Apagar"><i class="fa-solid fa-trash"></i></button>
      </td>
    </tr>`).join('');
  modelModal.show();
}

function editModel(id, supplierId, model, protocol) {
  els.modelForm.dataset.modelId = String(id);
  els.modelSupplier.value = String(supplierId);
  els.modelModel.value = model;
  els.modelProtocol.value = protocol;
  els.modelImage.value = '';
  els.modelModalLabel.textContent = 'Editar modelo';
  els.saveModelBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i>';
}

async function saveModel() {
  const supplierId = parseInt(els.modelSupplier.value);
  const model = els.modelModel.value.trim();
  const protocol = els.modelProtocol.value.trim();
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
    requestCardCount: document.getElementById('requestCardCount'),
    requestGrid: document.getElementById('requestGrid'),
    downlinkRequests: document.getElementById('downlinkRequests'),
    addDeviceBtn: document.getElementById('addDeviceBtn'),
    deviceModalLabel: document.getElementById('deviceModalLabel'),
    deviceForm: document.getElementById('deviceForm'),
    deviceImei: document.getElementById('deviceImei'),
    deviceSupplier: document.getElementById('deviceSupplier'),
    deviceModel: document.getElementById('deviceModel'),
    supplierList: document.getElementById('supplierList'),
    modelList: document.getElementById('modelList'),
    saveDeviceBtn: document.getElementById('saveDeviceBtn'),
    manageSuppliersBtn: document.getElementById('manageSuppliersBtn'),
    manageModelsBtn: document.getElementById('manageModelsBtn'),
    supplierForm: document.getElementById('supplierForm'),
    supplierName: document.getElementById('supplierName'),
    supplierListBody: document.getElementById('supplierListBody'),
    saveSupplierBtn: document.getElementById('saveSupplierBtn'),
    modelModalLabel: document.getElementById('modelModalLabel'),
    modelForm: document.getElementById('modelForm'),
    modelSupplier: document.getElementById('modelSupplier'),
    modelModel: document.getElementById('modelModel'),
    modelProtocol: document.getElementById('modelProtocol'),
    modelImage: document.getElementById('modelImage'),
    modelListBody: document.getElementById('modelListBody'),
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
  els.modelForm.addEventListener('submit', e => { e.preventDefault(); saveModel(); });

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
      editModel(parseInt(btn.dataset.id), parseInt(btn.dataset.supplierId), btn.dataset.model, btn.dataset.protocol);
    }
    if (btn.dataset.action === 'deleteModel') {
      deleteModel(parseInt(btn.dataset.id));
    }
  });

  loadSummary();
  setInterval(loadSummary, 5000);
});
