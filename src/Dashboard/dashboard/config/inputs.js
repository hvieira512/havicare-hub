import {esc, fieldLabel} from "../format.js";
import {renderPhoneControl} from "../phone.js";
import {protocolPhonebookConstraints} from "./protocol-catalog.js";
import {formatFourPTouchAlarmTime, normalizeAlarmClockRecurrenceKind} from "./alarm-fields.js";
import {
    WONLEX_MEDICATION_PERIODS,
    boolValue,
    defaultAlarmClockItem,
    defaultWonlexMedicationPlan,
    formatReminderTime,
    isFourPTouchAlarmDaySelected,
    normalizeAlarmClockDaySelection,
    normalizeAlarmClockItems,
    normalizeFourPTouchAlarmDays,
    normalizeFourPTouchAlarms,
    normalizeWonlexMedicationPlan,
    normalizeWonlexMedicationPlans,
} from "./normalizers.js";

/**
 * The HTML for every configuration input type.
 *
 * Renderers only: each one turns a desired value into markup that the matching
 * reader in readers.js can turn back into a payload. The pairing between the
 * two is what tests/Frontend/config-payload-roundtrip.test.js checks.
 */

let uidCounter = 0;

const languageTimezonePresetOptions = [
    { language: 0, timeZone: "0", label: "English (UTC+0)" },
    { language: 1, timeZone: "8", label: "简体中文 (UTC+8)" },
    { language: 3, timeZone: "1", label: "Português (UTC+1)" },
    { language: 4, timeZone: "1", label: "Español (UTC+1)" },
    { language: 5, timeZone: "1", label: "Deutsch (UTC+1)" },
    { language: 10, timeZone: "1", label: "Français (UTC+1)" },
];

export function makeCallInput(entry, desired) {
    return `
        <div>
            <label class="form-label form-label-sm">Número de telefone</label>
            <div class="d-flex gap-2">
                <div class="flex-grow-1">
                    ${renderPhoneControl({
                        value: String(desired.phone || ""),
                        configField: "phone",
                        placeholder: "+351912345678",
                    })}
                </div>
            </div>
            <div class="form-text">Envia um comando para o relógio fazer uma chamada para o número indicado.</div>
        </div>`;
}

export function resetActionInput(_entry, _desired) {
    return `
        <div>
            <div class="alert alert-warning mb-3 py-2 px-3 small">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                Esta ação é enviada imediatamente para o dispositivo e não pode ser desfeita.
            </div>
        </div>`;
}

export function requestActionInput(entry) {
    return `
        <div>
            <div class="alert alert-info mb-3 py-2 px-3 small">
                <i class="fa-solid fa-circle-info me-2"></i>
                ${esc(entry.label || "Ação")} é enviada sem parâmetros adicionais.
            </div>
        </div>`;
}

export function toggleInput(entry, desired, protocol = "") {
    const nativeField = entry.fields?.[0] || "enabled";
    const field =
        protocol === "wonlex-json" && nativeField === "switchState"
            ? "enabled"
            : nativeField;
    const checked = boolValue(desired[field] ?? desired[nativeField], true);
    return `
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" data-config-field="${esc(field)}" ${checked ? "checked" : ""}>
            <label class="form-check-label" data-switch-label>${checked ? "Ligado" : "Desligado"}</label>
        </div>`;
}

export function soundProfileInput(desired) {
    const current = parseInt(String(desired.mode ?? 1), 10) || 1;
    const options = [
        {
            value: 1,
            label: "Vibração e toque",
            icon: "fa-volume-high",
            className: "btn-outline-primary",
        },
        {
            value: 2,
            label: "Só toque",
            icon: "fa-bell",
            className: "btn-outline-secondary",
        },
        {
            value: 3,
            label: "Só vibração",
            icon: "fa-mobile-screen-button",
            className: "btn-outline-warning",
        },
        {
            value: 4,
            label: "Silêncio",
            icon: "fa-volume-xmark",
            className: "btn-outline-danger",
        },
    ];

    return `
        <div class="vstack gap-2">
            <div class="small text-secondary">Escolha o perfil de som do dispositivo.</div>
            <div class="row row-cols-2 g-2" role="radiogroup" aria-label="Perfil de som">
                ${options
                    .map(
                        (option) => `
                    <div class="col">
                        <input
                            class="btn-check"
                            type="radio"
                            name="soundProfile"
                            id="soundProfile${option.value}"
                            data-config-field="mode"
                            value="${option.value}"
                            ${option.value === current ? "checked" : ""}>
                        <label class="btn ${option.className} w-100 h-100 text-start d-flex align-items-center gap-2 py-3 px-3" for="soundProfile${option.value}">
                            <i class="fa-solid ${option.icon}"></i>
                            <span class="small fw-semibold">${esc(option.label)}</span>
                        </label>
                    </div>
                `,
                    )
                    .join("")}
            </div>
        </div>`;
}

export function fallSensitivityInput(desired) {
    const current = parseInt(String(desired.sensitivity ?? 2), 10) || 2;
    const options = [
        {
            value: 1,
            label: "Baixa",
            icon: "fa-feather-pointed",
            className: "btn-outline-success",
        },
        {
            value: 2,
            label: "Normal",
            icon: "fa-shield-heart",
            className: "btn-outline-warning",
        },
        {
            value: 3,
            label: "Alta",
            icon: "fa-triangle-exclamation",
            className: "btn-outline-danger",
        },
    ];

    return `
        <div>
            <label class="form-label form-label-sm">Sensibilidade</label>
            <input type="hidden" data-config-field="sensitivity" value="${esc(String(current))}">
            <div class="btn-group w-100" role="group" aria-label="Sensibilidade de queda" data-config-choice-group="sensitivity">
                ${options
                    .map(
                        (option) => `
                    <button
                        type="button"
                        class="btn ${option.className} ${option.value === current ? "active" : ""}"
                        data-action="selectConfigChoice"
                        data-config-field="sensitivity"
                        data-config-value="${option.value}"
                        aria-pressed="${option.value === current ? "true" : "false"}">
                        <i class="fa-solid ${option.icon} me-2"></i>${option.label}
                    </button>
                `,
                    )
                    .join("")}
            </div>
        </div>`;
}

