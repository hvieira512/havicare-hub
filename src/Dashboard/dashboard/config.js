import {esc, fieldLabel, titleize, when} from './format.js';
import {statusBadge} from './renderers.js';

const CATEGORY_LABELS = {
    'vivistar-iw': {
        contacts: 'Contactos',
        alerts: 'Alertas',
        health: 'Saude',
        system: 'Sistema',
        intervals: 'Intervalos',
    },
    'wonlex-json': {
        contacts: 'Contactos',
        alerts: 'Alarmes',
        health: 'Saude',
        measurements: 'Medições',
        system: 'Sistema',
        intervals: 'Intervalos',
    },
};

const CATEGORY_ORDER = {
    'vivistar-iw': ['contacts', 'alerts', 'health', 'system', 'intervals'],
    'wonlex-json': ['intervals', 'contacts', 'measurements', 'alerts', 'health', 'system'],
};

let uidCounter = 0;

export function catalogForProtocol(protocol) {
    const catalog = globalThis.dashboardConfigurationCatalog?.[protocol] || [];
    return Array.isArray(catalog) ? catalog : [];
}

export function groupedCatalog(catalog) {
    const groups = [];
    const index = new Map();

    for (const entry of catalog) {
        const key = entry.category || 'general';
        if (!index.has(key)) {
            index.set(key, {key, label: '', entries: []});
            groups.push(index.get(key));
        }
        index.get(key).entries.push(entry);
    }

    return groups;
}

export function renderDeviceConfigurationRoot(context) {
    const {protocol, catalog, configurations, supplier = '', model = '', disabled = false, activeCategory = ''} = context;
    if (!protocol) {
        return emptyConfigurationState('Selecione fornecedor e modelo para ver as configurações.');
    }

    if (!catalog.length) {
        return emptyConfigurationState('Este protocolo não tem configurações suportadas.');
    }

    const rowsByKey = rowsByConfigKey(configurations);
    const groups = groupedCatalog(catalog);
    const order = CATEGORY_ORDER[protocol] || [];
    groups.sort((a, b) => {
        const ai = order.indexOf(a.key);
        const bi = order.indexOf(b.key);
        if (ai !== bi) {
            return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi);
        }
        return a.key.localeCompare(b.key);
    });
    const currentCategory = groups.some(group => group.key === activeCategory)
        ? activeCategory
        : (groups[0]?.key || '');
    for (const group of groups) {
        group.label = categoryLabel(protocol, group.key);
    }

    return `
        <div class="vstack gap-3">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="fw-semibold">Configurações do dispositivo</div>
                    <div class="small text-secondary">${esc(protocol)}${supplier || model ? ` · ${esc(supplier)} ${esc(model)}` : ''}</div>
                </div>
                <span class="badge text-bg-secondary">${catalog.length} opções</span>
            </div>
            <div class="nav nav-tabs flex-wrap gap-1" role="tablist">
                ${groups.map(group => `
                    <button type="button" class="nav-link ${group.key === currentCategory ? 'active' : ''}" data-config-category="${esc(group.key)}">
                        ${esc(group.label)}
                    </button>
                `).join('')}
            </div>
            <div class="tab-content">
                ${groups.map(group => `
                    <div class="tab-pane fade ${group.key === currentCategory ? 'show active' : ''}" data-config-category-pane="${esc(group.key)}">
                        ${group.entries.map(entry => renderConfigSection(protocol, entry, rowsByKey[entry.key] || null, disabled)).join('')}
                    </div>
                `).join('')}
            </div>
        </div>`;
}

