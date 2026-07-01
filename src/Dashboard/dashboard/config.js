import { esc, fieldLabel, titleize } from "./format.js";
import { normalizePhoneControl, renderPhoneControl } from "./phone.js";
import { requestJson } from "./api/http.js";
import { state } from "./state.js";

const CATEGORY_LABELS = {
    "vivistar-iw": {
        contacts: "Contactos",
        alerts: "Alertas",
        health: "Saúde",
        system: "Sistema",
        intervals: "Intervalos",
    },
    "wonlex-json": {
        contacts: "Contactos",
        alerts: "Alarmes",
        health: "Saúde",
        measurements: "Medições",
        system: "Sistema",
        intervals: "Intervalos",
    },
    "four-p-touch": {
        contacts: "Contactos",
        alerts: "Alertas",
        health: "Saúde",
        system: "Sistema",
        intervals: "Intervalos",
    },
};

const CATEGORY_ORDER = {
    "vivistar-iw": ["contacts", "alerts", "health", "system", "intervals"],
    "wonlex-json": [
        "intervals",
        "contacts",
        "measurements",
        "alerts",
        "health",
        "system",
    ],
    "four-p-touch": ["intervals", "contacts", "alerts", "health", "system"],
};

let uidCounter = 0;

export async function catalogForProtocol(protocol) {
    if (!protocol) {
        return [];
    }

    if (state.protocolCatalogs[protocol]) {
        return state.protocolCatalogs[protocol];
    }

    const response = await requestJson(`/api/protocols/${encodeURIComponent(protocol)}/config-catalog`);
    const catalog = Array.isArray(response.data) ? response.data : [];
    state.protocolCatalogs[protocol] = catalog;
    return catalog;
}

export function groupedCatalog(catalog) {
    const groups = [];
    const index = new Map();

    for (const entry of catalog) {
        const key = entry.category || "general";
        if (!index.has(key)) {
            index.set(key, { key, label: "", entries: [] });
            groups.push(index.get(key));
        }
        index.get(key).entries.push(entry);
    }

    return groups;
}

export function renderDeviceConfigurationRoot(context) {
    const {
        protocol,
        catalog,
        configurations = {},
        supplier = "",
        model = "",
        disabled = false,
        activeCategory = "",
        uiByKey = {},
    } = context;
    if (!protocol) {
        return emptyConfigurationState(
            "Selecione fornecedor e modelo para ver as configurações.",
        );
    }

    if (!catalog.length) {
        return emptyConfigurationState(
            "Este protocolo não tem configurações suportadas.",
        );
    }

    const rowsByKey = configurations;
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
    const currentCategory = groups.some((group) => group.key === activeCategory)
        ? activeCategory
        : groups[0]?.key || "";
    for (const group of groups) {
        group.label = categoryLabel(protocol, group.key);
    }

    return `
        <div class="vstack gap-3">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="fw-semibold">Configurações do dispositivo</div>
                    <div class="small text-secondary">${supplier || model ? `${esc(supplier)} ${esc(model)}` : ""}</div>
                </div>
                <span class="badge text-bg-secondary">${catalog.length} opções</span>
            </div>
            <div class="nav nav-tabs nav-fill flex-wrap gap-1" role="tablist">
                ${groups
                    .map(
                        (group) => `
                    <button type="button" class="nav-link ${group.key === currentCategory ? "active" : ""}" data-config-category="${esc(group.key)}">
                        ${esc(group.label)}
                    </button>
                `,
                    )
                    .join("")}
            </div>
            <div class="tab-content">
                ${groups
                    .map(
                        (group) => `
                    <div class="tab-pane fade ${group.key === currentCategory ? "show active" : ""}" data-config-category-pane="${esc(group.key)}">
                        ${group.entries.map((entry) => renderConfigSection(protocol, entry, rowsByKey[entry.key] || null, disabled, uiByKey[entry.key] || null)).join("")}
                    </div>
                `,
                    )
                    .join("")}
            </div>
        </div>`;
}

