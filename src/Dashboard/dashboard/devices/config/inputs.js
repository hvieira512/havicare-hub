import { esc, fieldLabel } from "../../format.js";
import { field } from "../../widgets.js";
import { renderPhoneControl } from "../../phone.js";
import { protocolPhonebookConstraints } from "./protocol-catalog.js";
import { formatFourPTouchAlarmTime, normalizeAlarmClockRecurrenceKind } from "./alarm-fields.js";
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
 * O HTML de cada tipo de campo de configuração. Só desenho: cada um transforma o valor
 * pretendido em marcação que o leitor correspondente no `readers.js` volta a transformar em
 * payload, e é esse par que o `config-payload-roundtrip.test.js` verifica.
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
            <label class="form-label-sm">Número de telefone</label>
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
            <div class="alert alert-warning alert-compact mb-3">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                Esta ação é enviada imediatamente para o dispositivo e não pode ser desfeita.
            </div>
        </div>`;
}

export function requestActionInput(entry) {
    return `
        <div>
            <div class="alert alert-info alert-compact mb-3">
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
            <label class="form-label-sm">Sensibilidade</label>
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

/**
 * A sensibilidade dos alertas de um medidor de fraldas: dois inteiros e três atalhos. Os
 * números estão sempre à vista e os presets são botões que os preenchem, sem um quarto botão
 * "Personalizado" -- nenhum preset activo já diz que os valores não são de nenhum deles.
 *
 * Os presets e as gamas vêm no `_meta` da capacidade, servidos pelo hub, para não haver aqui
 * uma segunda cópia destas fronteiras.
 */
export function diaperSensitivityInput(desired, meta = {}) {
    const presets = meta.presets || {};
    const bounds = meta.bounds || {};
    const [rangeMin, rangeMax] = bounds.pollutionRange || [2, 10];
    const [valueMin, valueMax] = bounds.pollutionValue || [5, 25];
    const range = desired.pollutionRange ?? "";
    const value = desired.pollutionValue ?? "";

    const levels = [
        { profile: "low", label: "Baixa", icon: "fa-feather-pointed", className: "btn-outline-success" },
        { profile: "normal", label: "Normal", icon: "fa-shield-heart", className: "btn-outline-warning" },
        { profile: "high", label: "Alta", icon: "fa-triangle-exclamation", className: "btn-outline-danger" },
    ].filter((level) => presets[level.profile]);

    const buttons = levels
        .map((level) => {
            const preset = presets[level.profile];
            const active = Number(preset.pollutionRange) === Number(range) &&
                Number(preset.pollutionValue) === Number(value);
            return `
            <button type="button" class="btn ${level.className}${active ? " active" : ""}"
                data-action="selectConfigChoice"
                data-config-preset="${esc(JSON.stringify(preset))}"
                aria-pressed="${active ? "true" : "false"}">
                <i class="fa-solid ${esc(level.icon)} me-2"></i>${esc(level.label)}
            </button>`;
        })
        .join("");

    return `
        <div>
            ${buttons === "" ? "" : `<div class="btn-group w-100 mb-2" role="group" aria-label="Sensibilidade dos alertas" data-config-choice-group="diaperSensitivity">${buttons}</div>`}
            <div class="row g-2">
                <div class="col">
                    <label class="form-label-sm mb-1" for="diaperPollutionRange">Canais afetados</label>
                    <input type="number" class="form-control" id="diaperPollutionRange" data-config-field="pollutionRange"
                        min="${esc(String(rangeMin))}" max="${esc(String(rangeMax))}" step="1" value="${esc(String(range))}">
                    <div class="form-text">Quantos canais molhados obrigam a uma muda.</div>
                </div>
                <div class="col">
                    <label class="form-label-sm mb-1" for="diaperPollutionValue">Limiar por canal</label>
                    <input type="number" class="form-control" id="diaperPollutionValue" data-config-field="pollutionValue"
                        min="${esc(String(valueMin))}" max="${esc(String(valueMax))}" step="1" value="${esc(String(value))}">
                    <div class="form-text">A partir de quanto um canal conta como molhado.</div>
                </div>
            </div>
            <div class="form-text">O sensor apenas transmite e nada lhe é enviado. Passa a valer na leitura seguinte.</div>
        </div>`;
}