export function renderConfigSection(protocol, entry, row, disabled = false) {
    const desired = normalizeDesired(entry, row?.desired_payload);
    const reported = row?.reported_payload && Object.keys(row.reported_payload).length ? row.reported_payload : null;
    const status = row?.last_status || '';
    const help = configHelp(entry);

    return `
        <section class="border rounded-3 p-3 mb-3 bg-body-tertiary" data-config-section data-config-key="${esc(entry.key)}" data-config-input="${esc(entry.input || 'json')}" data-config-protocol="${esc(protocol)}">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="fw-semibold">${esc(entry.label || entry.key)}</div>
                    <div class="small text-secondary">${esc(entry.command)} · ${esc(titleize(entry.input || 'json'))}${help ? ` · ${esc(help)}` : ''}</div>
                </div>
                <div class="text-end">
                    ${status ? statusBadge(status) : '<span class="badge text-bg-light">sem estado</span>'}
                </div>
            </div>
            <form class="mt-3" data-config-form data-config-key="${esc(entry.key)}" ${disabled ? 'data-config-disabled="1"' : ''}>
                ${renderConfigInputs(entry, desired)}
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="button" class="btn btn-primary btn-sm" data-action="saveConfig" data-config-key="${esc(entry.key)}" ${disabled ? 'disabled' : ''}>Guardar e enviar</button>
                    <button type="reset" class="btn btn-outline-secondary btn-sm" ${disabled ? 'disabled' : ''}>Repor</button>
                </div>
            </form>
            <div class="small text-secondary mt-3">
                ${reported ? `Reportado: ${esc(JSON.stringify(reported))}` : 'Ainda sem resposta do dispositivo.'}
                ${row?.requestedAt ? ` · Pedido: ${esc(when(row.requestedAt))}` : ''}
            </div>
        </section>`;
}

export function renderConfigInputs(entry, desired) {
    const input = entry.input || 'json';
    if (input === 'toggle') {
        return toggleInput(entry, desired);
    }
    if (input === 'fallSensitivity') {
        return fallSensitivityInput(desired);
    }
    if (input === 'number') {
        return numberInput(entry, desired);
    }
    if (input === 'intervalToggle') {
        return intervalToggleInput(entry, desired);
    }
    if (input === 'workingMode') {
        return workingModeInput(desired);
    }
    if (input === 'bloodPressure') {
        return bloodPressureInput(desired);
    }
    if (input === 'list') {
        return listInput(entry, desired, 'numbers', 'Números SOS');
    }
    if (input === 'contacts') {
        return contactsInput(entry, desired);
    }
    if (input === 'reminders') {
        return remindersInput(desired);
    }

    return jsonInput(desired);
}

export function readConfigPayload(section) {
    const input = section.dataset.configInput || 'json';
    if (input === 'toggle') {
        return {enabled: readCheckbox(section, 'enabled')};
    }
    if (input === 'fallSensitivity') {
        return {sensitivity: readNumber(section, 'sensitivity')};
    }
    if (input === 'number') {
        return {[firstFieldName(section)]: readNumber(section, firstFieldName(section))};
    }
    if (input === 'intervalToggle') {
        return {
            enabled: readCheckbox(section, 'enabled'),
            intervalMinutes: readNumber(section, 'intervalMinutes'),
        };
    }
    if (input === 'workingMode') {
        const mode = readNumber(section, 'mode');
        const payload = {mode};
        if (mode === 8) {
            payload.intervalSeconds = readNumber(section, 'intervalSeconds');
            payload.gpsEnabled = readCheckbox(section, 'gpsEnabled');
        }
        return payload;
    }
    if (input === 'bloodPressure') {
        return {
            systolic: readNumber(section, 'systolic'),
            diastolic: readNumber(section, 'diastolic'),
        };
    }
    if (input === 'list') {
        return {numbers: readTextArray(section, 'numbers').slice(0, 3)};
    }
    if (input === 'contacts') {
        return {contacts: readContacts(section)};
    }
    if (input === 'reminders') {
        return readReminders(section);
    }

    return readJson(section);
}