export function renderConfigSection(
    protocol,
    entry,
    row,
    disabled = false,
    uiState = null,
) {
    const desired = normalizeDesired(entry, row);
    const help = configHelp(entry);

    return `
        <section class="border rounded-3 p-3 mb-3 bg-body-tertiary" data-config-section data-config-key="${esc(entry.key)}" data-config-input="${esc(entry.input || "json")}" data-config-protocol="${esc(protocol)}" data-config-limit="${esc(String(entry.limit ?? ""))}">
            <div>
                <div>
                    <div class="fw-semibold">${esc(entry.label || entry.key)}</div>
                    <div class="small text-secondary">${esc(entry.command)} · ${esc(titleize(entry.input || "json"))}${help ? ` · ${esc(help)}` : ""}</div>
                </div>
            </div>
            <form class="mt-3" data-config-form data-config-key="${esc(entry.key)}" ${disabled ? 'data-config-disabled="1"' : ""}>
                ${renderConfigInputs(entry, desired)}
                <div class="d-flex justify-content-end gap-2 mt-3">
                    ${renderConfigActionButton(entry.key, row, uiState, disabled)}
                    <button type="reset" class="btn btn-outline-secondary btn-sm" title="Repor" aria-label="Repor" ${disabled ? "disabled" : ""}>
                        <i class="fa-solid fa-rotate-left"></i>
                    </button>
                </div>
            </form>
            ${renderConfigFeedback(entry.key, uiState)}
        </section>`;
}

function renderConfigActionButton(key, row, uiState, disabled = false) {
    const state = configButtonState(row, uiState);
    const idleLabel = key === "pushMessage" ? "Enviar mensagem" : "Enviar";
    const icons = {
        idle: "fa-paper-plane",
        submitting: "fa-spinner fa-spin",
        sent: "fa-check",
        queued: "fa-list-check",
        waiting: "fa-hourglass-half",
        acked: "fa-circle-check",
        failed: "fa-triangle-exclamation",
        dropped: "fa-triangle-exclamation",
    };
    const labels = {
        idle: idleLabel,
        submitting: "A enviar",
        sent: "Enviado",
        queued: "Em fila",
        waiting: "À espera",
        acked: "Confirmado",
        failed: "Falhou",
        dropped: "Falhou",
    };
    const classes = {
        idle: "btn-primary",
        submitting: "btn-primary",
        sent: "btn-info",
        queued: "btn-secondary",
        waiting: "btn-warning",
        acked: "btn-success",
        failed: "btn-danger",
        dropped: "btn-danger",
    };
    const isDisabled =
        disabled || ["submitting", "sent", "queued", "waiting"].includes(state);

    return `
        <button type="button" class="btn ${classes[state] || "btn-primary"} btn-sm" data-action="saveConfig" data-config-key="${esc(key)}" ${isDisabled ? "disabled" : ""}>
            <i class="fa-solid ${icons[state] || "fa-paper-plane"} me-2"></i>${labels[state] || "Enviar"}
        </button>`;
}

function renderConfigFeedback(key, uiState) {
    if (!uiState?.feedback?.message) {
        return "";
    }

    const tone = uiState.feedback.tone === "danger" ? "danger" : "success";

    return `
        <div class="alert alert-${tone} alert-dismissible fade show small mt-3 mb-0 py-2 px-3" role="alert" data-config-feedback-key="${esc(key)}">
            ${esc(uiState.feedback.message)}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
        </div>`;
}

function configButtonState(_row, uiState) {
    if (uiState?.phase === "submitting" || uiState?.phase === "sent") {
        return uiState.phase;
    }
    return "idle";
}

