import {esc} from "../../format.js";

export function takePillsInput(desired, meta = {}) {
    const reminderText = String(desired.reminderText || "");
    const voiceData = String(desired.voiceData || "");
    const voiceMimeType = String(desired.voiceMimeType || "audio/webm");
    const hasVoiceData = voiceData.trim() !== "" || desired.voiceDataAvailable === true;
    const voiceEnabled = normalizeVoiceEnabled(desired, hasVoiceData);
    const previewSrc = voicePreviewSrc(voiceData, voiceMimeType);
    const frequencyOptions = frequencyOptionsFor(meta);
    const numberLimit = Math.max(1, parseInt(String(meta.limit ?? 3), 10) || 3);
    const reminders = normalizeReminderSettings(desired).slice(0, numberLimit);
    return `<div class="vstack gap-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><div><div class="fw-semibold">Horários</div><div class="small text-secondary">Até ${esc(String(numberLimit))} lembretes.</div></div><button type="button" class="btn btn-outline-secondary btn-sm" data-action="addTakePillsReminder" ${reminders.length >= numberLimit ? "disabled" : ""}><i class="fa-solid fa-plus me-2"></i>Adicionar lembrete</button></div>
        <div class="vstack gap-2" data-takepills-reminders-list data-repeat-limit="${esc(String(numberLimit))}">${reminders.map((settings, index) => takePillsReminderGroup(settings, index, frequencyOptions)).join("")}</div>
        <div class="border rounded-3 p-3 vstack gap-3"><div><div class="fw-semibold">Mensagem do plano</div><div class="small text-secondary">O protocolo 4P Touch aplica o mesmo texto e áudio a todos os horários.</div></div>
        <div><label class="form-label form-label-sm">Texto do lembrete</label><input class="form-control" type="text" data-config-field="reminderText" value="${esc(reminderText)}">
        <div class="vstack gap-2" data-takepills-audio><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" data-config-field="voiceEnabled" ${voiceEnabled ? "checked" : ""}><label class="form-check-label" data-switch-label data-switch-on="Áudio ligado" data-switch-off="Áudio desligado">${voiceEnabled ? "Áudio ligado" : "Áudio desligado"}</label></div>
        <fieldset class="vstack gap-2" data-takepills-audio-controls ${voiceEnabled ? "" : "disabled"}><input type="hidden" data-config-field="voiceData" value="${esc(voiceData)}"><input type="hidden" data-config-field="voiceMimeType" value="${esc(voiceMimeType)}"><div class="d-flex flex-wrap align-items-center gap-2">
        <button type="button" class="btn btn-outline-primary btn-sm" data-action="takePillsRecord"><i class="fa-solid fa-microphone me-2"></i>Gravar</button><button type="button" class="btn btn-outline-secondary btn-sm d-none" data-action="takePillsStop"><i class="fa-solid fa-stop me-2"></i>Parar</button><button type="button" class="btn btn-outline-danger btn-sm" data-action="takePillsClear"><i class="fa-solid fa-trash-can me-2"></i>Limpar</button><label class="btn btn-outline-secondary btn-sm mb-0"><i class="fa-solid fa-file-audio me-2"></i>Carregar<input type="file" class="d-none" accept="audio/*" data-action="takePillsFile"></label><span class="small text-secondary" data-takepills-status>${voiceEnabled ? (hasVoiceData ? "Áudio carregado" : "Sem áudio") : "Áudio desligado"}</span></div>
        <audio class="w-100" controls preload="none" data-takepills-preview ${hasVoiceData ? `src="${esc(previewSrc)}"` : ""}></audio></fieldset></div></div></div></div>`;
}