export function numberInput(entry, desired) {
    const key = entry.fields?.[0] || "value";
    const isWonlexMeasurementInterval =
        entry.command === "deviceMeasuringFrequency" && key === "interval";
    const value = desired[key] ?? (isWonlexMeasurementInterval ? 60 : 0);
    return field(
        fieldLabel(key),
        `<input class="form-control" type="number" min="0" step="1" data-config-field="${esc(key)}" value="${esc(String(value))}">`,
        {
            help: isWonlexMeasurementInterval
                ? "Periodicidade de envio desta medição, em minutos. Use 0 para desativar."
                : "",
        },
    );
}

export function phoneInput(entry, desired) {
    const key = entry.fields?.[0] || "phone";
    return field(
        fieldLabel(key),
        renderPhoneControl({
            value: String(desired[key] || ""),
            configField: key,
            placeholder: entry.label || fieldLabel(key),
        }),
    );
}

export function textInput(entry, desired) {
    const key = entry.fields?.[0] || "value";
    return field(
        fieldLabel(key),
        `<input class="form-control" type="text" data-config-field="${esc(key)}" value="${esc(String(desired[key] ?? ""))}">`,
    );
}

export function pushMessageInput(_entry, desired) {
    return field(
        "Mensagem",
        `<input class="form-control" type="text" data-config-field="message" value="${esc(String(desired.message ?? ""))}" placeholder="Mensagem a mostrar no relógio">`,
        { help: "Envia uma mensagem imediata para o relógio. Não fica guardada como configuração desejada." },
    );
}

export function intervalToggleInput(entry, desired) {
    return `
        <div class="row g-3">
            <div class="col-md-4">${enabledSwitch(desired)}</div>
            ${field(
                "Intervalo (minutos)",
                `<input class="form-control" type="number" min="0" step="1" data-config-field="intervalMinutes" value="${esc(String(desired.intervalMinutes ?? 60))}">`,
                { cls: "col-md-8" },
            )}
        </div>`;
}

export function intervalHoursToggleInput(desired) {
    return `
        <div class="row g-3">
            <div class="col-md-4">${enabledSwitch(desired)}</div>
            ${field(
                "Intervalo (horas)",
                `<input class="form-control" type="number" min="1" max="12" step="1" data-config-field="intervalHours" value="${esc(String(desired.intervalHours ?? 2))}">`,
                { cls: "col-md-8" },
            )}
        </div>`;
}