export function renderConfigInputs(entry, desired) {
    const input = entry.input || "json";
    if (input === "toggle") {
        return toggleInput(entry, desired);
    }
    if (input === "fallSensitivity") {
        return fallSensitivityInput(desired);
    }
    if (input === "number") {
        return numberInput(entry, desired);
    }
    if (input === "phone") {
        return phoneInput(entry, desired);
    }
    if (input === "text") {
        return textInput(entry, desired);
    }
    if (input === "pushMessage") {
        return pushMessageInput(entry, desired);
    }
    if (input === "intervalToggle") {
        return intervalToggleInput(entry, desired);
    }
    if (input === "intervalHoursToggle") {
        return intervalHoursToggleInput(desired);
    }
    if (input === "workingMode") {
        return workingModeInput(desired);
    }
    if (input === "bloodPressure") {
        return bloodPressureInput(desired);
    }
    if (input === "wonlexBloodPressureWarning") {
        return wonlexBloodPressureWarningInput(desired);
    }
    if (input === "languageTimezone") {
        return languageTimezoneInput(desired);
    }
    if (input === "dualToggle") {
        return dualToggleInput(desired);
    }
    if (input === "fallSensitivityLevels") {
        return fallSensitivityLevelsInput(desired);
    }
    if (input === "timeRanges") {
        return timeRangesInput(entry, desired);
    }
    if (input === "timeRange") {
        return timeRangeInput(desired);
    }
    if (input === "wonlexSleepSettings") {
        return wonlexSleepSettingsInput(desired);
    }
    if (input === "wonlexReminderThreshold") {
        return wonlexReminderThresholdInput(entry, desired);
    }
    if (input === "wonlexHeartRateRange") {
        return wonlexHeartRateRangeInput(desired);
    }
    if (input === "list") {
        return listInput(entry, desired, "numbers", "Números SOS");
    }
    if (input === "contacts") {
        return contactsInput(entry, desired);
    }
    if (input === "reminders") {
        return remindersInput(desired);
    }

    return jsonInput(desired);
}