export function numberInput(entry, desired) {
    const field = entry.fields?.[0] || "value";
    const isWonlexMeasurementInterval =
        entry.command === "deviceMeasuringFrequency" && field === "interval";
    const value = desired[field] ?? (isWonlexMeasurementInterval ? 60 : 0);
    return `
        <div>
            <label class="form-label form-label-sm">${esc(fieldLabel(field))}</label>
            <input class="form-control" type="number" min="0" step="1" data-config-field="${esc(field)}" value="${esc(String(value))}">
            ${isWonlexMeasurementInterval ? '<div class="form-text">Periodicidade de envio desta medição, em minutos. Use 0 para desativar.</div>' : ""}
        </div>`;
}

export function phoneInput(entry, desired) {
    const field = entry.fields?.[0] || "phone";
    return `
        <div>
            <label class="form-label form-label-sm">${esc(fieldLabel(field))}</label>
            ${renderPhoneControl({
                value: String(desired[field] || ""),
                configField: field,
                placeholder: entry.label || fieldLabel(field),
            })}
        </div>`;
}

export function textInput(entry, desired) {
    const field = entry.fields?.[0] || "value";
    const value = desired[field] ?? "";
    return `
        <div>
            <label class="form-label form-label-sm">${esc(fieldLabel(field))}</label>
            <input class="form-control" type="text" data-config-field="${esc(field)}" value="${esc(String(value))}">
        </div>`;
}

export function pushMessageInput(_entry, desired) {
    return `
        <div>
            <label class="form-label form-label-sm">Mensagem</label>
            <input class="form-control" type="text" data-config-field="message" value="${esc(String(desired.message ?? ""))}" placeholder="Mensagem a mostrar no relógio">
            <div class="form-text">Envia uma mensagem imediata para o relógio. Não fica guardada como configuração desejada.</div>
        </div>`;
}

export function intervalToggleInput(entry, desired) {
    const enabled = boolValue(desired.enabled, true);
    return `
        <div class="row g-3">
            <div class="col-md-4">
                <div class="form-check form-switch mt-4">
                    <input class="form-check-input" type="checkbox" role="switch" data-config-field="enabled" ${enabled ? "checked" : ""}>
                    <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
                </div>
            </div>
            <div class="col-md-8">
                <label class="form-label form-label-sm">Intervalo (minutos)</label>
                <input class="form-control" type="number" min="0" step="1" data-config-field="intervalMinutes" value="${esc(String(desired.intervalMinutes ?? 60))}">
            </div>
        </div>`;
}

export function intervalHoursToggleInput(desired) {
    const enabled = boolValue(desired.enabled, true);
    return `
        <div class="row g-3">
            <div class="col-md-4">
                <div class="form-check form-switch mt-4">
                    <input class="form-check-input" type="checkbox" role="switch" data-config-field="enabled" ${enabled ? "checked" : ""}>
                    <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
                </div>
            </div>
            <div class="col-md-8">
                <label class="form-label form-label-sm">Intervalo (horas)</label>
                <input class="form-control" type="number" min="1" max="12" step="1" data-config-field="intervalHours" value="${esc(String(desired.intervalHours ?? 2))}">
            </div>
        </div>`;
}

