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
  if (!value) return 'never';
  const seconds = Math.max(0, Math.floor((Date.now() - Date.parse(value)) / 1000));
  if (seconds < 60) return `${seconds}s ago`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
};

const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));

const titleize = value => String(value ?? 'unknown').replace(/[_-]+/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

const when = value => {
  if (!value) return '';
  const parsed = Date.parse(value);
  if (Number.isNaN(parsed)) return String(value);
  return new Date(parsed).toLocaleString();
};

const rowPayload = row => row?.payload && typeof row.payload === 'object' ? row.payload : row;

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
  els.hubCounts.textContent = `${summary.counts?.online ?? 0} online / ${summary.counts?.offline ?? 0} offline`;

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
                <span class="badge ${d.online ? 'text-bg-success' : 'text-bg-secondary'}">${d.online ? 'online' : 'offline'}</span>
              </div>
              <div class="small text-secondary">${ago(d.lastSeenAt)}</div>
            </div>
            <div class="btn-group btn-group-sm" style="flex-shrink:0">
              <button class="btn btn-outline-secondary" data-imei="${esc(d.imei)}" data-supplier="${esc(d.supplier)}" data-model="${esc(d.model)}" data-action="edit" title="Edit"><i class="fa-solid fa-pen"></i></button>
              <button class="btn btn-outline-danger" data-imei="${esc(d.imei)}" data-action="delete" title="Delete"><i class="fa-solid fa-trash"></i></button>
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
  els.detailMeta.textContent = `${d.supplier ?? ''} ${d.model ?? ''} · ${d.protocol ?? 'unknown'} · last seen ${ago(d.lastSeenAt)}`;
  els.detailBadge.className = `badge ${d.online ? 'text-bg-success' : 'text-bg-secondary'}`;
  els.detailBadge.textContent = d.online ? 'online' : 'offline';
  els.commandGrid.innerHTML = selectedDetail.commands.map(c => `
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card position-relative overflow-hidden h-100">
        <i class="fa-solid ${esc(c.icon)} position-absolute top-0 end-0 fs-1 opacity-25 m-3"></i>
        <div class="card-body position-relative">
          <h2 class="h6">${esc(c.label)}</h2>
          <div class="small text-secondary mb-3">${esc(c.command)}</div>
          <button class="btn btn-primary btn-sm" data-command="${esc(c.command)}" data-action="sendCommand" ${loadingCommands.has(c.command) ? 'disabled' : ''}>${loadingCommands.has(c.command) ? '<span class="spinner-border spinner-border-sm me-1"></span>Requesting' : '<i class="fa-solid fa-paper-plane me-1"></i>Request'}</button>
        </div>
      </div>
    </div>`).join('');
  renderUplinkCards([...(selectedDetail.recent.telemetry || []), ...(selectedDetail.recent.events || []), ...(selectedDetail.recent.raw || [])]);
  renderDownlinkRequests(selectedDetail.recent.commands || []);
}

function renderUplinkCards(rows) {
  const latestByType = [];
  const seen = new Set();
  for (const row of rows) {
    const payload = rowPayload(row);
    const key = String(payload?.type || payload?.source?.nativeType || payload?.debug?.payload || 'raw');
    if (seen.has(key)) continue;
    seen.add(key);
    latestByType.push(row);
  }

  els.uplinkCount.textContent = latestByType.length ? `${latestByType.length} latest` : '';
  els.uplinkCards.innerHTML = latestByType.length
    ? latestByType.map(renderUplinkCard).join('')
    : '<div class="col-12"><div class="text-secondary border rounded bg-body-tertiary p-3">No uplink data yet.</div></div>';
}

function renderUplinkCard(row) {
  const payload = rowPayload(row) || {};
  const type = payload.type || 'raw_uplink';
  const data = payload.data && typeof payload.data === 'object' ? payload.data : {};
  const meta = payload.source?.nativeType || payload.debug?.protocol || '';
  const timestamp = payload.occurredAt || payload.recordedAt || row?.recorded_at || '';
  const card = uplinkCardContent(type, data, payload);

  return `
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between gap-2 mb-2">
            <div>
              <div class="small text-secondary">${esc(titleize(type))}</div>
              <h3 class="h5 mb-0">${esc(card.value)}</h3>
            </div>
            <i class="fa-solid ${esc(card.icon)} fs-3 text-secondary"></i>
          </div>
          ${card.details ? `<div class="small text-secondary">${card.details}</div>` : ''}
          <div class="small text-secondary mt-3">${esc(when(timestamp) || 'time unknown')}${meta ? ` · ${esc(meta)}` : ''}</div>
        </div>
      </div>
    </div>`;
}