export function readConfigPayload(section) {
    const input = section.dataset.configInput || "json";
    if (input === "toggle") {
        const field = firstFieldName(section);
        return { [field]: readCheckbox(section, field) };
    }
    if (input === "fallSensitivity") {
        return { sensitivity: readNumber(section, "sensitivity") };
    }
    if (input === "number") {
        return {
            [firstFieldName(section)]: readNumber(
                section,
                firstFieldName(section),
            ),
        };
    }
    if (input === "phone") {
        return {
            [firstFieldName(section)]: readPhone(
                section,
                firstFieldName(section),
            ),
        };
    }
    if (input === "text") {
        return {
            [firstFieldName(section)]: readText(
                section,
                firstFieldName(section),
            ),
        };
    }
    if (input === "pushMessage") {
        return { message: readText(section, "message") };
    }
    if (input === "intervalToggle") {
        return {
            enabled: readCheckbox(section, "enabled"),
            intervalMinutes: readNumber(section, "intervalMinutes"),
        };
    }
    if (input === "intervalHoursToggle") {
        return {
            enabled: readCheckbox(section, "enabled"),
            intervalHours: readNumber(section, "intervalHours"),
        };
    }
    if (input === "workingMode") {
        const mode = readNumber(section, "mode");
        const payload = { mode };
        if (mode === 8) {
            payload.intervalSeconds = readNumber(section, "intervalSeconds");
            payload.gpsEnabled = readCheckbox(section, "gpsEnabled");
        }
        return payload;
    }
    if (input === "bloodPressure") {
        return {
            systolic: readNumber(section, "systolic"),
            diastolic: readNumber(section, "diastolic"),
        };
    }
    if (input === "wonlexBloodPressureWarning") {
        return {
            switchState: readCheckbox(section, "switchState"),
            hpWarn: readNumber(section, "hpWarn"),
            LPWarn: readNumber(section, "LPWarn"),
        };
    }
    if (input === "languageTimezone") {
        return {
            language: readNumber(section, "language"),
            timeZone: readText(section, "timeZone"),
        };
    }
    if (input === "dualToggle") {
        return {
            enabled: readCheckbox(section, "enabled"),
            callCenterOnFall: readCheckbox(section, "callCenterOnFall"),
        };
    }
    if (input === "fallSensitivityLevels") {
        return {
            sensitivityLevel: readNumber(section, "sensitivityLevel"),
            totalLevels: readNumber(section, "totalLevels"),
        };
    }
    if (input === "timeRanges") {
        return { ranges: readTextArray(section, "ranges") };
    }
    if (input === "timeRange") {
        return { range: readText(section, "range") };
    }
    if (input === "wonlexSleepSettings") {
        return {
            switchState: readCheckbox(section, "switchState"),
            sleepStartTime: readText(section, "sleepStartTime"),
            sleepEndTime: readText(section, "sleepEndTime"),
            sleepTarget: readNumber(section, "sleepTarget"),
        };
    }
    if (input === "wonlexReminderThreshold") {
        const valueField = section.querySelector(
            '[data-config-field="RemindValue"]',
        )
            ? "RemindValue"
            : "reminderValue";
        return {
            switchState: readCheckbox(section, "switchState"),
            [valueField]: readNumber(section, valueField),
        };
    }
    if (input === "wonlexHeartRateRange") {
        return {
            switchState: readCheckbox(section, "switchState"),
            remindValue: readNumber(section, "remindValue"),
            exerciseSwitchState: readCheckbox(section, "exerciseSwitchState"),
            exerciseHRMin: readNumber(section, "exerciseHRMin"),
            exerciseHRMax: readNumber(section, "exerciseHRMax"),
            exerciseRemindValue: readNumber(section, "exerciseRemindValue"),
        };
    }
    if (input === "list") {
        const limit = parseInt(section.dataset.configLimit || "3", 10) || 3;
        return { numbers: readPhoneArray(section, "numbers").slice(0, limit) };
    }
    if (input === "contacts") {
        return { contacts: readContacts(section) };
    }
    if (input === "reminders") {
        return readReminders(section);
    }

    return readJson(section);
}

export function defaultConfigPayload(entry) {
    const input = entry.input || "json";
    const field = entry.fields?.[0] || "value";
    if (input === "toggle") return { [field]: true };
    if (input === "fallSensitivity") return { sensitivity: 2 };
    if (input === "number") return { [field]: 0 };
    if (input === "phone" || input === "text") return { [field]: "" };
    if (input === "intervalToggle")
        return { enabled: true, intervalMinutes: 60 };
    if (input === "intervalHoursToggle")
        return { enabled: true, intervalHours: 2 };
    if (input === "workingMode") return { mode: 1 };
    if (input === "bloodPressure") return { systolic: 120, diastolic: 80 };
    if (input === "wonlexBloodPressureWarning")
        return { switchState: true, hpWarn: 135, LPWarn: 90 };
    if (input === "languageTimezone") return { language: 3, timeZone: "0" };
    if (input === "dualToggle")
        return { enabled: true, callCenterOnFall: false };
    if (input === "fallSensitivityLevels")
        return { sensitivityLevel: 5, totalLevels: 8 };
    if (input === "timeRanges") return { ranges: ["08:10-09:30"] };
    if (input === "timeRange") return { range: "21:10-07:30" };
    if (input === "wonlexSleepSettings")
        return {
            switchState: true,
            sleepStartTime: "220000",
            sleepEndTime: "100000",
            sleepTarget: 480,
        };
    if (input === "wonlexReminderThreshold")
        return { switchState: true, reminderValue: 90 };
    if (input === "wonlexHeartRateRange")
        return {
            switchState: true,
            remindValue: 120,
            exerciseSwitchState: true,
            exerciseHRMin: 100,
            exerciseHRMax: 140,
            exerciseRemindValue: 140,
        };
    if (input === "list") return { numbers: ["", "", ""] };
    if (input === "contacts") return { contacts: [{ name: "", phone: "" }] };
    if (input === "reminders") return { masterEnabled: true, items: [] };
    return {};
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
    if ((entry.input || "") === "list" && (entry.limit || 0) > 0) {
        return `limite ${entry.limit}`;
    }
    if ((entry.input || "") === "contacts" && (entry.limit || 0) > 0) {
        return `limite ${entry.limit}`;
    }
    if ((entry.key || "") === "whitelistSwitch") {
        return "ativa os contactos da lista telefónica do BP14";
    }
    return "";
}