/** O interruptor de ligado ao lado de um campo com etiqueta, alinhado por baixo com ele. */
function enabledSwitch(desired) {
    const enabled = boolValue(desired.enabled, true);
    return `
        <div class="form-check form-switch mt-4">
            <input class="form-check-input" type="checkbox" role="switch" data-config-field="enabled" ${enabled ? "checked" : ""}>
            <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
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
                <label class="form-label-sm">Modo</label>
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
                    ${field(
                        "Intervalo de envio (segundos)",
                        `<input class="form-control" type="number" min="30" step="1" data-config-field="intervalSeconds" value="${esc(String(intervalSeconds))}">`,
                        { cls: "col-md-6" },
                    )}
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
            ${field(
                "Sistólica",
                `<input class="form-control" type="number" min="0" step="1" data-config-field="systolic" value="${esc(String(desired.systolic ?? 120))}">`,
                { cls: "col-md-6" },
            )}
            ${field(
                "Diastólica",
                `<input class="form-control" type="number" min="0" step="1" data-config-field="diastolic" value="${esc(String(desired.diastolic ?? 80))}">`,
                { cls: "col-md-6" },
            )}
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
                ${field(
                    "Sistólica máxima",
                    `<input class="form-control" type="number" min="0" step="1" data-config-field="hpWarn" value="${esc(String(desired.hpWarn ?? 135))}">`,
                    { cls: "col-md-6" },
                )}
                ${field(
                    "Diastólica máxima",
                    `<input class="form-control" type="number" min="0" step="1" data-config-field="LPWarn" value="${esc(String(desired.LPWarn ?? 90))}">`,
                    { cls: "col-md-6" },
                )}
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
            <label class="form-label-sm">Idioma e fuso horário</label>
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
                <label class="form-label-sm">Nível de sensibilidade</label>
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
                <label class="form-label-sm">Escala do firmware</label>
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
                    <label class="form-label-sm">Intervalo ${index + 1}</label>
                    <input class="form-control" type="text" data-config-field="ranges" value="${esc(String(value))}" placeholder="08:10-09:30">
                </div>
            `,
                )
                .join("")}
        </div>`;
}

export function timeRangeInput(desired) {
    return field(
        "Intervalo",
        `<input class="form-control" type="text" data-config-field="range" value="${esc(String(desired.range ?? "21:10-07:30"))}" placeholder="21:10-07:30">`,
    );
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
                ${field(
                    "Início (HHmmss)",
                    `<input class="form-control" type="text" data-config-field="sleepStartTime" value="${esc(String(desired.sleepStartTime ?? "220000"))}" placeholder="220000">`,
                    { cls: "col-md-4" },
                )}
                ${field(
                    "Fim (HHmmss)",
                    `<input class="form-control" type="text" data-config-field="sleepEndTime" value="${esc(String(desired.sleepEndTime ?? "100000"))}" placeholder="100000">`,
                    { cls: "col-md-4" },
                )}
                ${field(
                    "Meta (minutos)",
                    `<input class="form-control" type="number" min="0" step="1" data-config-field="sleepTarget" value="${esc(String(desired.sleepTarget ?? 480))}">`,
                    { cls: "col-md-4" },
                )}
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
            ${field(
                fieldLabel(valueField),
                `<input class="form-control" type="number" min="0" step="1" data-config-field="${esc(valueField)}" value="${esc(String(value))}">`,
            )}
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
                ${field(
                    "Limite principal",
                    `<input class="form-control" type="number" min="0" step="1" data-config-field="remindValue" value="${esc(String(desired.remindValue ?? 120))}">`,
                    { cls: "col-md-6" },
                )}
                <div class="col-md-6">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" role="switch" data-config-field="exerciseEnabled" ${exerciseEnabled ? "checked" : ""}>
                        <label class="form-check-label">Usar limites de exercício</label>
                    </div>
                </div>
                ${field(
                    "Mínimo exercício",
                    `<input class="form-control" type="number" min="0" step="1" data-config-field="exerciseHRMin" value="${esc(String(desired.exerciseHRMin ?? 100))}">`,
                    { cls: "col-md-4" },
                )}
                ${field(
                    "Máximo exercício",
                    `<input class="form-control" type="number" min="0" step="1" data-config-field="exerciseHRMax" value="${esc(String(desired.exerciseHRMax ?? 140))}">`,
                    { cls: "col-md-4" },
                )}
                ${field(
                    "Alerta em exercício",
                    `<input class="form-control" type="number" min="0" step="1" data-config-field="exerciseRemindValue" value="${esc(String(desired.exerciseRemindValue ?? 140))}">`,
                    { cls: "col-md-4" },
                )}
            </div>
        </div>`;
}