export function takePillsReminderGroup(settings, index, frequencyOptions) {
    const frequency = parseInt(String(settings.frequency ?? 1), 10) || 1;
    return `<div class="border rounded p-3 bg-body" data-takepills-reminder-group="${index}"><div class="d-flex justify-content-between align-items-center gap-2 mb-2"><span class="small fw-semibold" data-takepills-reminder-number>Lembrete ${index + 1}</span><button type="button" class="btn btn-outline-danger btn-sm" data-action="removeTakePillsReminder" aria-label="Remover lembrete"><i class="fa-solid fa-trash-can"></i></button></div><div class="row g-3 align-items-end">
        <div class="col-md-3"><label class="form-label form-label-sm">Hora</label><input class="form-control" type="text" inputmode="numeric" maxlength="5" pattern="[0-9]{2}:[0-9]{2}" placeholder="HH:MM" data-time-format="24h" data-takepills-field="reminderTime" data-takepills-index="${index}" value="${esc(settings.time)}"></div>
        <div class="col-md-3"><label class="form-label form-label-sm d-block">Estado</label><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" data-takepills-field="reminderEnabled" data-takepills-index="${index}" ${settings.enabled ? "checked" : ""}><label class="form-check-label" data-switch-label data-switch-on="Ligado" data-switch-off="Desligado">${settings.enabled ? "Ligado" : "Desligado"}</label></div></div>
        <div class="col-md-2"><label class="form-label form-label-sm">Frequência</label><select class="form-select" data-takepills-field="reminderFrequency" data-takepills-index="${index}" data-takepills-frequency>${frequencyOptions.map(option => `<option value="${esc(String(option.value))}" ${parseInt(String(option.value), 10) === frequency ? "selected" : ""}>${esc(String(option.label))}</option>`).join("")}</select></div>
        <div class="col-md-4 ${frequency === 3 ? "" : "d-none"}" data-takepills-custom-wrapper="${index}"><label class="form-label form-label-sm">Custom</label><input class="form-control" type="text" inputmode="numeric" maxlength="7" pattern="[01]{7}" placeholder="0111110" data-takepills-field="reminderCustom" data-takepills-index="${index}" value="${esc(settings.custom)}"></div></div></div>`;
}

function normalizeVoiceEnabled(desired, hasVoiceData) {
    if (typeof desired?.voiceEnabled === "boolean") return desired.voiceEnabled;
    if (typeof desired?.voiceEnabled === "string") return boolValue(desired.voiceEnabled, hasVoiceData);
    return hasVoiceData;
}

function normalizeReminderSettings(desired) {
    const base = desired?.reminderSettings;
    if (Array.isArray(base)) return base.map(normalizeReminder);
    if (typeof base === "string" && base.trim() !== "") return parseReminderString(base);
    if (base && typeof base === "object") return [normalizeReminder(base)];
    const legacy = ["reminderTime", "reminderEnabled", "reminderFrequency", "reminderCustom"].some(key => Object.prototype.hasOwnProperty.call(desired || {}, key));
    return legacy ? [{time: String(desired?.reminderTime ?? "08:00"), enabled: boolValue(desired?.reminderEnabled ?? desired?.enabled ?? true, true), frequency: parseInt(String(desired?.reminderFrequency ?? desired?.frequency ?? 1), 10) || 1, custom: String(desired?.reminderCustom ?? desired?.custom ?? "")}] : [];
}

function normalizeReminder(item) {
    if (typeof item === "string") {
        const parts = item.split("-");
        return {time: String(parts[0] ?? "08:00"), enabled: boolValue(parts[1] ?? true, true), frequency: parseInt(String(parts[2] ?? 1), 10) || 1, custom: String(parts.slice(3).join("-") ?? "")};
    }
    return {time: String(item.time ?? item.reminderTime ?? "08:00"), enabled: boolValue(item.enabled ?? item.switchState, true), frequency: parseInt(String(item.frequency ?? item.frequencies ?? 1), 10) || 1, custom: String(item.custom ?? item.reminderCustom ?? "")};
}

function parseReminderString(value) {
    const parts = value.split("-");
    const reminders = [];
    for (let index = 0; index < parts.length;) {
        const time = parts[index++] ?? "08:00";
        const enabled = boolValue(parts[index++] ?? true, true);
        const frequency = parseInt(parts[index++] ?? "1", 10) || 1;
        reminders.push({time, enabled, frequency, custom: frequency === 3 && index < parts.length ? parts[index++] : ""});
    }
    return reminders;
}

function voicePreviewSrc(voiceData, voiceMimeType) {
    const value = String(voiceData || "").trim();
    if (value === "" || value.startsWith("data:")) return value;
    return `data:${String(voiceMimeType || "audio/webm").trim() || "audio/webm"};base64,${value}`;
}

function frequencyOptionsFor(meta) {
    return Array.isArray(meta?.frequency?.options) && meta.frequency.options.length
        ? meta.frequency.options
        : [{value: 1, label: "Uma vez"}, {value: 2, label: "Diariamente"}, {value: 3, label: "Personalizado"}];
}

function boolValue(value, fallback = false) {
    if (typeof value === "boolean") return value;
    if (typeof value === "number") return value !== 0;
    const normalized = String(value ?? "").trim().toLowerCase();
    if (["1", "true", "yes", "on"].includes(normalized)) return true;
    if (["0", "false", "no", "off"].includes(normalized)) return false;
    return fallback;
}