function categoryLabel(protocol, category) {
    const labels = CATEGORY_LABELS[protocol] || {};
    return labels[category] || titleize(category);
}

function firstFieldName(section) {
    return (
        section.querySelector("[data-config-field]")?.dataset.configField ||
        "value"
    );
}

function readCheckbox(section, field) {
    return (
        section.querySelector(`[data-config-field="${CSS.escape(field)}"]`)
            ?.checked || false
    );
}

function readNumber(section, field) {
    const nodes = Array.from(
        section.querySelectorAll(`[data-config-field="${CSS.escape(field)}"]`),
    );
    const input =
        nodes.find((node) => ("checked" in node ? node.checked : false)) ||
        nodes[0] ||
        null;
    const value = input?.value ?? "";
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
}

function readText(section, field) {
    return String(
        section.querySelector(`[data-config-field="${CSS.escape(field)}"]`)
            ?.value || "",
    ).trim();
}

function readTextArray(section, field) {
    return Array.from(
        section.querySelectorAll(`[data-config-field="${CSS.escape(field)}"]`),
    )
        .map((input) => String(input.value || "").trim())
        .filter(Boolean);
}

function readPhoneArray(section, field) {
    return Array.from(
        section.querySelectorAll(
            `[data-phone-control][data-config-field="${CSS.escape(field)}"]`,
        ),
    )
        .map((control) => normalizePhoneControl(control))
        .filter(Boolean);
}

function readPhone(section, field) {
    const control = section.querySelector(
        `[data-phone-control][data-config-field="${CSS.escape(field)}"]`,
    );
    return control ? normalizePhoneControl(control) : "";
}

function readContacts(section) {
    return Array.from(section.querySelectorAll('[data-repeat-row="contacts"]'))
        .map((row) => ({
            name: String(
                row.querySelector('[data-repeat-field="name"]')?.value || "",
            ).trim(),
            phone: normalizePhoneControl(
                row.querySelector(
                    '[data-phone-control][data-repeat-field="phone"]',
                ),
            ),
        }))
        .filter((contact) => contact.name !== "" || contact.phone !== "");
}

function readReminders(section) {
    const items = Array.from(
        section.querySelectorAll('[data-repeat-row="reminders"]'),
    )
        .map((row) => ({
            time: String(
                row.querySelector('[data-repeat-field="time"]')?.value || "",
            ).trim(),
            days: readReminderDays(row),
            enabled:
                row.querySelector('[data-repeat-field="enabled"]')?.checked ||
                false,
            type: readCheckedNumberFromRow(row, "type", 1),
        }))
        .filter((item) => item.time !== "" || item.days !== "");

    return {
        masterEnabled:
            section.querySelector('[data-config-field="masterEnabled"]')
                ?.checked || false,
        items,
    };
}

function readNumberFromRow(row, field) {
    const value =
        row.querySelector(`[data-repeat-field="${CSS.escape(field)}"]`)
            ?.value ?? "";
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
}

function readCheckedNumberFromRow(row, field, fallback = 0) {
    const value =
        row.querySelector(`[data-repeat-field="${CSS.escape(field)}"]:checked`)
            ?.value ?? "";
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : fallback;
}

function readReminderDays(row) {
    return Array.from(
        row.querySelectorAll('[data-repeat-field="days"]:checked'),
    )
        .map((input) => String(input.value || ""))
        .join("");
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
        return JSON.parse(textarea.value || "{}");
    } catch {
        throw new Error("JSON inválido para esta configuração");
    }
}