export function listInput(entry, desired, key, label) {
    const limit = Math.max(1, parseInt(String(entry.limit ?? 3), 10) || 3);
    const values = Array.isArray(desired[key]) ? desired[key] : [];
    const rows = Array.from(
        { length: limit },
        (_, index) => values[index] ?? "",
    );
    return `
        <div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label-sm mb-0">${esc(label)}</label>
                <span class="small text-secondary">${limit} itens</span>
            </div>
            <div class="vstack gap-2">
                ${rows
                    .map(
                        (value, index) => `
                    ${renderPhoneControl({
                        value,
                        configField: key,
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
                <div class="form-label-sm">Selecionar da lista telefónica</div>
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
                <label class="form-label-sm mb-0">Contactos</label>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="contacts">Adicionar</button>
            </div>
            <div class="small text-secondary mb-2">${limit} contactos máximos</div>
            <div class="vstack gap-2" data-repeat-list="contacts" data-repeat-limit="${limit}"${isPhonebookLike && nameMaxLengthValue > 0 ? ` data-phonebook-name-max-length="${esc(String(nameMaxLengthValue))}"` : ""}${isPhonebookLike && phoneMaxLengthValue > 0 ? ` data-phonebook-phone-max-length="${esc(String(phoneMaxLengthValue))}"` : ""}>
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
                                <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeRepeatRow">-</button>
                            </div>
                        </div>
                    </div>
                `,
                    )
                    .join("")}
            </div>
        </div>`;
}

function phoneRepeaterInput(entry, desired, options) {
    const limit = Math.max(1, parseInt(String(options.limit ?? entry.limit ?? 3), 10) || 3);
    const values = Array.isArray(desired)
        ? desired
        : Array.isArray(desired.numbers)
            ? desired.numbers
            : [];
    const rows = values.length ? values.slice(0, limit) : [""];
    const kind = String(options.kind || "numbers");
    const label = String(options.label || entry.label || "Lista");
    const helpText = String(options.helpText || "");
    const emptyLabel = String(options.emptyLabel || "Adicionar");
    const placeholderPrefix = String(options.placeholderPrefix || label);
    const phoneMaxLength = Math.max(0, parseInt(String(options.phoneMaxLength ?? 0), 10) || 0);

    return `
        <div class="vstack gap-3">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <label class="form-label-sm mb-0">${esc(label)}</label>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="${esc(kind)}">${esc(emptyLabel)}</button>
            </div>
            ${helpText !== "" ? `<div class="small text-secondary">${esc(helpText)}</div>` : ""}
            <div class="vstack gap-2" data-repeat-list="${esc(kind)}" data-repeat-limit="${limit}">
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
                            <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeRepeatRow">-</button>
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
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="alarm_clock">Adicionar item</button>
            </div>
            <div class="vstack gap-2" data-repeat-list="alarm_clock">
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

    const rows = alarms.slice(0, limit);

    return `
        <div class="vstack gap-3">
            <div class="small text-secondary">
                Até ${esc(String(limit))} alarmes. A recorrência personalizada usa uma máscara de 7 dias, de Segunda a Domingo.
            </div>
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="fourPTouchAlarm" ${rows.length >= limit ? "disabled" : ""}>Adicionar item</button>
            </div>
            <div class="vstack gap-2" data-repeat-list="fourPTouchAlarm" data-repeat-limit="${esc(String(limit))}">
                ${rows.map((alarm, index) => fourPTouchAlarmRow(alarm, index)).join("")}
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
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="wonlexMedicationPlan">
                    <i class="fa-solid fa-plus me-2"></i>Adicionar medicamento
                </button>
            </div>
            <div class="vstack gap-3" data-repeat-list="wonlexMedicationPlan">
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
                <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeRepeatRow" title="Remover medicamento" aria-label="Remover medicamento">
                    <i class="fa-solid fa-trash-can"></i>
                </button>
            </div>
            <div class="row g-3">
                ${field(
                    "Tipo",
                    `<select class="form-select" data-medication-field="drugType" required>
                        ${[
                            [0, "Hipertensão"],
                            [1, "Diabetes"],
                            [2, "Colesterol / lípidos"],
                            [3, "Ácido úrico elevado"],
                        ].map(([value, label]) => `
                            <option value="${value}" ${normalized.drugType === value ? "selected" : ""}>${esc(label)}</option>
                        `).join("")}
                    </select>`,
                    { cls: "col-md-4", required: true },
                )}
                ${field(
                    "Nome do medicamento",
                    `<input class="form-control" type="text" data-medication-field="drugName" value="${esc(normalized.drugName)}" placeholder="Ex.: Losartan" required>`,
                    { cls: "col-md-8", required: true },
                )}
                ${field(
                    "Dose",
                    `<input class="form-control" type="number" min="0" step="0.1" data-medication-field="drugDose" value="${esc(String(normalized.drugDose))}">`,
                    { cls: "col-sm-6 col-md-3" },
                )}
                ${field(
                    "Unidade",
                    `<select class="form-select" data-medication-field="drugUnit">
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
                    </select>`,
                    { cls: "col-sm-6 col-md-3" },
                )}
                ${field(
                    "Data inicial",
                    `<input class="form-control" type="date" data-medication-field="drugStartTime" value="${esc(normalized.drugStartTime)}" required>`,
                    { cls: "col-sm-6 col-md-3", required: true },
                )}
                ${field(
                    "Data final",
                    `<input class="form-control" type="date" data-medication-field="drugEndTime" value="${esc(normalized.drugEndTime)}" required>`,
                    { cls: "col-sm-6 col-md-3", required: true },
                )}
                ${field(
                    "Intervalo",
                    `<div class="input-group">
                        <input class="form-control" type="number" min="0" step="0.5" data-medication-field="drugInterval" value="${esc(String(normalized.drugInterval))}" required>
                        <span class="input-group-text">dias</span>
                    </div>`,
                    { cls: "col-sm-6 col-md-4", required: true },
                )}
                ${field(
                    "Tomar",
                    `<div class="btn-group" role="group" aria-label="Relação com a refeição">
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
                    </div>`,
                    { cls: "col-sm-6 col-md-8", required: true },
                )}
            </div>
            <div class="mt-3">
                <label class="form-label-sm required">Períodos e horários</label>
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
        <div class="border rounded p-3 bg-body" data-repeat-row="fourPTouchAlarm" data-fourptouch-alarm-row="${index}">
            <div class="row g-3 align-items-end">
                ${field(
                    "Hora",
                    `<input class="form-control" type="text" inputmode="numeric" maxlength="5" pattern="[0-9]{2}:[0-9]{2}" placeholder="HH:MM" data-time-format="24h" data-fourptouch-field="time" value="${esc(formatFourPTouchAlarmTime(alarm.time))}">`,
                    { cls: "col-sm-6 col-lg-2" },
                )}
                <div class="col-sm-6 col-lg-2">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" role="switch" data-fourptouch-field="enabled" ${boolValue(alarm.enabled, true) ? "checked" : ""}>
                        <label class="form-check-label" data-switch-label>${boolValue(alarm.enabled, true) ? "Ligado" : "Desligado"}</label>
                    </div>
                </div>
                ${field(
                    "Modo",
                    `<select class="form-select" data-config-field="mode" data-fourptouch-field="mode">
                        ${modeOptions
                            .map(
                                (option) => `
                            <option value="${option.value}" ${option.value === mode ? "selected" : ""}>${esc(option.label)}</option>
                        `,
                            )
                            .join("")}
                    </select>`,
                    { cls: "col-12 col-lg-3" },
                )}
                <div class="col-12 col-lg-5 ${customVisible ? "" : "d-none"}" data-fourptouch-custom-wrapper>
                    <label class="form-label-sm">Dias personalizados</label>
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
                <div class="col-12 d-flex justify-content-end">
                    <button type="button" class="btn btn-outline-danger btn-sm" data-action="removeRepeatRow" title="Remover alarme" aria-label="Remover alarme">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>
            </div>
        </div>`;
}

function alarmClockRow(item = {}, typeOptions = [], recurrenceOptions = [], wonlexFields = {}) {
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
                    ? field(
                            "Nome do alarme",
                            `<input class="form-control" type="text" placeholder="Ex.: Tomar medicação" data-alarm-clock-field="label" value="${esc(String(item.label || ""))}">`,
                            { cls: "col-12 col-lg-4" },
                        )
                    : ""}
                ${field(
                    "Hora",
                    `<input class="form-control" type="text" inputmode="numeric" maxlength="5" pattern="[0-9]{2}:[0-9]{2}" placeholder="HH:MM" data-time-format="24h" data-alarm-clock-field="time" value="${esc(formatReminderTime(item.time))}" required>`,
                    { cls: `col-sm-6 col-lg-${hasTypeSelector ? "1" : "3"}`, required: true },
                )}
                <div class="col-sm-6 col-lg-${hasTypeSelector ? "1" : "3"}">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" role="switch" data-alarm-clock-field="enabled" ${boolValue(item.enabled, true) ? "checked" : ""}>
                        <label class="form-check-label" data-switch-label>${boolValue(item.enabled, true) ? "Ligado" : "Desligado"}</label>
                    </div>
                </div>
                ${hasTypeSelector
                    ? field(
                            "Tipo",
                            `<div class="btn-group w-100" role="group" aria-label="Tipo de alarme">
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
                    </div>`,
                            { cls: "col-12 col-lg-3" },
                        )
                    : ""}
                ${field(
                    "Recorrência",
                    `<div class="btn-group w-100" role="group" aria-label="Recorrência do alarme">
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
                    </div>`,
                    { cls: `col-12 col-lg-${hasTypeSelector ? "3" : "4"}`, required: true },
                )}
                <div class="col-12 col-lg-3 ${customVisible ? "" : "d-none"}" data-alarm-clock-custom-wrapper>
                    <label class="form-label-sm required">Dias personalizados</label>
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
                    <button type="button" class="btn btn-outline-danger btn-sm mt-lg-4" data-action="removeRepeatRow" title="Remover" aria-label="Remover">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>
                ${wonlexFields.url
                    ? field(
                            "URL do áudio",
                            `<input class="form-control" type="url" inputmode="url" placeholder="https://exemplo.pt/lembrete.mp3" data-alarm-clock-field="url" value="${esc(String(item.url || ""))}">`,
                            {
                                cls: "col-12",
                                help: "Endereço HTTP ou HTTPS opcional para o ficheiro de voz do lembrete.",
                            },
                        )
                    : ""}
            </div>
        </div>`;
}

function nextUid(prefix) {
    uidCounter += 1;
    return `${prefix}-${uidCounter}`;
}