function uplinkCardContent(type, data, payload) {
  if (type === 'heart_rate') return {icon: 'fa-heart-pulse', value: `${data.bpm ?? '-'} bpm`};
  if (type === 'blood_pressure') return {icon: 'fa-stethoscope', value: `${data.systolicMmHg ?? '-'} / ${data.diastolicMmHg ?? '-'} mmHg`, details: data.pulseBpm ? `Pulse ${esc(data.pulseBpm)} bpm` : ''};
  if (type === 'blood_oxygen') return {icon: 'fa-droplet', value: `${data.spo2Percent ?? '-'}% SpO2`};
  if (type === 'temperature') return {icon: 'fa-temperature-half', value: `${data.bodyCelsius ?? '-'} °C`};
  if (type === 'battery') return {icon: 'fa-battery-three-quarters', value: `${data.percent ?? '-'}%`, details: data.charging === true ? 'Charging' : (data.charging === false ? 'Not charging' : '')};
  if (type === 'activity') return {icon: 'fa-person-walking', value: `${data.steps ?? 0} steps`, details: compactDetails(data, ['distanceMeters', 'caloriesKcal'])};
  if (type === 'location') return {icon: 'fa-location-dot', value: data.lat && data.lon ? `${data.lat}, ${data.lon}` : 'Location update', details: compactDetails(data, ['source', 'gpsValid', 'speedKmh', 'accuracyMeters'])};
  if (type === 'alarm') return {icon: 'fa-triangle-exclamation', value: alarmValue(data), details: compactDetails(data, ['code', 'lowBattery', 'fall', 'wearingNotice'])};
  if (type === 'heartbeat') return {icon: 'fa-signal', value: 'Heartbeat'};
  if (payload.debug) return {icon: 'fa-arrow-up', value: `${payload.debug.size ?? '-'} bytes`, details: esc(String(payload.debug.payload ?? '')).slice(0, 90)};
  return {icon: 'fa-circle-info', value: titleize(type), details: compactDetails(data, Object.keys(data).slice(0, 4))};
}

function alarmValue(data) {
  if (data.sos) return 'SOS';
  if (data.fall) return 'Fall detected';
  if (data.lowBattery) return 'Low battery';
  return 'Alarm';
}

function compactDetails(data, keys) {
  return keys
    .filter(key => data[key] !== undefined && data[key] !== null && data[key] !== '')
    .map(key => `${esc(titleize(key))}: ${esc(data[key])}`)
    .join(' · ');
}

function renderDownlinkRequests(commands) {
  els.downlinkRequests.innerHTML = commands.length ? `
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr><th>Requested</th><th>Request</th><th>Status</th><th>Response</th><th>Details</th></tr>
        </thead>
        <tbody>
          ${commands.map(renderDownlinkRow).join('')}
        </tbody>
      </table>
    </div>` : '<div class="text-secondary border rounded bg-body-tertiary p-3">No downlink requests yet.</div>';
}

function renderDownlinkRow(command) {
  const status = String(command.status || 'unknown');
  return `
    <tr>
      <td class="text-nowrap small">${esc(when(command.requestedAt) || '-')}</td>
      <td><div class="fw-semibold">${esc(command.label || command.nativeType || 'Request')}</div><div class="small text-secondary">${esc(command.nativeType || '')}</div></td>
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
  return `<span class="badge ${cls}">${esc(status)}</span>`;
}

function expectedReplies(command) {
  return Array.isArray(command.expectedReplyTypes) && command.expectedReplyTypes.length
    ? `Waiting for ${command.expectedReplyTypes.join(', ')}`
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
  els.deviceModalLabel.textContent = 'Add Device';
  els.deviceForm.reset();
  delete els.deviceImei.dataset.originalImei;
  populateModelOptions();
  deviceModal.show();
}

function editDevice(imei, supplier, model) {
  els.deviceModalLabel.textContent = 'Edit Device';
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
  if (!imei || !supplier || !model) { alert('All fields are required'); return; }

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
  if (!confirm(`Delete device ${imei}?`)) return;
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
      <td><span class="badge ${s.enabled ? 'text-bg-success' : 'text-bg-secondary'}">${s.enabled ? 'enabled' : 'disabled'}</span></td>
      <td>
        <button class="btn btn-outline-${s.enabled ? 'warning' : 'success'} btn-sm" data-id="${s.id}" data-enabled="${s.enabled ? '1' : ''}" data-action="toggleSupplier" title="${s.enabled ? 'Disable' : 'Enable'}"><i class="fa-solid fa-${s.enabled ? 'pause' : 'play'}"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${s.id}" data-action="deleteSupplier" title="Delete"><i class="fa-solid fa-trash"></i></button>
      </td>
    </tr>`).join('');
  supplierModal.show();
}

async function saveSupplier() {
  const name = els.supplierName.value.trim();
  if (!name) { alert('Name is required'); return; }
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
  if (!confirm(`Delete supplier?`)) return;
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
  els.modelModalLabel.textContent = 'Models';
  els.saveModelBtn.innerHTML = '<i class="fa-solid fa-plus"></i>';
  els.modelSupplier.innerHTML = '<option value="">Supplier...</option>' + suppliersData.suppliers.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  els.modelListBody.innerHTML = modelsData.models.map(m => `
    <tr>
      <td>${modelImageHtml(m)}</td>
      <td>${esc(m.supplier)}</td>
      <td>${esc(m.model)}</td>
      <td>${esc(m.protocol)}</td>
      <td>
        <button class="btn btn-outline-secondary btn-sm" data-id="${m.id}" data-supplier-id="${m.supplier_id}" data-model="${esc(m.model)}" data-protocol="${esc(m.protocol)}" data-action="editModel" title="Edit"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-outline-danger btn-sm" data-id="${m.id}" data-action="deleteModel"><i class="fa-solid fa-trash"></i></button>
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
  els.modelModalLabel.textContent = 'Edit Model';
  els.saveModelBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i>';
}

async function saveModel() {
  const supplierId = parseInt(els.modelSupplier.value);
  const model = els.modelModel.value.trim();
  const protocol = els.modelProtocol.value.trim();
  if (!supplierId || !model || !protocol) { alert('All fields are required'); return; }

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
  if (!confirm(`Delete model?`)) return;
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
    uplinkCount: document.getElementById('uplinkCount'),
    uplinkCards: document.getElementById('uplinkCards'),
    commandGrid: document.getElementById('commandGrid'),
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

  els.commandGrid.addEventListener('click', e => {
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