function toggleInput(entry, desired) {
    const field = entry.fields?.[0] || "enabled";
    const checked = boolValue(desired[field], true);
    return `
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" data-config-field="${esc(field)}" ${checked ? "checked" : ""}>
            <label class="form-check-label" data-switch-label>${checked ? "Ligado" : "Desligado"}</label>
        </div>`;
}

function fallSensitivityInput(desired) {
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
            <div class="btn-group w-100" role="group" aria-label="Sensibilidade de queda">
                ${options
                    .map(
                        (option) => `
                    <input
                        class="btn-check"
                        type="radio"
                        name="fallSensitivity"
                        id="fallSensitivity${option.value}"
                        data-config-field="sensitivity"
                        value="${option.value}"
                        ${option.value === current ? "checked" : ""}>
                    <label class="btn ${option.className}" for="fallSensitivity${option.value}">
                        <i class="fa-solid ${option.icon} me-2"></i>${option.label}
                    </label>
                `,
                    )
                    .join("")}
            </div>
        </div>`;
}

function numberInput(entry, desired) {
    const field = entry.fields?.[0] || "value";
    const value = desired[field] ?? 0;
    return `
        <div>
            <label class="form-label form-label-sm">${esc(fieldLabel(field))}</label>
            <input class="form-control" type="number" min="0" step="1" data-config-field="${esc(field)}" value="${esc(String(value))}">
        </div>`;
}

function phoneInput(entry, desired) {
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

function textInput(entry, desired) {
    const field = entry.fields?.[0] || "value";
    const value = desired[field] ?? "";
    return `
        <div>
            <label class="form-label form-label-sm">${esc(fieldLabel(field))}</label>
            <input class="form-control" type="text" data-config-field="${esc(field)}" value="${esc(String(value))}">
        </div>`;
}

function pushMessageInput(_entry, desired) {
    return `
        <div>
            <label class="form-label form-label-sm">Mensagem</label>
            <input class="form-control" type="text" data-config-field="message" value="${esc(String(desired.message ?? ""))}" placeholder="Mensagem a mostrar no relógio">
            <div class="form-text">Envia uma mensagem imediata para o relógio. Não fica guardada como configuração desejada.</div>
        </div>`;
}

function intervalToggleInput(entry, desired) {
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
                <input class="form-control" type="number" min="1" step="1" data-config-field="intervalMinutes" value="${esc(String(desired.intervalMinutes ?? 60))}">
            </div>
        </div>`;
}