export function defaultConfigPayload(entry) {
    const input = entry.input || 'json';
    const field = entry.fields?.[0] || 'value';
    if (input === 'toggle') return {enabled: true};
    if (input === 'fallSensitivity') return {sensitivity: 2};
    if (input === 'number') return {[field]: 0};
    if (input === 'intervalToggle') return {enabled: true, intervalMinutes: 60};
    if (input === 'workingMode') return {mode: 1};
    if (input === 'bloodPressure') return {systolic: 120, diastolic: 80};
    if (input === 'list') return {numbers: ['', '', '']};
    if (input === 'contacts') return {contacts: [{name: '', phone: ''}]};
    if (input === 'reminders') return {masterEnabled: true, items: []};
    return {};
}

function rowsByConfigKey(rows) {
    const indexed = {};
    for (const row of rows || []) {
        indexed[row.config_key] = row;
    }
    return indexed;
}

function normalizeDesired(entry, desired) {
    if (desired && Object.keys(desired).length) {
        return desired;
    }
    return defaultConfigPayload(entry);
}

function emptyConfigurationState(text) {
    return `<div class="text-secondary border rounded bg-body-tertiary p-3">${esc(text)}</div>`;
}

function configHelp(entry) {
    if ((entry.input || '') === 'list' && (entry.limit || 0) > 0) {
        return `limite ${entry.limit}`;
    }
    if ((entry.input || '') === 'contacts' && (entry.limit || 0) > 0) {
        return `limite ${entry.limit}`;
    }
    if ((entry.key || '') === 'whitelistSwitch') {
        return 'ativa os contactos da lista telefónica do BP14';
    }
    return '';
}

function categoryLabel(protocol, category) {
    const labels = CATEGORY_LABELS[protocol] || {};
    return labels[category] || titleize(category);
}

function firstFieldName(section) {
    return section.querySelector('[data-config-field]')?.dataset.configField || 'value';
}

function readCheckbox(section, field) {
    return section.querySelector(`[data-config-field="${CSS.escape(field)}"]`)?.checked || false;
}

function readNumber(section, field) {
    const nodes = Array.from(section.querySelectorAll(`[data-config-field="${CSS.escape(field)}"]`));
    const input = nodes.find(node => 'checked' in node ? node.checked : false) || nodes[0] || null;
    const value = input?.value ?? '';
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
}

function readTextArray(section, field) {
    return Array.from(section.querySelectorAll(`[data-config-field="${CSS.escape(field)}"]`))
        .map(input => String(input.value || '').trim())
        .filter(Boolean);
}

function readContacts(section) {
    return Array.from(section.querySelectorAll('[data-repeat-row="contacts"]')).map(row => ({
        name: String(row.querySelector('[data-repeat-field="name"]')?.value || '').trim(),
        phone: String(row.querySelector('[data-repeat-field="phone"]')?.value || '').trim(),
    })).filter(contact => contact.name !== '' || contact.phone !== '');
}

function readReminders(section) {
    const items = Array.from(section.querySelectorAll('[data-repeat-row="reminders"]')).map(row => ({
        time: String(row.querySelector('[data-repeat-field="time"]')?.value || '').trim(),
        days: readReminderDays(row),
        enabled: row.querySelector('[data-repeat-field="enabled"]')?.checked || false,
        type: readCheckedNumberFromRow(row, 'type', 1),
    })).filter(item => item.time !== '' || item.days !== '');

    return {
        masterEnabled: section.querySelector('[data-config-field="masterEnabled"]')?.checked || false,
        items,
    };
}

function readNumberFromRow(row, field) {
    const value = row.querySelector(`[data-repeat-field="${CSS.escape(field)}"]`)?.value ?? '';
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
}

function readCheckedNumberFromRow(row, field, fallback = 0) {
    const value = row.querySelector(`[data-repeat-field="${CSS.escape(field)}"]:checked`)?.value ?? '';
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : fallback;
}

function readReminderDays(row) {
    return Array.from(row.querySelectorAll('[data-repeat-field="days"]:checked'))
        .map(input => String(input.value || ''))
        .join('');
}