export function workingModeInput(desired) {
    const mode = parseInt(String(desired.mode ?? 1), 10) || 1;
    const intervalSeconds = desired.intervalSeconds ?? 60;
    const gpsEnabled = boolValue(desired.gpsEnabled, true);
    const options = [
        {
            value: 1,
            title: "Normal",
            description: "Envia localização a cada 15 minutos com Wi-Fi e LBS.",
            icon: "fa-clock",
            className: "btn-outline-primary",
        },
        {
            value: 2,
            title: "Poupança",
            description: "Envia localização a cada 60 minutos com Wi-Fi e LBS.",
            icon: "fa-battery-half",
            className: "btn-outline-success",
        },
        {
            value: 3,
            title: "Emergência",
            description:
                "Envia localização a cada 1 minuto com GPS, Wi-Fi e LBS.",
            icon: "fa-bolt",
            className: "btn-outline-danger",
        },
        {
            value: 8,
            title: "Personalizado",
            description:
                "Permite definir intervalo em segundos e ligar ou desligar GPS.",
            icon: "fa-sliders",
            className: "btn-outline-dark",
        },
    ];

    return `
        <div class="vstack gap-3" data-working-mode-root>
            <div>
                <label class="form-label form-label-sm">Modo</label>
                <div class="row g-2" data-working-mode-select>
                    ${options
                        .map(
                            (option) => `
                        <div class="col-12 col-md-6">
                            <input
                                class="btn-check"
                                type="radio"
                                name="workingMode"
                                id="workingMode${option.value}"
                                data-config-field="mode"
                                value="${option.value}"
                                ${option.value === mode ? "checked" : ""}>
                            <label class="btn ${option.className} w-100 h-100 text-start d-flex gap-3 align-items-start p-3" for="workingMode${option.value}">
                                <i class="fa-solid ${option.icon} mt-1"></i>
                                <span>
                                    <span class="d-block fw-semibold">${option.title}</span>
                                    <span class="d-block small">${option.description}</span>
                                </span>
                            </label>
                        </div>
                    `,
                        )
                        .join("")}
                </div>
            </div>
            <div class="${mode === 8 ? "" : "d-none"}" data-working-mode-extra>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label form-label-sm">Intervalo de envio (segundos)</label>
                        <input class="form-control" type="number" min="30" step="1" data-config-field="intervalSeconds" value="${esc(String(intervalSeconds))}">
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" role="switch" data-config-field="gpsEnabled" ${gpsEnabled ? "checked" : ""}>
                            <label class="form-check-label">GPS ativo</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
}

export function bloodPressureInput(desired) {
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

export function wonlexBloodPressureWarningInput(desired) {
    const enabled = boolValue(desired.enabled ?? desired.switchState, true);
    return `
        <div class="vstack gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="enabled" ${enabled ? "checked" : ""}>
                <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label form-label-sm">Sistólica máxima</label>
                    <input class="form-control" type="number" min="0" step="1" data-config-field="hpWarn" value="${esc(String(desired.hpWarn ?? 135))}">
                </div>
                <div class="col-md-6">
                    <label class="form-label form-label-sm">Diastólica máxima</label>
                    <input class="form-control" type="number" min="0" step="1" data-config-field="LPWarn" value="${esc(String(desired.LPWarn ?? 90))}">
                </div>
            </div>
        </div>`;
}

export function languageTimezoneInput(desired) {
    const preset = languageTimezonePresetOptions.find(
        (option) =>
            String(desired.language ?? 3) === String(option.language) &&
            String(desired.timeZone ?? "0") === String(option.timeZone),
    ) || languageTimezonePresetOptions[0];

    return `
        <div class="vstack gap-2">
            <label class="form-label form-label-sm">Idioma e fuso horário</label>
            <select class="form-select" data-config-field="preset">
                ${languageTimezonePresetOptions
                    .map(
                        (option) => `
                        <option value="${option.language}|${esc(String(option.timeZone))}" ${
                            option.language === preset.language &&
                            String(option.timeZone) === String(preset.timeZone)
                                ? "selected"
                                : ""
                        }>${esc(option.label)}</option>
                    `,
                    )
                    .join("")}
            </select>
            <div class="form-text">Escolha a combinação suportada pelo dispositivo.</div>
        </div>`;
}

export function dualToggleInput(desired) {
    const enabled = boolValue(desired.enabled, true);
    const callCenterOnFall = boolValue(desired.callCenterOnFall, false);
    return `
        <div class="vstack gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="enabled" ${enabled ? "checked" : ""}>
                <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="callCenterOnFall" ${callCenterOnFall ? "checked" : ""}>
                <label class="form-check-label" data-switch-label data-switch-on="Liga para o centro" data-switch-off="Não liga para o centro">${callCenterOnFall ? "Liga para o centro" : "Não liga para o centro"}</label>
            </div>
        </div>`;
}

export function fallSensitivityLevelsInput(desired) {
    const sensitivityLevel =
        parseInt(String(desired.sensitivity ?? 5), 10) || 5;
    const parsedTotalLevels = parseInt(String(desired.levels ?? ""), 10);
    const totalLevels = [6, 8].includes(parsedTotalLevels)
        ? parsedTotalLevels
        : 8;

    const levels = [
        { label: "Máxima", icon: "fa-bolt", btnClass: "btn-outline-danger" },
        { label: "Muito Alta", icon: "fa-circle-exclamation", btnClass: "btn-outline-danger" },
        { label: "Alta", icon: "fa-triangle-exclamation", btnClass: "btn-outline-warning" },
        { label: "Moderada", icon: "fa-equals", btnClass: "btn-outline-warning" },
        { label: "Baixa", icon: "fa-arrow-down", btnClass: "btn-outline-primary" },
        { label: "Muito Baixa", icon: "fa-angles-down", btnClass: "btn-outline-primary" },
        { label: "Quase Mínima", icon: "fa-feather", btnClass: "btn-outline-secondary" },
        { label: "Mínima", icon: "fa-snowflake", btnClass: "btn-outline-secondary" },
    ];

    return `
        <div class="row g-3">
            <div class="col-12 col-md-9">
                <label class="form-label form-label-sm">Nível de sensibilidade</label>
                <input type="hidden" data-config-field="sensitivity" value="${esc(String(sensitivityLevel))}">
                <div class="d-flex flex-wrap gap-1 w-100 sens-level-group" role="group" aria-label="Nível de sensibilidade" data-config-choice-group="sensitivity">
                    ${levels
                        .map(
                            ({ label, icon, btnClass }, i) => {
                                const level = i + 1;
                                return `
                        <button
                            type="button"
                            class="btn ${btnClass} sens-level-btn d-flex flex-column align-items-center justify-content-center ${level === sensitivityLevel ? "active" : ""} ${level > totalLevels ? "d-none" : ""}"
                            style="flex: 1 0 0; min-width: 4rem; min-height: 4rem"
                            data-action="selectConfigChoice"
                            data-config-field="sensitivity"
                            data-config-value="${level}"
                            aria-pressed="${level === sensitivityLevel ? "true" : "false"}"
                            ${level > totalLevels ? "disabled" : ""}>
                            <div class="d-flex align-items-center gap-1 fw-medium">
                                <i class="fa-solid ${icon}"></i>
                                <span>${level}</span>
                            </div>
                            <div class="small opacity-75">${label}</div>
                        </button>
                    `;
                            },
                        )
                        .join("")}
                </div>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label form-label-sm">Escala do firmware</label>
                <select class="form-select" data-config-field="levels" data-action="fallTotalLevels" required>
                    <option value="" ${totalLevels === null ? "selected" : ""} disabled>Selecione…</option>
                    <option value="6" ${totalLevels === 6 ? "selected" : ""}>6 níveis</option>
                    <option value="8" ${totalLevels === 8 ? "selected" : ""}>8 níveis</option>
                </select>
                <div class="form-text">Escolha a escala indicada para o firmware deste dispositivo.</div>
            </div>
        </div>`;
}

export function timeRangesInput(entry, desired) {
    const limit = Math.max(1, parseInt(String(entry.limit ?? 3), 10) || 3);
    const ranges = Array.isArray(desired.ranges) ? desired.ranges : [];
    const values = Array.from(
        { length: limit },
        (_, index) => ranges[index] ?? "",
    );
    return `
        <div class="vstack gap-2">
            <div class="small text-secondary">Formato HH:MM-HH:MM. Envie pelo menos um intervalo.</div>
            ${values
                .map(
                    (value, index) => `
                <div>
                    <label class="form-label form-label-sm">Intervalo ${index + 1}</label>
                    <input class="form-control" type="text" data-config-field="ranges" value="${esc(String(value))}" placeholder="08:10-09:30">
                </div>
            `,
                )
                .join("")}
        </div>`;
}

export function timeRangeInput(desired) {
    return `
        <div>
            <label class="form-label form-label-sm">Intervalo</label>
            <input class="form-control" type="text" data-config-field="range" value="${esc(String(desired.range ?? "21:10-07:30"))}" placeholder="21:10-07:30">
        </div>`;
}

export function wonlexSleepSettingsInput(desired) {
    const enabled = boolValue(desired.enabled ?? desired.switchState, true);
    return `
        <div class="vstack gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="enabled" ${enabled ? "checked" : ""}>
                <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Início (HHmmss)</label>
                    <input class="form-control" type="text" data-config-field="sleepStartTime" value="${esc(String(desired.sleepStartTime ?? "220000"))}" placeholder="220000">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Fim (HHmmss)</label>
                    <input class="form-control" type="text" data-config-field="sleepEndTime" value="${esc(String(desired.sleepEndTime ?? "100000"))}" placeholder="100000">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Meta (minutos)</label>
                    <input class="form-control" type="number" min="0" step="1" data-config-field="sleepTarget" value="${esc(String(desired.sleepTarget ?? 480))}">
                </div>
            </div>
        </div>`;
}

export function wonlexReminderThresholdInput(entry, desired) {
    const enabled = boolValue(desired.enabled ?? desired.switchState, true);
    const valueField = (entry.fields || []).includes("RemindValue")
        ? "RemindValue"
        : "reminderValue";
    const value =
        desired[valueField] ??
        desired.reminderValue ??
        desired.RemindValue ??
        90;
    return `
        <div class="vstack gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="enabled" ${enabled ? "checked" : ""}>
                <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
            </div>
            <div>
                <label class="form-label form-label-sm">${esc(fieldLabel(valueField))}</label>
                <input class="form-control" type="number" min="0" step="1" data-config-field="${esc(valueField)}" value="${esc(String(value))}">
            </div>
        </div>`;
}

export function wonlexHeartRateRangeInput(desired) {
    const enabled = boolValue(desired.enabled ?? desired.switchState, true);
    const exerciseEnabled = boolValue(
        desired.exerciseEnabled ?? desired.exerciseSwitchState,
        true,
    );
    return `
        <div class="vstack gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="enabled" ${enabled ? "checked" : ""}>
                <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label form-label-sm">Limite principal</label>
                    <input class="form-control" type="number" min="0" step="1" data-config-field="remindValue" value="${esc(String(desired.remindValue ?? 120))}">
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" role="switch" data-config-field="exerciseEnabled" ${exerciseEnabled ? "checked" : ""}>
                        <label class="form-check-label">Usar limites de exercício</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Mínimo exercício</label>
                    <input class="form-control" type="number" min="0" step="1" data-config-field="exerciseHRMin" value="${esc(String(desired.exerciseHRMin ?? 100))}">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Máximo exercício</label>
                    <input class="form-control" type="number" min="0" step="1" data-config-field="exerciseHRMax" value="${esc(String(desired.exerciseHRMax ?? 140))}">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Alerta em exercício</label>
                    <input class="form-control" type="number" min="0" step="1" data-config-field="exerciseRemindValue" value="${esc(String(desired.exerciseRemindValue ?? 140))}">
                </div>
            </div>
        </div>`;
}

export function listInput(entry, desired, field, label) {
    const limit = Math.max(1, parseInt(String(entry.limit ?? 3), 10) || 3);
    const values = Array.isArray(desired[field]) ? desired[field] : [];
    const rows = Array.from(
        { length: limit },
        (_, index) => values[index] ?? "",
    );
    return `
        <div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label form-label-sm mb-0">${esc(label)}</label>
                <span class="small text-secondary">${limit} itens</span>
            </div>
            <div class="vstack gap-2">
                ${rows
                    .map(
                        (value, index) => `
                    ${renderPhoneControl({
                        value,
                        configField: field,
                        placeholder: `${label} ${index + 1}`,
                    })}
                `,
                    )
                    .join("")}
            </div>
        </div>`;
}

export function sosContactsInput(entry, desired, meta = {}) {
    if (meta.sourceCapability === "phonebook") {
        const selected = new Set(Array.isArray(desired) ? desired.map(String) : []);
        const contacts = Array.isArray(meta.phonebookContacts)
            ? meta.phonebookContacts.filter((contact) => contact?.phone)
            : [];
        if (contacts.length === 0) {
            return `
                <div class="alert alert-warning small mb-0">
                    Adicione primeiro os contactos à Lista telefónica. Os contactos SOS do Wonlex são selecionados dessa lista.
                </div>`;
        }

        return `
            <div>
                <div class="form-label form-label-sm">Selecionar da lista telefónica</div>
                <div class="vstack gap-2">
                    ${contacts.map((contact, index) => {
                        const phone = String(contact.phone || "");
                        const name = String(contact.name || "").trim();
                        const id = `sos-phonebook-${++uidCounter}-${index}`;
                        return `
                            <label class="border rounded bg-body p-3 d-flex align-items-center gap-3" for="${esc(id)}">
                                <input
                                    id="${esc(id)}"
                                    class="form-check-input mt-0"
                                    type="checkbox"
                                    data-sos-contact-phone
                                    value="${esc(phone)}"
                                    ${selected.has(phone) ? "checked" : ""}>
                                <span>
                                    <span class="d-block fw-semibold">${esc(name || phone)}</span>
                                    ${name ? `<span class="small text-secondary">${esc(phone)}</span>` : ""}
                                </span>
                            </label>`;
                    }).join("")}
                </div>
                <div class="form-text">Apenas contactos existentes na lista telefónica podem ser usados como SOS.</div>
            </div>`;
    }

    const phoneMaxLength = Math.max(0, parseInt(String(meta.phone?.maxLength ?? 0), 10) || 0);
    return phoneRepeaterInput(entry, desired, {
        kind: "sos_contacts",
        limit: Math.max(1, parseInt(String(entry.limit ?? 3), 10) || 3),
        label: "Contactos SOS",
        addAction: "addSosContactRow",
        removeAction: "removeSosContactRow",
        emptyLabel: "Adicionar contacto SOS",
        placeholderPrefix: "SOS",
        helpText: "Até 3 números. A ordem define a posição nos comandos SOS do dispositivo.",
        phoneMaxLength,
    });
}

export function callWhitelistInput(entry, desired, meta = {}) {
    if ((meta.protocol || "") === "vivistar-iw") {
        return contactsInput(entry, desired, meta);
    }

    return phoneRepeaterInput(entry, desired, {
        kind: "call_whitelist",
        limit: Math.max(1, parseInt(String(entry.limit ?? 10), 10) || 10),
        label: "Lista branca",
        addAction: "addWhitelistRow",
        removeAction: "removeWhitelistRow",
        emptyLabel: "Adicionar número",
        placeholderPrefix: "Número",
        helpText: "Até 10 números permitidos.",
    });
}

export function contactsInput(entry, desired, meta = {}) {
    const limit = Math.max(1, parseInt(String(meta.limit ?? entry.limit ?? 10), 10) || 10);
    const contacts = Array.isArray(desired)
        ? desired
        : Array.isArray(desired.contacts)
            ? desired.contacts
            : [];
    const rows = contacts.length ? contacts.slice(0, limit) : [{}];
    const phonebookConstraints = protocolPhonebookConstraints(meta.protocol || "");
    const isPhonebookLike = String(entry.key || "") === "phonebook" || String(entry.key || "") === "call_whitelist";
    const nameMaxLengthValue = Math.max(
        0,
        parseInt(String(meta.name?.maxLength ?? phonebookConstraints.name?.maxLength ?? 0), 10) || 0,
    );
    const phoneMaxLengthValue = Math.max(
        0,
        parseInt(String(meta.phone?.maxLength ?? phonebookConstraints.phone?.maxLength ?? 0), 10) || 0,
    );
    const nameMaxLength = nameMaxLengthValue > 0 ? ` maxlength="${esc(String(nameMaxLengthValue))}"` : "";
    return `
        <div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label form-label-sm mb-0">Contactos</label>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addContactRow">Adicionar</button>
            </div>
            <div class="small text-secondary mb-2">${limit} contactos máximos</div>
            <div class="vstack gap-2" data-repeat-limit="${limit}"${isPhonebookLike && nameMaxLengthValue > 0 ? ` data-phonebook-name-max-length="${esc(String(nameMaxLengthValue))}"` : ""}${isPhonebookLike && phoneMaxLengthValue > 0 ? ` data-phonebook-phone-max-length="${esc(String(phoneMaxLengthValue))}"` : ""}>
                ${rows
                    .map(
                        (contact, index) => `
                    <div class="row g-2 align-items-end" data-repeat-row="contacts">
                        <div class="col-md-6">
                            <input class="form-control" type="text" placeholder="Nome ${index + 1}" data-repeat-field="name"${nameMaxLength} value="${esc(String(contact.name || ""))}">
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2">
                                <div class="flex-grow-1">
                                    ${renderPhoneControl({
                                        value: String(contact.phone || ""),
                                        repeatField: "phone",
                                        placeholder: `Telefone ${index + 1}`,
                                        maxLength: phoneMaxLengthValue,
                                    })}
                                </div>
                                <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeContactRow">-</button>
                            </div>
                        </div>
                    </div>
                `,
                    )
                    .join("")}
            </div>
        </div>`;
}

export function phoneRepeaterInput(entry, desired, options) {
    const limit = Math.max(1, parseInt(String(options.limit ?? entry.limit ?? 3), 10) || 3);
    const values = Array.isArray(desired)
        ? desired
        : Array.isArray(desired.numbers)
            ? desired.numbers
            : [];
    const rows = values.length ? values.slice(0, limit) : [""];
    const kind = String(options.kind || "numbers");
    const addAction = String(options.addAction || "addPhoneRow");
    const removeAction = String(options.removeAction || "removePhoneRow");
    const label = String(options.label || entry.label || "Lista");
    const helpText = String(options.helpText || "");
    const emptyLabel = String(options.emptyLabel || "Adicionar");
    const placeholderPrefix = String(options.placeholderPrefix || label);
    const phoneMaxLength = Math.max(0, parseInt(String(options.phoneMaxLength ?? 0), 10) || 0);

    return `
        <div class="vstack gap-3">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <label class="form-label form-label-sm mb-0">${esc(label)}</label>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="${esc(addAction)}">${esc(emptyLabel)}</button>
            </div>
            ${helpText !== "" ? `<div class="small text-secondary">${esc(helpText)}</div>` : ""}
            <div class="vstack gap-2" data-repeat-limit="${limit}" data-repeat-kind="${esc(kind)}">
                ${rows
                    .map(
                        (value, index) => `
                    <div class="row g-2 align-items-end" data-repeat-row="${esc(kind)}">
                        <div class="col">
                            ${renderPhoneControl({
                                value: String(value || ""),
                                configField: "numbers",
                                placeholder: `${placeholderPrefix} ${index + 1}`,
                                maxLength: phoneMaxLength,
                            })}
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-outline-danger btn-sm" data-action="${esc(removeAction)}">-</button>
                        </div>
                    </div>
                `,
                    )
                    .join("")}
            </div>
        </div>`;
}

export function alarmClockInput(desired, meta = {}) {
    const items = normalizeAlarmClockItems(desired);
    const limit = Math.max(1, parseInt(String(meta.limit ?? 3), 10) || 3);
    const typeOptions = Array.isArray(meta.type?.options) ? meta.type.options : [];
    const recurrenceOptions = Array.isArray(meta.recurrence?.options) && meta.recurrence.options.length
        ? meta.recurrence.options
        : [
              { value: "once", label: "Uma vez" },
              { value: "daily", label: "Todos os dias" },
              { value: "custom", label: "Personalizado" },
          ];
    const wonlexFields = {
        label: meta.label?.supported === true,
        url: meta.url?.supported === true,
    };
    if (items.length === 0) {
        items.push(defaultAlarmClockItem(
            typeOptions.length > 0,
            recurrenceOptions[0]?.value ?? "once",
        ));
    }

    return `
        <div class="vstack gap-3">
            <div class="small text-secondary">Até ${esc(String(limit))} alarmes. A recorrência personalizada usa dias de Segunda a Domingo.</div>
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addAlarmClockRow">Adicionar item</button>
            </div>
            <div class="vstack gap-2" data-alarm-clock-list>
                ${items.slice(0, limit).map((item) => alarmClockRow(item, typeOptions, recurrenceOptions, wonlexFields)).join("")}
            </div>
        </div>`;
}

export function alarmsInput(desired, meta = {}) {
    const alarms = normalizeFourPTouchAlarms(desired);
    const limit = Math.max(1, parseInt(String(meta.limit ?? 3), 10) || 3);
    if (alarms.length === 0) {
        alarms.push({
            time: "",
            enabled: true,
            mode: 1,
            custom: "",
        });
    }

    return `
        <div class="vstack gap-3">
            <div class="small text-secondary">
                Até ${esc(String(limit))} alarmes. A recorrência personalizada usa uma máscara de 7 dias, de Segunda a Domingo.
            </div>
            <div class="vstack gap-2">
                ${alarms
                    .slice(0, limit)
                    .map((alarm, index) => fourPTouchAlarmRow(alarm, index))
                    .join("")}
            </div>
        </div>`;
}

export function wonlexMedicationPlansInput(desired) {
    const plans = normalizeWonlexMedicationPlans(desired);
    if (plans.length === 0) {
        plans.push(defaultWonlexMedicationPlan());
    }

    return `
        <div class="vstack gap-3">
            <div class="small text-secondary">
                Cada plano é enviado separadamente ao relógio. Selecione pelo menos um período e indique a respetiva hora.
            </div>
            <div class="small"><span class="text-danger" aria-hidden="true">*</span> Campo obrigatório</div>
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addWonlexMedicationPlan">
                    <i class="fa-solid fa-plus me-2"></i>Adicionar medicamento
                </button>
            </div>
            <div class="vstack gap-3" data-wonlex-medication-list>
                ${plans.map((plan, index) => wonlexMedicationPlanRow(plan, index)).join("")}
            </div>
        </div>`;
}

export function wonlexMedicationPlanRow(plan = {}, index = 0) {
    const normalized = normalizeWonlexMedicationPlan(plan);
    const rowId = nextUid("wonlex-medication");

    return `
        <div class="border rounded p-3 bg-body" data-repeat-row="wonlexMedicationPlan">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <div class="fw-semibold">Medicamento <span data-medication-plan-number>${index + 1}</span></div>
                <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeWonlexMedicationPlan" title="Remover medicamento" aria-label="Remover medicamento">
                    <i class="fa-solid fa-trash-can"></i>
                </button>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Tipo <span class="text-danger" aria-hidden="true">*</span></label>
                    <select class="form-select" data-medication-field="drugType" required>
                        ${[
                            [0, "Hipertensão"],
                            [1, "Diabetes"],
                            [2, "Colesterol / lípidos"],
                            [3, "Ácido úrico elevado"],
                        ].map(([value, label]) => `
                            <option value="${value}" ${normalized.drugType === value ? "selected" : ""}>${esc(label)}</option>
                        `).join("")}
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label form-label-sm">Nome do medicamento <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" type="text" data-medication-field="drugName" value="${esc(normalized.drugName)}" placeholder="Ex.: Losartan" required>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label form-label-sm">Dose</label>
                    <input class="form-control" type="number" min="0" step="0.1" data-medication-field="drugDose" value="${esc(String(normalized.drugDose))}">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label form-label-sm">Unidade</label>
                    <select class="form-select" data-medication-field="drugUnit">
                        ${[
                            ["0", "Comprimido / unidade"],
                            ["1", "Ampola"],
                            ["2", "ml"],
                            ["3", "mg"],
                            ["4", "UI"],
                            ["5", "Outra"],
                        ].map(([value, label]) => `
                            <option value="${value}" ${normalized.drugUnit === value ? "selected" : ""}>${esc(label)}</option>
                        `).join("")}
                    </select>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label form-label-sm">Data inicial <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" type="date" data-medication-field="drugStartTime" value="${esc(normalized.drugStartTime)}" required>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label form-label-sm">Data final <span class="text-danger" aria-hidden="true">*</span></label>
                    <input class="form-control" type="date" data-medication-field="drugEndTime" value="${esc(normalized.drugEndTime)}" required>
                </div>
                <div class="col-sm-6 col-md-4">
                    <label class="form-label form-label-sm">Intervalo <span class="text-danger" aria-hidden="true">*</span></label>
                    <div class="input-group">
                        <input class="form-control" type="number" min="0" step="0.5" data-medication-field="drugInterval" value="${esc(String(normalized.drugInterval))}" required>
                        <span class="input-group-text">dias</span>
                    </div>
                </div>
                <div class="col-sm-6 col-md-8">
                    <label class="form-label form-label-sm d-block">Tomar <span class="text-danger" aria-hidden="true">*</span></label>
                    <div class="btn-group" role="group" aria-label="Relação com a refeição">
                        ${[
                            [0, "Antes da refeição"],
                            [1, "Depois da refeição"],
                        ].map(([value, label]) => {
                            const id = `${rowId}-meal-${value}`;
                            return `
                                <input class="btn-check" type="radio" name="${rowId}-meal" id="${id}" value="${value}" data-medication-field="mealTiming" ${normalized.mealTiming === value ? "checked" : ""}>
                                <label class="btn btn-outline-secondary" for="${id}">${esc(label)}</label>
                            `;
                        }).join("")}
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <label class="form-label form-label-sm">Períodos e horários <span class="text-danger" aria-hidden="true">*</span></label>
                <div class="row g-2">
                    ${WONLEX_MEDICATION_PERIODS.map((period) => {
                        const selected = normalized.periods.includes(period.index);
                        const inputId = `${rowId}-period-${period.index}`;
                        return `
                            <div class="col-sm-6 col-xl-3">
                                <div class="border rounded p-2 h-100">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="${inputId}" value="${period.index}" data-medication-period ${selected ? "checked" : ""}>
                                        <label class="form-check-label" for="${inputId}">${esc(period.label)}</label>
                                    </div>
                                    <input class="form-control form-control-sm" type="time" data-medication-period-time="${period.index}" value="${esc(normalized.alarmClock[period.key] || period.defaultTime)}" ${selected ? "" : "disabled"}>
                                </div>
                            </div>
                        `;
                    }).join("")}
                </div>
            </div>
        </div>`;
}

export function fourPTouchAlarmRow(alarm, index) {
    const mode = parseInt(String(alarm.mode ?? 1), 10) || 1;
    const customVisible = mode === 3;
    const rowId = nextUid("fourptouch-alarm");
    const dayButtons = [
        { value: "0", label: "Dom" },
        { value: "1", label: "Seg" },
        { value: "2", label: "Ter" },
        { value: "3", label: "Qua" },
        { value: "4", label: "Qui" },
        { value: "5", label: "Sex" },
        { value: "6", label: "Sáb" },
    ];

    const modeOptions = [
        { value: 1, label: "Uma vez" },
        { value: 2, label: "Todos os dias" },
        { value: 3, label: "Personalizado" },
    ];

    const customDays = normalizeFourPTouchAlarmDays(alarm.custom || "");

    return `
        <div class="border rounded p-3 bg-body" data-fourptouch-alarm-row="${index}">
            <div class="row g-3 align-items-end">
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label form-label-sm">Hora</label>
                    <input class="form-control" type="text" inputmode="numeric" maxlength="5" pattern="[0-9]{2}:[0-9]{2}" placeholder="HH:MM" data-time-format="24h" data-fourptouch-field="time" value="${esc(formatFourPTouchAlarmTime(alarm.time))}">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" role="switch" data-fourptouch-field="enabled" ${boolValue(alarm.enabled, true) ? "checked" : ""}>
                        <label class="form-check-label" data-switch-label>${boolValue(alarm.enabled, true) ? "Ligado" : "Desligado"}</label>
                    </div>
                </div>
                <div class="col-12 col-lg-3">
                    <label class="form-label form-label-sm">Modo</label>
                    <select class="form-select" data-config-field="mode" data-fourptouch-field="mode">
                        ${modeOptions
                            .map(
                                (option) => `
                            <option value="${option.value}" ${option.value === mode ? "selected" : ""}>${esc(option.label)}</option>
                        `,
                            )
                            .join("")}
                    </select>
                </div>
                <div class="col-12 col-lg-5 ${customVisible ? "" : "d-none"}" data-fourptouch-custom-wrapper>
                    <label class="form-label form-label-sm d-block">Dias personalizados</label>
                    <div class="d-flex flex-wrap gap-1" role="group" aria-label="Dias personalizados">
                        ${dayButtons
                            .map(
                                (day) => `
                            <input
                                class="btn-check"
                                type="checkbox"
                                id="${rowId}-day-${day.value}"
                                data-fourptouch-day="customDays"
                                value="${day.value}"
                                ${isFourPTouchAlarmDaySelected(customDays, day.value) ? "checked" : ""}>
                            <label class="btn btn-outline-secondary btn-sm" for="${rowId}-day-${day.value}">${day.label}</label>
                        `,
                            )
                            .join("")}
                    </div>
                </div>
            </div>
        </div>`;
}

export function alarmClockRow(item = {}, typeOptions = [], recurrenceOptions = [], wonlexFields = {}) {
    const rowId = nextUid("alarm-clock");
    const recurrenceKind = normalizeAlarmClockRecurrenceKind(
        item.recurrence?.kind ?? item.kind ?? recurrenceOptions[0]?.value ?? "once",
    );
    const recurrenceValue = recurrenceKind;
    const dayMask = normalizeAlarmClockDaySelection(
        item.recurrence?.days ?? "",
    );
    const hasTypeSelector = Array.isArray(typeOptions) && typeOptions.length > 0;
    const typeValue = hasTypeSelector
        ? parseInt(String(item.type ?? typeOptions[0]?.value ?? 1), 10) || 1
        : 0;
    const customVisible = recurrenceKind === "custom";
    const recurrenceButtonOptions = Array.isArray(recurrenceOptions) && recurrenceOptions.length
        ? recurrenceOptions
        : [
              { value: "once", label: "Uma vez" },
              { value: "daily", label: "Todos os dias" },
              { value: "custom", label: "Personalizado" },
          ];
    const dayButtons = [
        { value: "1", label: "Seg" },
        { value: "2", label: "Ter" },
        { value: "3", label: "Qua" },
        { value: "4", label: "Qui" },
        { value: "5", label: "Sex" },
        { value: "6", label: "Sáb" },
        { value: "7", label: "Dom" },
    ];

    return `
        <div class="border rounded p-3 bg-body" data-repeat-row="alarm_clock">
            <div class="row g-3 align-items-end">
                ${wonlexFields.label
                    ? `
                <div class="col-12 col-lg-4">
                    <label class="form-label form-label-sm">Nome do alarme</label>
                    <input class="form-control" type="text" placeholder="Ex.: Tomar medicação" data-alarm-clock-field="label" value="${esc(String(item.label || ""))}">
                </div>`
                    : ""}
                <div class="col-sm-6 col-lg-${hasTypeSelector ? "1" : "3"}">
                    <label class="form-label form-label-sm">Hora <span class="text-danger">*</span></label>
                    <input class="form-control" type="text" inputmode="numeric" maxlength="5" pattern="[0-9]{2}:[0-9]{2}" placeholder="HH:MM" data-time-format="24h" data-alarm-clock-field="time" value="${esc(formatReminderTime(item.time))}" required>
                </div>
                <div class="col-sm-6 col-lg-${hasTypeSelector ? "1" : "3"}">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" role="switch" data-alarm-clock-field="enabled" ${boolValue(item.enabled, true) ? "checked" : ""}>
                        <label class="form-check-label" data-switch-label>${boolValue(item.enabled, true) ? "Ligado" : "Desligado"}</label>
                    </div>
                </div>
                ${hasTypeSelector
                    ? `
                <div class="col-12 col-lg-3">
                    <label class="form-label form-label-sm">Tipo</label>
                    <div class="btn-group w-100" role="group" aria-label="Tipo de alarme">
                        ${typeOptions.map((option) => {
                            const optionValue = parseInt(String(option.value), 10) || 1;
                            const inputId = `${rowId}-type-${optionValue}`;
                            return `
                            <input
                                class="btn-check"
                                type="radio"
                                name="${rowId}-type"
                                id="${inputId}"
                                value="${esc(String(optionValue))}"
                                data-alarm-clock-field="type"
                                ${optionValue === typeValue ? "checked" : ""}>
                            <label class="btn btn-outline-primary btn-sm" for="${inputId}">${esc(String(option.label || option.value))}</label>
                        `;
                        }).join("")}
                    </div>
                </div>`
                    : ""}
                <div class="col-12 col-lg-${hasTypeSelector ? "3" : "4"}">
                    <label class="form-label form-label-sm">Recorrência <span class="text-danger">*</span></label>
                    <div class="btn-group w-100" role="group" aria-label="Recorrência do alarme">
                        ${recurrenceButtonOptions
                            .map((option) => {
                                const optionValue = normalizeAlarmClockRecurrenceKind(option.value);
                                const inputId = `${rowId}-recurrence-${optionValue}`;
                                return `
                            <input
                                class="btn-check"
                                type="radio"
                                name="${rowId}-recurrence"
                                id="${inputId}"
                                value="${esc(optionValue)}"
                                data-alarm-clock-field="recurrenceKind"
                                ${optionValue === recurrenceValue ? "checked" : ""}>
                            <label class="btn btn-outline-secondary btn-sm" for="${inputId}">${esc(String(option.label))}</label>
                        `;
                            })
                            .join("")}
                    </div>
                </div>
                <div class="col-12 col-lg-3 ${customVisible ? "" : "d-none"}" data-alarm-clock-custom-wrapper>
                    <label class="form-label form-label-sm d-block">Dias personalizados <span class="text-danger">*</span></label>
                    <div class="d-flex flex-wrap gap-1" role="group" aria-label="Dias personalizados">
                        ${dayButtons
                            .map(
                                (day) => `
                            <input
                                class="btn-check"
                                type="checkbox"
                                id="${rowId}-day-${day.value}"
                                data-alarm-clock-day="customDays"
                                value="${day.value}"
                                ${dayMask.includes(day.value) ? "checked" : ""}>
                            <label class="btn btn-outline-secondary btn-sm" for="${rowId}-day-${day.value}">${day.label}</label>
                        `,
                            )
                            .join("")}
                    </div>
                </div>
                <div class="col-12 col-lg-1 d-flex justify-content-lg-end">
                    <button type="button" class="btn btn-outline-danger btn-sm mt-lg-4" data-action="removeAlarmClockRow" title="Remover" aria-label="Remover">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>
                ${wonlexFields.url
                    ? `
                <div class="col-12">
                    <label class="form-label form-label-sm">URL do áudio</label>
                    <input class="form-control" type="url" inputmode="url" placeholder="https://exemplo.pt/lembrete.mp3" data-alarm-clock-field="url" value="${esc(String(item.url || ""))}">
                    <div class="form-text">Endereço HTTP ou HTTPS opcional para o ficheiro de voz do lembrete.</div>
                </div>`
                    : ""}
            </div>
        </div>`;
}

function nextUid(prefix) {
    uidCounter += 1;
    return `${prefix}-${uidCounter}`;
}