function intervalHoursToggleInput(desired) {
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

function workingModeInput(desired) {
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

function wonlexBloodPressureWarningInput(desired) {
    const enabled = boolValue(desired.switchState, true);
    return `
        <div class="vstack gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="switchState" ${enabled ? "checked" : ""}>
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

function languageTimezoneInput(desired) {
    const currentLanguage = parseInt(String(desired.language ?? 3), 10) || 3;
    const options = [
        { value: 0, label: "English" },
        { value: 1, label: "中文" },
        { value: 3, label: "Português" },
        { value: 4, label: "Español" },
        { value: 5, label: "Deutsch" },
        { value: 10, label: "Français" },
    ];

    return `
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label form-label-sm">Idioma</label>
                <select class="form-select" data-config-field="language">
                    ${options
                        .map(
                            (option) => `
                        <option value="${option.value}" ${option.value === currentLanguage ? "selected" : ""}>${esc(option.label)} (${option.value})</option>
                    `,
                        )
                        .join("")}
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label form-label-sm">Fuso horário</label>
                <input class="form-control" type="text" data-config-field="timeZone" value="${esc(String(desired.timeZone ?? "0"))}" placeholder="Ex.: 0, 1, 8">
            </div>
        </div>`;
}

function dualToggleInput(desired) {
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

function fallSensitivityLevelsInput(desired) {
    const sensitivityLevel =
        parseInt(String(desired.sensitivityLevel ?? 5), 10) || 5;
    const totalLevels = parseInt(String(desired.totalLevels ?? 8), 10) || 8;
    return `
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label form-label-sm">Nível de sensibilidade</label>
                <input class="form-control" type="number" min="1" max="${esc(String(totalLevels))}" step="1" data-config-field="sensitivityLevel" value="${esc(String(sensitivityLevel))}">
            </div>
            <div class="col-md-6">
                <label class="form-label form-label-sm">Níveis totais do firmware</label>
                <select class="form-select" data-config-field="totalLevels">
                    <option value="6" ${totalLevels === 6 ? "selected" : ""}>6</option>
                    <option value="8" ${totalLevels === 8 ? "selected" : ""}>8</option>
                </select>
            </div>
        </div>`;
}

function timeRangesInput(entry, desired) {
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

function timeRangeInput(desired) {
    return `
        <div>
            <label class="form-label form-label-sm">Intervalo</label>
            <input class="form-control" type="text" data-config-field="range" value="${esc(String(desired.range ?? "21:10-07:30"))}" placeholder="21:10-07:30">
        </div>`;
}

function wonlexSleepSettingsInput(desired) {
    const enabled = boolValue(desired.switchState, true);
    return `
        <div class="vstack gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="switchState" ${enabled ? "checked" : ""}>
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

function wonlexReminderThresholdInput(entry, desired) {
    const enabled = boolValue(desired.switchState, true);
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
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="switchState" ${enabled ? "checked" : ""}>
                <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
            </div>
            <div>
                <label class="form-label form-label-sm">${esc(fieldLabel(valueField))}</label>
                <input class="form-control" type="number" min="0" step="1" data-config-field="${esc(valueField)}" value="${esc(String(value))}">
            </div>
        </div>`;
}

function wonlexHeartRateRangeInput(desired) {
    const enabled = boolValue(desired.switchState, true);
    const exerciseEnabled = boolValue(desired.exerciseSwitchState, true);
    return `
        <div class="vstack gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="switchState" ${enabled ? "checked" : ""}>
                <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label form-label-sm">Limite principal</label>
                    <input class="form-control" type="number" min="0" step="1" data-config-field="remindValue" value="${esc(String(desired.remindValue ?? 120))}">
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" role="switch" data-config-field="exerciseSwitchState" ${exerciseEnabled ? "checked" : ""}>
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

function listInput(entry, desired, field, label) {
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
                ${rows
                    .map(
                        (contact, index) => `
                    <div class="row g-2 align-items-end" data-repeat-row="contacts">
                        <div class="col-md-6">
                            <input class="form-control" type="text" placeholder="Nome ${index + 1}" data-repeat-field="name" value="${esc(String(contact.name || ""))}">
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2">
                                <div class="flex-grow-1">
                                    ${renderPhoneControl({
                                        value: String(contact.phone || ""),
                                        repeatField: "phone",
                                        placeholder: `Telefone ${index + 1}`,
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

function remindersInput(desired) {
    const masterEnabled = boolValue(desired.masterEnabled, true);
    const items =
        Array.isArray(desired.items) && desired.items.length
            ? desired.items
            : [{}];
    return `
        <div class="vstack gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="masterEnabled" ${masterEnabled ? "checked" : ""}>
                <label class="form-check-label" data-switch-label data-switch-on="Alertas ligados" data-switch-off="Alertas desligados">Alertas ${masterEnabled ? "ligados" : "desligados"}</label>
            </div>
            <div class="small text-secondary">Cada alarme suporta hora, dias da semana, estado e tipo: medicação, água ou sedentarismo.</div>
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addReminderRow">Adicionar lembrete</button>
            </div>
            <div class="vstack gap-2" data-reminders-list>
                ${items.map((item) => reminderRow(item)).join("")}
            </div>
        </div>`;
}

function reminderRow(item = {}) {
    const normalizedDays = String(item.days || "").replace(/[^1-7]/g, "");
    const reminderType = parseInt(String(item.type ?? 1), 10) || 1;
    const rowId = nextUid("reminder");
    const dayButtons = [
        { value: "1", label: "Seg" },
        { value: "2", label: "Ter" },
        { value: "3", label: "Qua" },
        { value: "4", label: "Qui" },
        { value: "5", label: "Sex" },
        { value: "6", label: "Sab" },
        { value: "7", label: "Dom" },
    ];
    const typeButtons = [
        {
            value: 1,
            label: "Medicação",
            icon: "fa-pills",
            className: "btn-outline-primary",
        },
        {
            value: 2,
            label: "Água",
            icon: "fa-glass-water",
            className: "btn-outline-info",
        },
        {
            value: 3,
            label: "Sedentarismo",
            icon: "fa-person-walking",
            className: "btn-outline-warning",
        },
    ];
    return `
        <div class="border rounded p-3 bg-body" data-repeat-row="reminders">
            <div class="row g-3 align-items-end">
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label form-label-sm">Hora</label>
                    <input class="form-control" type="time" data-repeat-field="time" value="${esc(formatReminderTime(item.time))}">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" role="switch" data-repeat-field="enabled" ${boolValue(item.enabled, true) ? "checked" : ""}>
                        <label class="form-check-label" data-switch-label>${boolValue(item.enabled, true) ? "Ligado" : "Desligado"}</label>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <label class="form-label form-label-sm d-block">Dias</label>
                    <div class="d-flex flex-wrap gap-1" role="group" aria-label="Dias da semana">
                        ${dayButtons
                            .map(
                                (day) => `
                            <input
                                class="btn-check"
                                type="checkbox"
                                id="${rowId}-day-${day.value}"
                                data-repeat-field="days"
                                value="${day.value}"
                                ${normalizedDays.includes(day.value) ? "checked" : ""}>
                            <label class="btn btn-outline-secondary btn-sm" for="${rowId}-day-${day.value}">${day.label}</label>
                        `,
                            )
                            .join("")}
                    </div>
                </div>
                <div class="col-12 col-lg-3">
                    <label class="form-label form-label-sm d-block">Tipo</label>
                    <div class="row g-2" role="group" aria-label="Tipo de lembrete">
                        ${typeButtons
                            .map(
                                (option) => `
                            <div class="col-12">
                                <input
                                    class="btn-check"
                                    type="radio"
                                    name="${rowId}-type"
                                    id="${rowId}-type-${option.value}"
                                    data-repeat-field="type"
                                    value="${option.value}"
                                    ${reminderType === option.value ? "checked" : ""}>
                                <label class="btn ${option.className} btn-sm w-100 text-start" for="${rowId}-type-${option.value}">
                                    <i class="fa-solid ${option.icon} me-1"></i>${option.label}
                                </label>
                            </div>
                        `,
                            )
                            .join("")}
                    </div>
                </div>
                <div class="col-12 col-lg-1 d-flex justify-content-lg-end">
                    <button type="button" class="btn btn-outline-danger btn-sm mt-lg-4" data-action="removeReminderRow" title="Remover" aria-label="Remover">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>
            </div>
        </div>`;
}

function boolValue(value, fallback = false) {
    if (value === true || value === 1 || value === "1") {
        return true;
    }
    if (value === false || value === 0 || value === "0") {
        return false;
    }
    return fallback;
}

function formatReminderTime(value) {
    const digits = String(value || "").replace(/[^0-9]/g, "");
    if (digits.length !== 4) {
        return "";
    }
    return `${digits.slice(0, 2)}:${digits.slice(2, 4)}`;
}

function nextUid(prefix) {
    uidCounter += 1;
    return `${prefix}-${uidCounter}`;
}