function jsonInput(desired) {
    return `
        <div>
            <label class="form-label form-label-sm">JSON</label>
            <textarea class="form-control font-monospace" rows="4" data-config-field="json">${esc(JSON.stringify(desired, null, 2))}</textarea>
        </div>`;
}

function readJson(section) {
    const textarea = section.querySelector('[data-config-field="json"]');
    if (!textarea) {
        return {};
    }

    try {
        return JSON.parse(textarea.value || '{}');
    } catch {
        throw new Error('JSON inválido para esta configuração');
    }
}

function toggleInput(entry, desired) {
    const field = entry.fields?.[0] || 'enabled';
    const checked = boolValue(desired[field], true);
    return `
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" data-config-field="${esc(field)}" ${checked ? 'checked' : ''}>
            <label class="form-check-label">${esc(fieldLabel(field))}</label>
        </div>`;
}

function fallSensitivityInput(desired) {
    const current = parseInt(String(desired.sensitivity ?? 2), 10) || 2;
    const options = [
        {value: 1, label: 'Baixa', icon: 'fa-feather-pointed', className: 'btn-success'},
        {value: 2, label: 'Normal', icon: 'fa-shield-heart', className: 'btn-warning'},
        {value: 3, label: 'Alta', icon: 'fa-triangle-exclamation', className: 'btn-danger'},
    ];

    return `
        <div>
            <label class="form-label form-label-sm">Sensibilidade</label>
            <div class="btn-group w-100" role="group" aria-label="Sensibilidade de queda">
                ${options.map(option => `
                    <input
                        class="btn-check"
                        type="radio"
                        name="fallSensitivity"
                        id="fallSensitivity${option.value}"
                        data-config-field="sensitivity"
                        value="${option.value}"
                        ${option.value === current ? 'checked' : ''}>
                    <label class="btn ${option.className}" for="fallSensitivity${option.value}">
                        <i class="fa-solid ${option.icon} me-2"></i>${option.label}
                    </label>
                `).join('')}
            </div>
        </div>`;
}

function numberInput(entry, desired) {
    const field = entry.fields?.[0] || 'value';
    const value = desired[field] ?? 0;
    return `
        <div>
            <label class="form-label form-label-sm">${esc(fieldLabel(field))}</label>
            <input class="form-control" type="number" min="0" step="1" data-config-field="${esc(field)}" value="${esc(String(value))}">
        </div>`;
}

function intervalToggleInput(entry, desired) {
    return `
        <div class="row g-3">
            <div class="col-md-4">
                <div class="form-check form-switch mt-4">
                    <input class="form-check-input" type="checkbox" role="switch" data-config-field="enabled" ${boolValue(desired.enabled, true) ? 'checked' : ''}>
                    <label class="form-check-label">Ativar</label>
                </div>
            </div>
            <div class="col-md-8">
                <label class="form-label form-label-sm">Intervalo (minutos)</label>
                <input class="form-control" type="number" min="1" step="1" data-config-field="intervalMinutes" value="${esc(String(desired.intervalMinutes ?? 60))}">
            </div>
        </div>`;
}

