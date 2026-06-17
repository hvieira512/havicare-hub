import {commandLabel, esc, featureLabel, fieldLabel, titleize} from './format.js';

export const supplierProtocolDefaults = {
    Wonlex: 'wonlex-json',
    Vivistar: 'vivistar-iw',
    '4P Touch': 'four-p-touch',
};

export function modelImageHtml(modelInfo) {
    return modelInfo?.image
        ? `<img src="${esc(modelInfo.image)}" class="object-fit-contain" alt="${esc(modelInfo.model)}" style="width:40px;height:40px;">`
        : '<i class="fa-solid fa-microchip fa-xl text-secondary" style="width:40px"></i>';
}

export function modelPreviewHtml(modelInfo, label = 'Modelo') {
    return modelInfo?.image
        ? `<img src="${esc(modelInfo.image)}" class="object-fit-contain" alt="${esc(modelInfo.model || label)}">`
        : `<div class="text-center text-secondary"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${esc(label)}</div></div>`;
}

export function renderButtonGroup(container, items, selected, action, valueKey = 'value', labelKey = 'label') {
    container.innerHTML = items.length ? items.map(item => {
        const value = String(item[valueKey] ?? '');
        const label = String(item[labelKey] ?? value);
        return `<button type="button" class="btn btn-sm ${value === selected ? 'btn-primary' : 'btn-outline-primary'}" data-action="${esc(action)}" data-value="${esc(value)}">${esc(label)}</button>`;
    }).join('') : '<div class="text-secondary border rounded bg-body-tertiary px-3 py-2 small">Sem opções disponíveis</div>';
}

export function emptyPanel(text) {
    return `<div class="text-secondary border rounded bg-body-tertiary p-3">${esc(text)}</div>`;
}

export function commandFeature(command) {
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

export function cardTone(type, command = {}) {
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

export function requestCardContent(type) {
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

export function uplinkCardContent(type, data) {
    if (type === 'heart_rate') return {icon: 'fa-heart-pulse', value: `${data.bpm ?? '-'} bpm`};
    if (type === 'blood_pressure') return {icon: 'fa-stethoscope', value: `${data.systolicMmHg ?? '-'} / ${data.diastolicMmHg ?? '-'} mmHg`, details: data.pulseBpm ? `Pulso ${esc(data.pulseBpm)} bpm` : ''};
    if (type === 'blood_oxygen') return {icon: 'fa-droplet', value: `${data.spo2Percent ?? '-'}% SpO2`};
    if (type === 'blood_sugar') return {icon: 'fa-vial', value: `${data.glucoseMgDl ?? '-'} mg/dL`};
    if (type === 'temperature') return {icon: 'fa-temperature-half', value: `${data.bodyCelsius ?? '-'} °C`};
    if (type === 'battery') return {icon: 'fa-battery-three-quarters', value: `${data.percent ?? '-'}%`, details: batteryDetails(data)};
    if (type === 'activity') return {icon: 'fa-person-walking', value: `${data.steps ?? 0} passos`, details: compactDetails(data, ['distanceMeters', 'caloriesKcal', 'exerciseSeconds', 'standMinutes'])};
    if (type === 'location') return {icon: 'fa-location-dot', value: data.lat && data.lon ? `${data.lat}, ${data.lon}` : 'Atualização de localização', details: compactDetails(data, ['source', 'gpsValid', 'speedKmh', 'accuracyMeters'])};
    if (type === 'alarm') return {icon: 'fa-triangle-exclamation', value: alarmValue(data), details: compactDetails(data, ['code', 'lowBattery', 'fall', 'wearingNotice'])};
    if (type === 'heartbeat') return {icon: 'fa-signal', value: 'Sinal de vida', details: compactDetails(data, ['batteryPercent', 'gsmSignal', 'satelliteCount', 'steps', 'workMode'])};
    if (type === 'sleep') return {icon: 'fa-bed', value: 'Dados de sono'};
    if (type === 'ecg') return {icon: 'fa-wave-square', value: 'Dados de ECG'};
    if (type === 'hrv') return {icon: 'fa-chart-line', value: 'Dados de VFC'};
    if (type === 'weather') return {icon: 'fa-cloud-sun', value: data.summary || 'Dados meteorológicos', details: compactDetails(data, ['temperatureCelsius', 'lowCelsius', 'highCelsius', 'humidityPercent', 'reportedAt'])};
    return {icon: 'fa-circle-info', value: featureLabel(type), details: compactDetails(data, Object.keys(data).slice(0, 4))};
}

function batteryDetails(data) {
    if (data.chargingState === 1) return 'A carregar';
    if (data.chargingState === 0) return 'Não está a carregar';
    return compactDetails(data, ['batteryType']);
}

export function renderRequestCardShell(command, loading) {
    const type = commandFeature(command);
    const card = requestCardContent(type);
    const tone = cardTone(type, command);

    return `
        <div class="col-12">
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

export function statusBadge(status) {
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