function workingModeInput(desired) {
    const mode = parseInt(String(desired.mode ?? 1), 10) || 1;
    const intervalSeconds = desired.intervalSeconds ?? 60;
    const gpsEnabled = boolValue(desired.gpsEnabled, true);
    const options = [
        {value: 1, title: 'Normal', description: 'Envia localização a cada 15 minutos com Wi-Fi e LBS.', icon: 'fa-clock', className: 'btn-outline-primary'},
        {value: 2, title: 'Poupança', description: 'Envia localização a cada 60 minutos com Wi-Fi e LBS.', icon: 'fa-battery-half', className: 'btn-outline-success'},
        {value: 3, title: 'Emergência', description: 'Envia localização a cada 1 minuto com GPS, Wi-Fi e LBS.', icon: 'fa-bolt', className: 'btn-outline-danger'},
        {value: 8, title: 'Personalizado', description: 'Permite definir intervalo em segundos e ligar ou desligar GPS.', icon: 'fa-sliders', className: 'btn-outline-dark'},
    ];

    return `
        <div class="vstack gap-3" data-working-mode-root>
            <div>
                <label class="form-label form-label-sm">Modo</label>
                <div class="row g-2" data-working-mode-select>
                    ${options.map(option => `
                        <div class="col-12 col-md-6">
                            <input
                                class="btn-check"
                                type="radio"
                                name="workingMode"
                                id="workingMode${option.value}"
                                data-config-field="mode"
                                value="${option.value}"
                                ${option.value === mode ? 'checked' : ''}>
                            <label class="btn ${option.className} w-100 h-100 text-start d-flex gap-3 align-items-start p-3" for="workingMode${option.value}">
                                <i class="fa-solid ${option.icon} mt-1"></i>
                                <span>
                                    <span class="d-block fw-semibold">${option.title}</span>
                                    <span class="d-block small">${option.description}</span>
                                </span>
                            </label>
                        </div>
                    `).join('')}
                </div>
            </div>
            <div class="${mode === 8 ? '' : 'd-none'}" data-working-mode-extra>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label form-label-sm">Intervalo de envio (segundos)</label>
                        <input class="form-control" type="number" min="30" step="1" data-config-field="intervalSeconds" value="${esc(String(intervalSeconds))}">
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" role="switch" data-config-field="gpsEnabled" ${gpsEnabled ? 'checked' : ''}>
                            <label class="form-check-label">GPS ativo</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
}

function bloodPressureInput(desired) {
    return `
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label form-label-sm">Sistólica</label>
                <input class="form-control" type="number" min="0" step="1" data-config-field="systolic" value="${esc(String(desired.systolic ?? 120))}">
            </div>
            <div class="col-md-6">
                <label class="form-label form-label-sm">Diastólica</label>
                <input class="form-control" type="number" min="0" step="1" data-config-field="diastolic" value="${esc(String(desired.diastolic ?? 80))}">
            </div>
        </div>`;
}

function listInput(entry, desired, field, label) {
    const limit = Math.max(1, parseInt(String(entry.limit ?? 3), 10) || 3);
    const values = Array.isArray(desired[field]) ? desired[field] : [];
    const rows = Array.from({length: limit}, (_, index) => values[index] ?? '');
    return `
        <div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label form-label-sm mb-0">${esc(label)}</label>
                <span class="small text-secondary">${limit} itens</span>
            </div>
            <div class="vstack gap-2">
                ${rows.map((value, index) => `
                    <input class="form-control" type="text" placeholder="${esc(label)} ${index + 1}" data-config-field="${esc(field)}" value="${esc(String(value || ''))}">
                `).join('')}
            </div>
        </div>`;
}

function contactsInput(entry, desired) {
    const limit = Math.max(1, parseInt(String(entry.limit ?? 10), 10) || 10);
    const contacts = Array.isArray(desired.contacts) ? desired.contacts : [];
    const rows = contacts.length ? contacts.slice(0, limit) : [{}];
    return `
        <div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label form-label-sm mb-0">Contactos</label>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addContactRow">Adicionar</button>
            </div>
            <div class="small text-secondary mb-2">${limit} contactos máximos</div>
            <div class="vstack gap-2" data-repeat-limit="${limit}">
                ${rows.map((contact, index) => `
                    <div class="row g-2 align-items-end" data-repeat-row="contacts">
                        <div class="col-md-6">
                            <input class="form-control" type="text" placeholder="Nome ${index + 1}" data-repeat-field="name" value="${esc(String(contact.name || ''))}">
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2">
                                <input class="form-control" type="text" placeholder="Telefone ${index + 1}" data-repeat-field="phone" value="${esc(String(contact.phone || ''))}">
                                <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeContactRow">-</button>
                            </div>
                        </div>
                    </div>
                `).join('')}
            </div>
        </div>`;
}

function remindersInput(desired) {
    const items = Array.isArray(desired.items) && desired.items.length ? desired.items : [{}];
    return `
        <div class="vstack gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="masterEnabled" ${boolValue(desired.masterEnabled, true) ? 'checked' : ''}>
                <label class="form-check-label">Ativar todos os alarmes</label>
            </div>
            <div class="small text-secondary">Cada alarme suporta hora, dias da semana, estado e tipo: medicação, água ou sedentarismo.</div>
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addReminderRow">Adicionar lembrete</button>
            </div>
            <div class="vstack gap-2" data-reminders-list>
                ${items.map(item => reminderRow(item)).join('')}
            </div>
        </div>`;
}

function reminderRow(item = {}) {
    const normalizedDays = String(item.days || '').replace(/[^1-7]/g, '');
    const reminderType = parseInt(String(item.type ?? 1), 10) || 1;
    const rowId = nextUid('reminder');
    const dayButtons = [
        {value: '1', label: 'Seg'},
        {value: '2', label: 'Ter'},
        {value: '3', label: 'Qua'},
        {value: '4', label: 'Qui'},
        {value: '5', label: 'Sex'},
        {value: '6', label: 'Sab'},
        {value: '7', label: 'Dom'},
    ];
    const typeButtons = [
        {value: 1, label: 'Medicação', icon: 'fa-pills', className: 'btn-outline-primary'},
        {value: 2, label: 'Água', icon: 'fa-glass-water', className: 'btn-outline-info'},
        {value: 3, label: 'Sedentarismo', icon: 'fa-person-walking', className: 'btn-outline-warning'},
    ];
    return `
        <div class="border rounded p-3 bg-body" data-repeat-row="reminders">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Hora</label>
                    <input class="form-control" type="time" data-repeat-field="time" value="${esc(formatReminderTime(item.time))}">
                </div>
                <div class="col-md-6">
                    <label class="form-label form-label-sm d-block">Dias</label>
                    <div class="btn-group flex-wrap w-100" role="group" aria-label="Dias da semana">
                        ${dayButtons.map(day => `
                            <input
                                class="btn-check"
                                type="checkbox"
                                id="${rowId}-day-${day.value}"
                                data-repeat-field="days"
                                value="${day.value}"
                                ${normalizedDays.includes(day.value) ? 'checked' : ''}>
                            <label class="btn btn-outline-secondary btn-sm" for="${rowId}-day-${day.value}">${day.label}</label>
                        `).join('')}
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm d-block">Tipo</label>
                    <div class="btn-group w-100" role="group" aria-label="Tipo de lembrete">
                        ${typeButtons.map(option => `
                            <input
                                class="btn-check"
                                type="radio"
                                name="${rowId}-type"
                                id="${rowId}-type-${option.value}"
                                data-repeat-field="type"
                                value="${option.value}"
                                ${reminderType === option.value ? 'checked' : ''}>
                            <label class="btn ${option.className} btn-sm" for="${rowId}-type-${option.value}">
                                <i class="fa-solid ${option.icon} me-1"></i>${option.label}
                            </label>
                        `).join('')}
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" role="switch" data-repeat-field="enabled" ${boolValue(item.enabled, true) ? 'checked' : ''}>
                        <label class="form-check-label">Ativo</label>
                    </div>
                </div>
                <div class="col-md-1 text-end">
                    <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeReminderRow">-</button>
                </div>
            </div>
        </div>`;
}

function boolValue(value, fallback = false) {
    if (value === true || value === 1 || value === '1') {
        return true;
    }
    if (value === false || value === 0 || value === '0') {
        return false;
    }
    return fallback;
}

function formatReminderTime(value) {
    const digits = String(value || '').replace(/[^0-9]/g, '');
    if (digits.length !== 4) {
        return '';
    }
    return `${digits.slice(0, 2)}:${digits.slice(2, 4)}`;
}

function nextUid(prefix) {
    uidCounter += 1;
    return `${prefix}-${uidCounter}`;
}
