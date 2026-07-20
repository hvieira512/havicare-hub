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
const protocolCatalogRequests = {};

const CONFIG_ACTION_BUTTON_META = {
    idle: {
        icon: "fa-paper-plane",
        label: "Enviar",
        className: "btn-primary",
    },
    submitting: {
        icon: "fa-spinner fa-spin",
        label: "A enviar",
        className: "btn-primary",
    },
    sent: {icon: "fa-check", label: "Enviado", className: "btn-info"},
    queued: {icon: "fa-list-check", label: "Em fila", className: "btn-secondary"},
    waiting: {icon: "fa-hourglass-half", label: "À espera", className: "btn-warning"},
    acked: {icon: "fa-circle-check", label: "Confirmado", className: "btn-success"},
    failed: {
        icon: "fa-triangle-exclamation",
        label: "Falhou",
        className: "btn-danger",
    },
    dropped: {
        icon: "fa-triangle-exclamation",
        label: "Falhou",
        className: "btn-danger",
    },
};

const CONFIG_INPUT_RENDERERS = {
    toggle: (entry, desired) => toggleInput(entry, desired),
    fallSensitivity: (_entry, desired) => fallSensitivityInput(desired),
    number: (entry, desired) => numberInput(entry, desired),
    phone: (entry, desired) => phoneInput(entry, desired),
    text: (entry, desired) => textInput(entry, desired),
    pushMessage: (_entry, desired) => pushMessageInput(_entry, desired),
    makeCall: (entry, desired) => makeCallInput(entry, desired),
    resetAction: (entry, desired) => resetActionInput(entry, desired),
    requestAction: (entry) => requestActionInput(entry),
    intervalToggle: (entry, desired) => intervalToggleInput(entry, desired),
    intervalHoursToggle: (_entry, desired) => intervalHoursToggleInput(desired),
    workingMode: (_entry, desired) => workingModeInput(desired),
    bloodPressure: (_entry, desired) => bloodPressureInput(desired),
    wonlexBloodPressureWarning: (_entry, desired) =>
        wonlexBloodPressureWarningInput(desired),
    languageTimezone: (_entry, desired) => languageTimezoneInput(desired),
    dualToggle: (_entry, desired) => dualToggleInput(desired),
    fallSensitivityLevels: (_entry, desired) => fallSensitivityLevelsInput(desired),
    timeRanges: (entry, desired) => timeRangesInput(entry, desired),
    timeRange: (_entry, desired) => timeRangeInput(desired),
    wonlexSleepSettings: (_entry, desired) => wonlexSleepSettingsInput(desired),
    wonlexReminderThreshold: (entry, desired) =>
        wonlexReminderThresholdInput(entry, desired),
    wonlexHeartRateRange: (_entry, desired) => wonlexHeartRateRangeInput(desired),
    list: (entry, desired) => listInput(entry, desired, "numbers", entry.label || "Lista"),
    sos_contacts: (entry, desired) => sosContactsInput(entry, desired),
    call_whitelist: (entry, desired) => callWhitelistInput(entry, desired),
    contacts: (entry, desired) => contactsInput(entry, desired),
    alarm_clock: (_entry, desired, meta) => alarmClockInput(desired, meta),
    alarms: (_entry, desired, meta) => alarmsInput(desired, meta),
    takePills: (_entry, desired, meta) => takePillsInput(desired, meta),
    soundProfile: (_entry, desired) => soundProfileInput(desired),
};

const FOUR_P_TOUCH_GROUPED_CAPABILITIES = {
    sos_contacts: {
        label: "Contactos SOS",
        limit: 3,
    },
    call_whitelist: {
        label: "Chamadas permitidas",
        limit: 10,
    },
};

const CONFIG_INPUT_READERS = {
    toggle: (section) => {
        const field = firstFieldName(section);
        return { [field]: readCheckbox(section, field) };
    },
    fallSensitivity: (section) => ({sensitivity: readNumber(section, "sensitivity")}),
    number: (section) => {
        const field = firstFieldName(section);
        return { [field]: readNumber(section, field) };
    },
    phone: (section) => {
        const field = firstFieldName(section);
        return { [field]: readPhone(section, field) };
    },
    text: (section) => {
        const field = firstFieldName(section);
        return { [field]: readText(section, field) };
    },
    pushMessage: (section) => ({message: readText(section, "message")}),
    makeCall: (section) => ({phone: readPhone(section, "phone")}),
    resetAction: () => ({}),
    requestAction: () => ({}),
    intervalToggle: (section) => ({
        enabled: readCheckbox(section, "enabled"),
        intervalMinutes: readNumber(section, "intervalMinutes"),
    }),
    intervalHoursToggle: (section) => ({
        enabled: readCheckbox(section, "enabled"),
        intervalHours: readNumber(section, "intervalHours"),
    }),
    workingMode: (section) => {
        const mode = readNumber(section, "mode");
        const payload = {mode};
        if (mode === 8) {
            payload.intervalSeconds = readNumber(section, "intervalSeconds");
            payload.gpsEnabled = readCheckbox(section, "gpsEnabled");
        }
        return payload;
    },
    bloodPressure: (section) => ({
        systolic: readNumber(section, "systolic"),
        diastolic: readNumber(section, "diastolic"),
    }),
    wonlexBloodPressureWarning: (section) => {
        const valueField = section.querySelector(
            '[data-config-field="RemindValue"]',
        )
            ? "RemindValue"
            : "reminderValue";
        return {
            switchState: readCheckbox(section, "switchState"),
            [valueField]: readNumber(section, valueField),
        };
    },
    languageTimezone: (section) => {
        const value = readText(section, "preset");
        const [language, timeZone] = value.split("|", 2);
        return {
            language: parseInt(language, 10),
            timeZone: String(timeZone || "0"),
        };
    },
    dualToggle: (section) => ({
        enabled: readCheckbox(section, "enabled"),
        callCenterOnFall: readCheckbox(section, "callCenterOnFall"),
    }),
    fallSensitivityLevels: (section) => ({
        sensitivity: readNumber(section, "sensitivity"),
        levels: readNumber(section, "levels"),
    }),
    timeRanges: (section) => ({ranges: readTextArray(section, "ranges")}),
    timeRange: (section) => ({range: readText(section, "range")}),
    wonlexSleepSettings: (section) => ({
        switchState: readCheckbox(section, "switchState"),
        sleepStartTime: readText(section, "sleepStartTime"),
        sleepEndTime: readText(section, "sleepEndTime"),
        sleepTarget: readNumber(section, "sleepTarget"),
    }),
    wonlexReminderThreshold: (section) => {
        const valueField = section.querySelector(
            '[data-config-field="RemindValue"]',
        )
            ? "RemindValue"
            : "reminderValue";
        return {
            switchState: readCheckbox(section, "switchState"),
            [valueField]: readNumber(section, valueField),
        };
    },
    wonlexHeartRateRange: (section) => ({
        switchState: readCheckbox(section, "switchState"),
        remindValue: readNumber(section, "remindValue"),
        exerciseSwitchState: readCheckbox(section, "exerciseSwitchState"),
        exerciseHRMin: readNumber(section, "exerciseHRMin"),
        exerciseHRMax: readNumber(section, "exerciseHRMax"),
        exerciseRemindValue: readNumber(section, "exerciseRemindValue"),
    }),
    list: (section) => {
        const limit = parseInt(section.dataset.configLimit || "3", 10) || 3;
        return {numbers: readPhoneArray(section, "numbers").slice(0, limit)};
    },
    sos_contacts: (section) => {
        const limit = parseInt(section.dataset.configLimit || "3", 10) || 3;
        return {numbers: readPhoneArray(section, "numbers").slice(0, limit)};
    },
    call_whitelist: (section) => {
        const limit = parseInt(section.dataset.configLimit || "10", 10) || 10;
        return {numbers: readPhoneArray(section, "numbers").slice(0, limit)};
    },
    contacts: (section) => ({contacts: readContacts(section)}),
    alarm_clock: (section) => readAlarmClock(section),
    alarms: (section) => ({alarms: readFourPTouchAlarms(section)}),
    takePills: (section) => readTakePills(section),
    soundProfile: (section) => ({mode: readNumber(section, "mode")}),
};

const CONFIG_INPUT_DEFAULTS = {
    toggle: (entry) => ({[entry.fields?.[0] || "value"]: true}),
    fallSensitivity: () => ({sensitivity: 2}),
    number: (entry) => ({[entry.fields?.[0] || "value"]: 0}),
    phone: (entry) => ({[entry.fields?.[0] || "value"]: ""}),
    text: (entry) => ({[entry.fields?.[0] || "value"]: ""}),
    intervalToggle: () => ({enabled: true, intervalMinutes: 60}),
    intervalHoursToggle: () => ({enabled: true, intervalHours: 2}),
    workingMode: () => ({mode: 1}),
    bloodPressure: () => ({systolic: 120, diastolic: 80}),
    wonlexBloodPressureWarning: () => ({switchState: true, hpWarn: 135, LPWarn: 90}),
    languageTimezone: () => ({preset: "0|0"}),
    dualToggle: () => ({enabled: true, callCenterOnFall: false}),
    fallSensitivityLevels: () => ({sensitivity: 5, levels: 8}),
    timeRanges: () => ({ranges: ["08:10-09:30"]}),
    timeRange: () => ({range: "21:10-07:30"}),
    wonlexSleepSettings: () => ({
        switchState: true,
        sleepStartTime: "220000",
        sleepEndTime: "100000",
        sleepTarget: 480,
    }),
    wonlexReminderThreshold: () => ({switchState: true, reminderValue: 90}),
    wonlexHeartRateRange: () => ({
        switchState: true,
        remindValue: 120,
        exerciseSwitchState: true,
        exerciseHRMin: 100,
        exerciseHRMax: 140,
        exerciseRemindValue: 140,
    }),
    list: () => ({numbers: ["", "", ""]}),
    sos_contacts: () => ({numbers: ["", "", ""]}),
    call_whitelist: () => ({numbers: ["", "", "", "", "", "", "", "", "", ""]}),
    contacts: () => ({contacts: [{name: "", phone: ""}]}),
    alarm_clock: () => ({items: []}),
    alarms: () => ({alarms: []}),
    takePills: () => ({
        reminderSettings: [
            {time: "08:00", enabled: true, frequency: 1, custom: ""},
            {time: "09:00", enabled: true, frequency: 1, custom: ""},
            {time: "10:00", enabled: true, frequency: 1, custom: ""},
        ],
        number: 1,
        reminderText: "",
        voiceData: "",
        voiceMimeType: "audio/webm",
    }),
    soundProfile: () => ({mode: 1}),
};

const CONFIG_INPUT_HELP = {
    list: (entry) => (entry.limit || 0) > 0 ? `limite ${entry.limit}` : "",
    contacts: (entry) => (entry.limit || 0) > 0 ? `limite ${entry.limit}` : "",
    alarm_clock: () => "Até 3 alarmes com recorrência e tipo, quando suportado.",
    alarms: () => "até 3 alarmes",
    requestAction: () => "sem parâmetros",
    soundProfile: () => "4 modos",
    whitelistSwitch: () => "ativa os contactos da lista telefónica do BP14",
    sos_contacts: () => "",
    call_whitelist: () => "",
};

const CONFIG_INPUT_LABEL = {
    requestAction: "Ação",
    soundProfile: "Perfil de som",
    alarm_clock: "Alarmes",
    sos_contacts: "Contactos SOS",
    call_whitelist: "Lista branca",
};

export async function catalogForProtocol(protocol) {
    if (!protocol) {
        return [];
    }

    if (state.protocolCatalogs[protocol]) {
        return state.protocolCatalogs[protocol];
    }

    if (protocolCatalogRequests[protocol]) {
        return protocolCatalogRequests[protocol];
    }

    protocolCatalogRequests[protocol] = (async () => {
        const response = await requestJson(
            `/api/protocols/${encodeURIComponent(protocol)}/config-catalog`,
        );
        const catalog = Array.isArray(response.data) ? response.data : [];
        state.protocolCatalogs[protocol] = catalog;
        return catalog;
    })().finally(() => {
        delete protocolCatalogRequests[protocol];
    });

    return protocolCatalogRequests[protocol];
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

function normalizedCatalogForProtocol(protocol, catalog) {
    if (protocol !== "four-p-touch") {
        return catalog.map((entry) => normalizeConfigEntry(entry));
    }

    const grouped = new Map();
    const normalized = [];

    for (const entry of catalog) {
        const nativeKey = String(entry.key || "");
        const normalizedEntry = normalizeConfigEntry(entry);
        const capabilityKey = normalizedEntry.capabilityKey || "";
        const groupedCapability = FOUR_P_TOUCH_GROUPED_CAPABILITIES[capabilityKey] || null;
        const label = groupedCapability?.label || "";

        if (label === "") {
            normalized.push(normalizedEntry);
            continue;
        }

        if (!grouped.has(capabilityKey)) {
            grouped.set(capabilityKey, {
                ...normalizedEntry,
                key: capabilityKey,
                capabilityKey,
                label,
                input: capabilityKey,
                category: normalizedEntry.category || "contacts",
                limit: groupedCapability?.limit || 0,
                transient: false,
                configKind: "capability",
                configSection: "contacts",
                configKeys: [],
            });
            normalized.push(grouped.get(capabilityKey));
        }

        const groupedEntry = grouped.get(capabilityKey);
        groupedEntry.configKeys.push(nativeKey);
        groupedEntry.command = groupedEntry.configKeys.join(" · ");
    }

    return normalized;
}

export function renderDeviceConfigurationRoot(context) {
    const {
        protocol,
        catalog,
        configurations = {},
        capabilities = {},
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
    const normalizedCatalog = normalizedCatalogForProtocol(protocol, catalog);
    const groups = groupedCatalog(normalizedCatalog);
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
                        ${group.entries.map((entry) => {
                            const row = resolveConfigRow(entry, rowsByKey);
                            const stored = resolveConfigStored(entry, rowsByKey);
                            const uiState = uiByKey[entry.key] || null;
                            return renderConfigSection(protocol, entry, row, capabilities, disabled, uiState, stored);
                        }).join("")}
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
    capabilities = {},
    disabled = false,
    uiState = null,
    stored = null,
) {
    const capability = capabilityForEntry(entry, capabilities);
    const desired = normalizeDesired(entry, row, capability ? extractCapabilityValue(capability) : null);
    const meta = capability?._meta || {};
    const help = configHelp(entry);
    const isStored = stored ?? (row !== null && Object.keys(row).length > 0);
    const hideNativeCommand = entry.configKind === "capability" && entry.key === "alarm_clock";
    const details = [
        hideNativeCommand ? "" : (entry.command || ""),
        hideNativeCommand ? "" : configInputLabel(entry.input || "json"),
        help || "",
    ].filter((part) => part !== "");

    return `
        <section class="border rounded-3 p-3 mb-3 bg-body-tertiary" data-config-section data-config-kind="${esc(entry.configKind || "configuration")}" data-config-key="${esc(entry.key)}" data-capability-key="${esc(entry.capabilityKey || entry.key)}"${entry.configSection ? ` data-config-section-name="${esc(entry.configSection)}"` : ""} data-config-input="${esc(entry.input || "json")}" data-config-protocol="${esc(protocol)}" data-config-limit="${esc(String(entry.limit ?? ""))}"${entry.transient ? ' data-config-transient="1"' : ""}>
            <div>
                <div>
                    <div class="fw-semibold">
                        ${esc(entry.label || entry.key)}
                        ${isStored
                            ? '<span class="badge text-bg-success ms-2">Configurado</span>'
                            : '<span class="badge text-bg-warning ms-2">Padrão</span>'}
                    </div>
                    ${details.length > 0 ? `<div class="small text-secondary">${details.map((part) => esc(part)).join(" · ")}</div>` : ""}
                </div>
            </div>
            <form class="mt-3" data-config-form data-config-key="${esc(entry.key)}" ${disabled ? 'data-config-disabled="1"' : ""}>
                ${renderConfigInputs(entry, desired, {...meta, protocol})}
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
    const isDisabled =
        disabled || ["submitting", "sent", "queued", "waiting"].includes(state);
    const meta = CONFIG_ACTION_BUTTON_META[state] || CONFIG_ACTION_BUTTON_META.idle;

    return `
        <button type="button" class="btn ${meta.className} btn-sm" data-action="saveConfig" data-config-key="${esc(key)}" ${isDisabled ? "disabled" : ""}>
            <i class="fa-solid ${meta.icon} me-2"></i>${state === "idle" ? esc(idleLabel) : esc(meta.label)}
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

export function renderConfigInputs(entry, desired, meta = {}) {
    const input = entry.input || "json";
    return CONFIG_INPUT_RENDERERS[input]?.(entry, desired, meta) || jsonInput(desired);
}

export function readConfigPayload(section) {
    const input = section.dataset.configInput || "json";
    return CONFIG_INPUT_READERS[input]?.(section) || readJson(section);
}

export function defaultConfigPayload(entry) {
    const input = entry.input || "json";
    return CONFIG_INPUT_DEFAULTS[input]?.(entry) || {};
}

function normalizeDesired(entry, desired, capabilityDesired = null) {
    const effectiveDesired = desired ?? capabilityDesired;
    if (effectiveDesired && Object.keys(effectiveDesired).length) {
        return effectiveDesired;
    }
    return defaultConfigPayload(entry);
}

function normalizeConfigEntry(entry) {
    const capabilityKey = String(entry.capabilityKey || "");
    const key = capabilityKey || String(entry.key || "");
    const input = capabilityKey === "alarm_clock"
        ? "alarm_clock"
        : String(entry.input || "json");
    const label = capabilityKey === "alarm_clock"
        ? "Alarmes"
        : String(entry.label || key || "");
    const configKind = capabilityKey === "alarm_clock"
        ? "capability"
        : String(entry.configKind || "configuration");

    return {
        ...entry,
        key,
        input,
        label,
        capabilityKey: capabilityKey || key,
        configKind,
        configSectionName: capabilityKey === "alarm_clock" ? "alarms" : entry.configSectionName,
    };
}

function resolveConfigRow(entry, rowsByKey) {
    if (Array.isArray(entry.configKeys) && entry.configKeys.length > 0) {
        return null;
    }

    return rowsByKey[entry.key] || null;
}

function resolveConfigStored(entry, rowsByKey) {
    if (Array.isArray(entry.configKeys) && entry.configKeys.length > 0) {
        return entry.configKeys.some((key) => Object.keys(rowsByKey[key] || {}).length > 0);
    }

    return Object.keys(rowsByKey[entry.key] || {}).length > 0;
}

function emptyConfigurationState(text) {
    return `<div class="text-secondary border rounded bg-body-tertiary p-3">${esc(text)}</div>`;
}

function configHelp(entry) {
    const input = entry.input || "json";
    const key = entry.key || "";
    if (CONFIG_INPUT_HELP[input]) {
        return CONFIG_INPUT_HELP[input](entry);
    }
    return CONFIG_INPUT_HELP[key]?.(entry) || "";
}

function configInputLabel(input) {
    return CONFIG_INPUT_LABEL[input] || titleize(input);
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
    const isFourPTouchPhonebook =
        String(section.dataset.configProtocol || "") === "four-p-touch" &&
        String(section.dataset.configKey || "") === "phonebook";
    const contacts = [];
    let sawIncompleteRow = false;

    for (const row of section.querySelectorAll('[data-repeat-row="contacts"]')) {
        const name = readContactName(row, isFourPTouchPhonebook);
        const phone = readContactPhone(row, isFourPTouchPhonebook);
        if (name === "" && phone === "") {
            continue;
        }
        if (name === "" || phone === "") {
            sawIncompleteRow = true;
            if (!isFourPTouchPhonebook) {
                throw new Error("Nome e telefone são obrigatórios");
            }
            continue;
        }

        contacts.push({name, phone});
    }

    if (isFourPTouchPhonebook && contacts.length === 0 && sawIncompleteRow) {
        throw new Error("Nome e telefone são obrigatórios");
    }

    return contacts;
}

function readContactName(row, isFourPTouchPhonebook) {
    const input = row.querySelector('[data-repeat-field="name"]');
    const value = String(input?.value || "").trim();
    if (isFourPTouchPhonebook && unicodeLength(value) > 10) {
        throw new Error("O nome deve ter no máximo 10 caracteres");
    }

    return value;
}

function readContactPhone(row, isFourPTouchPhonebook) {
    if (!isFourPTouchPhonebook) {
        return normalizePhoneControl(
            row.querySelector('[data-phone-control][data-repeat-field="phone"]'),
        );
    }

    const control = row.querySelector('[data-fourptouch-phonebook-control]');
    const input = control?.querySelector('[data-fourptouch-phonebook-phone]');
    const value = String(input?.value || "").trim();
    clearFourPTouchPhonebookError(control);
    if (value === "") {
        return "";
    }
    if (value.length > 20) {
        setFourPTouchPhonebookError(
            control,
            "O telefone deve ter no máximo 20 caracteres ASCII",
        );
        throw new Error("O telefone deve ter no máximo 20 caracteres ASCII");
    }
    if (!/^[\x00-\x7F]+$/.test(value)) {
        setFourPTouchPhonebookError(
            control,
            "O telefone deve conter apenas caracteres ASCII",
        );
        throw new Error("O telefone deve conter apenas caracteres ASCII");
    }

    return value;
}

function renderFourPTouchPhonebookPhoneControl({value = "", placeholder = "Telefone"} = {}) {
    return `
        <div class="vstack gap-1" data-fourptouch-phonebook-control>
            <input class="form-control" type="text" inputmode="text" autocomplete="off" maxlength="20" data-repeat-field="phone" data-fourptouch-phonebook-phone placeholder="${esc(placeholder)}" value="${esc(value)}">
            <div class="invalid-feedback d-none" data-fourptouch-phonebook-feedback></div>
        </div>`;
}

function setFourPTouchPhonebookError(control, message) {
    if (!control) {
        return;
    }
    const input = control.querySelector("[data-fourptouch-phonebook-phone]");
    const feedback = control.querySelector("[data-fourptouch-phonebook-feedback]");
    if (input) {
        input.classList.add("is-invalid");
    }
    if (feedback) {
        feedback.textContent = message;
        feedback.classList.remove("d-none");
    }
}

function clearFourPTouchPhonebookError(control) {
    if (!control) {
        return;
    }
    const input = control.querySelector("[data-fourptouch-phonebook-phone]");
    const feedback = control.querySelector("[data-fourptouch-phonebook-feedback]");
    if (input) {
        input.classList.remove("is-invalid");
    }
    if (feedback) {
        feedback.textContent = "";
        feedback.classList.add("d-none");
    }
}

function unicodeLength(value) {
    return Array.from(String(value || "")).length;
}

function readAlarmClock(section) {
    const items = Array.from(
        section.querySelectorAll('[data-repeat-row="alarm_clock"]'),
    )
        .map((row) => {
            const recurrenceKind = normalizeAlarmClockRecurrenceKind(
                row.querySelector('[data-alarm-clock-field="recurrenceKind"]:checked')?.value || "once",
            );
            const item = {
                time: String(
                    row.querySelector('[data-alarm-clock-field="time"]')?.value || "",
                ).trim(),
                enabled:
                    row.querySelector('[data-alarm-clock-field="enabled"]')?.checked ||
                    false,
                recurrence: {kind: recurrenceKind},
            };

            const typeField = row.querySelector('[data-alarm-clock-field="type"]:checked');
            if (typeField) {
                const type = parseInt(String(typeField.value || "1"), 10);
                if (Number.isFinite(type)) {
                    item.type = type;
                }
            }

            if (item.recurrence.kind === "custom") {
                const days = readAlarmClockDays(row);
                item.recurrence.days = days;
                item.days = days;
                item.custom = days.map((day) => String(day)).join("");
                if (!Array.isArray(days) || days.length === 0) {
                    throw new Error("Selecione pelo menos um dia para a recorrência personalizada");
                }
            }

            return item;
        })
        .filter((item) => item.time !== "");

    return {items};
}

function readTakePills(section) {
    const groups = Array.from(
        section.querySelectorAll("[data-takepills-reminder-group]"),
    );
    const number = readNumber(section, "number");
    const voiceEnabled = readCheckbox(section, "voiceEnabled");
    const voiceData = readText(section, "voiceData");
    const voiceMimeType = readText(section, "voiceMimeType");

    const reminderSettings = groups
        .slice(0, number)
        .map((group) => {
            const frequency =
                parseInt(
                    String(
                        group.querySelector(
                            '[data-takepills-field="reminderFrequency"]',
                        )?.value ?? "1",
                    ),
                    10,
                ) || 1;
            return {
                time:
                    group.querySelector(
                        '[data-takepills-field="reminderTime"]',
                    )?.value || "",
                enabled:
                    group.querySelector(
                        '[data-takepills-field="reminderEnabled"]',
                    )?.checked || false,
                frequency,
                custom:
                    frequency === 3
                        ? group.querySelector(
                              '[data-takepills-field="reminderCustom"]',
                          )?.value || ""
                        : "",
            };
        });

    const payload = {
        reminderSettings,
        number,
        reminderText: readText(section, "reminderText"),
    };

    if (voiceEnabled) {
        payload.voiceData = voiceData;
        if (voiceMimeType !== "") {
            payload.voiceMimeType = voiceMimeType;
        }
    }

    return payload;
}

function readFourPTouchAlarms(section) {
    return Array.from(section.querySelectorAll("[data-fourptouch-alarm-row]"))
        .map((row) => {
            const mode = parseInt(
                String(row.querySelector('[data-fourptouch-field="mode"]')?.value || "1"),
                10,
            ) || 1;
            const alarm = {
                time: formatFourPTouchAlarmTime(
                    row.querySelector('[data-fourptouch-field="time"]')?.value || "",
                ),
                enabled:
                    row.querySelector('[data-fourptouch-field="enabled"]')?.checked ||
                    false,
                mode,
                custom: mode === 3 ? readFourPTouchAlarmDays(row) : "",
            };

            if (mode === 3 && alarm.custom === "0000000") {
                throw new Error("Selecione pelo menos um dia para o alarme personalizado");
            }

            return alarm;
        })
        .filter((alarm) => alarm.time !== "");
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

function makeCallInput(entry, desired) {
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

function resetActionInput(_entry, _desired) {
    return `
        <div>
            <div class="alert alert-warning mb-3 py-2 px-3 small">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                Esta ação é enviada imediatamente para o dispositivo e não pode ser desfeita.
            </div>
        </div>`;
}

function requestActionInput(entry) {
    return `
        <div>
            <div class="alert alert-info mb-3 py-2 px-3 small">
                <i class="fa-solid fa-circle-info me-2"></i>
                ${esc(entry.label || "Ação")} é enviada sem parâmetros adicionais.
            </div>
        </div>`;
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

function soundProfileInput(desired) {
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

const languageTimezonePresetOptions = [
    { language: 0, timeZone: "0", label: "English (UTC+0)" },
    { language: 1, timeZone: "8", label: "简体中文 (UTC+8)" },
    { language: 3, timeZone: "0", label: "Português (UTC+0)" },
    { language: 4, timeZone: "1", label: "Español (UTC+1)" },
    { language: 5, timeZone: "1", label: "Deutsch (UTC+1)" },
    { language: 10, timeZone: "1", label: "Français (UTC+1)" },
];

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
        parseInt(String(desired.sensitivity ?? 5), 10) || 5;
    const totalLevels = parseInt(String(desired.levels ?? 8), 10) || 8;

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
                <label class="form-label form-label-sm">Níveis totais</label>
                <select class="form-select" data-config-field="levels" data-action="fallTotalLevels">
                    <option value="6" ${totalLevels === 6 ? "selected" : ""}>6 níveis</option>
                    <option value="8" ${totalLevels === 8 ? "selected" : ""}>8 níveis</option>
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

function sosContactsInput(entry, desired) {
    return phoneRepeaterInput(entry, desired, {
        kind: "sos_contacts",
        limit: Math.max(1, parseInt(String(entry.limit ?? 3), 10) || 3),
        label: "Contactos SOS",
        addAction: "addSosContactRow",
        removeAction: "removeSosContactRow",
        emptyLabel: "Adicionar contacto SOS",
        placeholderPrefix: "SOS",
        helpText: "Até 3 números. A ordem define a posição nos comandos SOS do dispositivo.",
    });
}

function callWhitelistInput(entry, desired) {
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

function contactsInput(entry, desired, meta = {}) {
    const limit = Math.max(1, parseInt(String(entry.limit ?? 10), 10) || 10);
    const contacts = Array.isArray(desired.contacts) ? desired.contacts : [];
    const rows = contacts.length ? contacts.slice(0, limit) : [{}];
    const isFourPTouchPhonebook =
        String(meta.protocol || "") === "four-p-touch" &&
        String(entry.key || "") === "phonebook";
    const nameMaxLength = isFourPTouchPhonebook ? ' maxlength="10"' : "";
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
                            <input class="form-control" type="text" placeholder="Nome ${index + 1}" data-repeat-field="name"${nameMaxLength} value="${esc(String(contact.name || ""))}">
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2">
                                <div class="flex-grow-1">
                                    ${isFourPTouchPhonebook
                                        ? renderFourPTouchPhonebookPhoneControl({
                                            value: String(contact.phone || ""),
                                            placeholder: `Telefone ${index + 1}`,
                                        })
                                        : renderPhoneControl({
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
            ${isFourPTouchPhonebook ? '<div class="small text-secondary mt-2">4P Touch: até 5 contactos. Nome até 10 caracteres e telefone ASCII até 20 caracteres.</div>' : ""}
        </div>`;
}

function phoneRepeaterInput(entry, desired, options) {
    const limit = Math.max(1, parseInt(String(options.limit ?? entry.limit ?? 3), 10) || 3);
    const values = Array.isArray(desired.numbers) ? desired.numbers : [];
    const rows = values.length ? values.slice(0, limit) : [""];
    const kind = String(options.kind || "numbers");
    const addAction = String(options.addAction || "addPhoneRow");
    const removeAction = String(options.removeAction || "removePhoneRow");
    const label = String(options.label || entry.label || "Lista");
    const helpText = String(options.helpText || "");
    const emptyLabel = String(options.emptyLabel || "Adicionar");
    const placeholderPrefix = String(options.placeholderPrefix || label);

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

function alarmClockInput(desired, meta = {}) {
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
    if (items.length === 0) {
        items.push(defaultAlarmClockItem(typeOptions.length > 0));
    }

    return `
        <div class="vstack gap-3">
            <div class="small text-secondary">Até ${esc(String(limit))} alarmes. A recorrência personalizada usa dias de Segunda a Domingo.</div>
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addAlarmClockRow">Adicionar item</button>
            </div>
            <div class="vstack gap-2" data-alarm-clock-list>
                ${items.slice(0, limit).map((item) => alarmClockRow(item, typeOptions, recurrenceOptions)).join("")}
            </div>
        </div>`;
}

function alarmsInput(desired, meta = {}) {
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

function takePillsInput(desired, meta = {}) {
    const reminderSettingsList = normalizeTakePillsReminderSettings(desired);
    const reminderNumber = parseInt(String(desired.number ?? 1), 10) || 1;
    const reminderText = String(desired.reminderText || "");
    const voiceData = String(desired.voiceData || "");
    const voiceMimeType = String(desired.voiceMimeType || "audio/webm");
    const hasVoiceData = voiceData.trim() !== "";
    const voiceEnabled = normalizeTakePillsVoiceEnabled(desired, hasVoiceData);
    const previewSrc = takePillsVoicePreviewSrc(voiceData, voiceMimeType);
    const frequencyOptions = takePillsFrequencyOptions(meta);
    const numberLimit = Math.max(1, parseInt(String(meta.limit ?? 3), 10) || 3);

    while (reminderSettingsList.length < numberLimit) {
        reminderSettingsList.push({
            time: "08:00",
            enabled: true,
            frequency: 1,
            custom: "",
        });
    }

    return `
        <div class="vstack gap-3">
            <div class="vstack gap-2" data-takepills-reminders-list>
                ${reminderSettingsList
                    .map(
                        (rs, index) => takePillsReminderGroup(rs, index, frequencyOptions, index >= reminderNumber),
                    )
                    .join("")}
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label form-label-sm">N\u00famero</label>
                    <input class="form-control" type="number" min="1" max="${esc(String(numberLimit))}" step="1" data-config-field="number" value="${esc(String(reminderNumber))}">
                    <div class="form-text">M\u00e1ximo de ${esc(String(numberLimit))} lembretes.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label form-label-sm">Texto do lembrete</label>
                    <input class="form-control" type="text" data-config-field="reminderText" value="${esc(reminderText)}">
                </div>
            </div>
            <div class="vstack gap-2" data-takepills-audio>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" data-config-field="voiceEnabled" ${voiceEnabled ? "checked" : ""}>
                    <label class="form-check-label" data-switch-label data-switch-on="\u00c1udio ligado" data-switch-off="\u00c1udio desligado">${voiceEnabled ? "\u00c1udio ligado" : "\u00c1udio desligado"}</label>
                </div>
                <fieldset class="vstack gap-2" data-takepills-audio-controls ${voiceEnabled ? "" : "disabled"}>
                <input type="hidden" data-config-field="voiceData" value="${esc(voiceData)}">
                <input type="hidden" data-config-field="voiceMimeType" value="${esc(voiceMimeType)}">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" data-action="takePillsRecord">
                        <i class="fa-solid fa-microphone me-2"></i>Gravar
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm d-none" data-action="takePillsStop">
                        <i class="fa-solid fa-stop me-2"></i>Parar
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" data-action="takePillsClear">
                        <i class="fa-solid fa-trash-can me-2"></i>Limpar
                    </button>
                    <label class="btn btn-outline-secondary btn-sm mb-0">
                        <i class="fa-solid fa-file-audio me-2"></i>Carregar
                        <input type="file" class="d-none" accept="audio/*" data-action="takePillsFile">
                    </label>
                    <span class="small text-secondary" data-takepills-status>
                        ${voiceEnabled ? (hasVoiceData ? "\u00c1udio carregado" : "Sem \u00e1udio") : "\u00c1udio desligado"}
                    </span>
                </div>
                <audio class="w-100" controls preload="none" data-takepills-preview ${hasVoiceData ? `src="${esc(previewSrc)}"` : ""}></audio>
                </fieldset>
            </div>
        </div>`;
}

function fourPTouchAlarmRow(alarm, index) {
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

function normalizeFourPTouchAlarms(desired) {
    const base = desired?.items ?? desired?.alarms ?? desired?.alarmClock ?? desired?.fields ?? desired;

    if (Array.isArray(base)) {
        if (base.length && typeof base[0] === "string") {
            return base.map((item) => normalizeFourPTouchAlarmItem(item));
        }

        return base.map((item) => normalizeFourPTouchAlarmItem(item));
    }

    if (typeof base === "string" && base.trim() !== "") {
        return base.split(",").map((item) => normalizeFourPTouchAlarmItem(item));
    }

    if (base && typeof base === "object") {
        return [normalizeFourPTouchAlarmItem(base)];
    }

    return [];
}

function normalizeFourPTouchAlarmItem(item) {
    if (typeof item === "string") {
        return parseFourPTouchAlarmString(item);
    }

    if (!item || typeof item !== "object") {
        return { time: "", enabled: true, mode: 1, custom: "" };
    }

    const recurrenceKind = String(item.recurrence?.kind ?? item.kind ?? "").trim().toLowerCase();
    const mode = recurrenceKind
        ? ({ once: 1, daily: 2, custom: 3 }[recurrenceKind] || 1)
        : (parseInt(
            String(item.mode ?? item.frequency ?? item.reminderFrequency ?? 1),
            10,
        ) || 1);

    return {
        time: formatFourPTouchAlarmTime(
            item.time ?? item.alarmTime ?? item.reminderTime ?? "",
        ),
        enabled: boolValue(item.enabled ?? item.switchState, true),
        mode: [1, 2, 3].includes(mode) ? mode : 1,
        custom:
            mode === 3
                ? normalizeFourPTouchAlarmDays(
                      item.recurrence?.days ?? item.custom ?? item.days ?? item.reminderCustom ?? "",
                  )
                : "",
    };
}

function parseFourPTouchAlarmString(value) {
    const parts = String(value || "").trim().split("-");
    if (parts.length < 3) {
        return { time: "", enabled: true, mode: 1, custom: "" };
    }

    const mode = parseInt(String(parts[2] || "1"), 10) || 1;

    return {
        time: formatFourPTouchAlarmTime(parts[0]),
        enabled: boolValue(parts[1], true),
        mode: [1, 2, 3].includes(mode) ? mode : 1,
        custom:
            mode === 3
                ? normalizeFourPTouchAlarmDays(parts.slice(3).join("-"))
                : "",
    };
}

function readFourPTouchAlarmDays(row) {
    const selected = new Set(
        Array.from(row.querySelectorAll('[data-fourptouch-day="customDays"]:checked'))
            .map((input) => String(input.value || ""))
            .filter(Boolean),
    );

    return ["0", "1", "2", "3", "4", "5", "6"]
        .map((day) => (selected.has(day) ? "1" : "0"))
        .join("");
}

function normalizeFourPTouchAlarmDays(value) {
    const raw = String(value || "").trim();
    if (raw === "") {
        return "";
    }

    if (/^[01]{7}$/.test(raw)) {
        return raw;
    }

    const selected = new Set(raw.replace(/[^0-6]/g, "").split(""));
    return ["0", "1", "2", "3", "4", "5", "6"]
        .map((day) => (selected.has(day) ? "1" : "0"))
        .join("");
}

function isFourPTouchAlarmDaySelected(mask, day) {
    const normalizedMask = String(mask || "").trim();
    const index = parseInt(String(day || ""), 10);
    if (!Number.isFinite(index) || index < 0 || index > 6) {
        return false;
    }

    if (/^[01]{7}$/.test(normalizedMask)) {
        return normalizedMask.charAt(index) === "1";
    }

    return false;
}

function formatFourPTouchAlarmTime(value) {
    const raw = String(value || "").trim();
    if (raw === "") {
        return "";
    }

    const hhmm = raw.replace(/[^0-9]/g, "");
    if (hhmm.length === 4) {
        return `${hhmm.slice(0, 2)}:${hhmm.slice(2, 4)}`;
    }

    if (/^\d{1,2}:\d{2}$/.test(raw)) {
        const [hour, minute] = raw.split(":");
        return `${String(parseInt(hour, 10)).padStart(2, "0")}:${String(parseInt(minute, 10)).padStart(2, "0")}`;
    }

    return raw;
}

function takePillsReminderGroup(settings, index, frequencyOptions, hidden) {
    const freqValue = parseInt(String(settings.frequency ?? 1), 10) || 1;
    const customVisible = freqValue === 3;
    return `
        <div class="border rounded p-3 bg-body ${hidden ? "d-none" : ""}" data-takepills-reminder-group="${index}">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Hora</label>
                    <input class="form-control" type="text" inputmode="numeric" maxlength="5" pattern="[0-9]{2}:[0-9]{2}" placeholder="HH:MM" data-time-format="24h" data-takepills-field="reminderTime" data-takepills-index="${index}" value="${esc(settings.time)}">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm d-block">Estado</label>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" role="switch" data-takepills-field="reminderEnabled" data-takepills-index="${index}" ${settings.enabled ? "checked" : ""}>
                        <label class="form-check-label" data-switch-label data-switch-on="Ligado" data-switch-off="Desligado">${settings.enabled ? "Ligado" : "Desligado"}</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Frequ\u00eancia</label>
                    <select class="form-select" data-takepills-field="reminderFrequency" data-takepills-index="${index}" data-takepills-frequency>
                        ${frequencyOptions
                            .map(
                                (option) => `
                            <option value="${esc(String(option.value))}" ${parseInt(String(option.value), 10) === freqValue ? "selected" : ""}>${esc(String(option.label))}</option>
                        `,
                            )
                            .join("")}
                    </select>
                </div>
                <div class="col-md-4 ${customVisible ? "" : "d-none"}" data-takepills-custom-wrapper="${index}">
                    <label class="form-label form-label-sm">Custom</label>
                    <input class="form-control" type="text" data-takepills-field="reminderCustom" data-takepills-index="${index}" value="${esc(settings.custom)}">
                </div>
            </div>
        </div>`;
}

function normalizeTakePillsVoiceEnabled(desired, hasVoiceData) {
    if (typeof desired?.voiceEnabled === "boolean") {
        return desired.voiceEnabled;
    }
    if (typeof desired?.voiceEnabled === "string") {
        return boolValue(desired.voiceEnabled, hasVoiceData);
    }
    return hasVoiceData;
}

function normalizeTakePillsReminderSettings(desired) {
    const base = desired?.reminderSettings;

    if (Array.isArray(base)) {
        return base.map((item) => normalizeSingleTakePillsReminder(item));
    }

    if (typeof base === "string" && base.trim() !== "") {
        return parseTakePillsReminderString(base);
    }

    if (base && typeof base === "object" && !Array.isArray(base)) {
        return [normalizeSingleTakePillsReminder(base)];
    }

    return [
        {
            time: String(desired?.reminderTime ?? "08:00"),
            enabled: boolValue(desired?.reminderEnabled ?? desired?.enabled ?? true, true),
            frequency:
                parseInt(String(desired?.reminderFrequency ?? desired?.frequency ?? 1), 10) || 1,
            custom: String(desired?.reminderCustom ?? desired?.custom ?? ""),
        },
    ];
}

function normalizeSingleTakePillsReminder(item) {
    if (typeof item === "string") {
        const parts = item.split("-");
        return {
            time: String(parts[0] ?? "08:00"),
            enabled: boolValue(parts[1] ?? true, true),
            frequency: parseInt(String(parts[2] ?? 1), 10) || 1,
            custom: String(parts.slice(3).join("-") ?? ""),
        };
    }
    return {
        time: String(item.time ?? item.reminderTime ?? "08:00"),
        enabled: boolValue(item.enabled ?? item.switchState, true),
        frequency: parseInt(String(item.frequency ?? item.frequencies ?? 1), 10) || 1,
        custom: String(item.custom ?? item.reminderCustom ?? ""),
    };
}

function parseTakePillsReminderString(str) {
    const parts = str.split("-");
    const reminders = [];
    let i = 0;
    while (i < parts.length) {
        const time = parts[i++] ?? "08:00";
        const enabled = boolValue(parts[i++] ?? true, true);
        const frequency = parseInt(parts[i++] ?? "1", 10) || 1;
        let custom = "";
        if (frequency === 3 && i < parts.length) {
            custom = parts[i++];
        }
        reminders.push({ time, enabled, frequency, custom });
    }
    return reminders;
}

function takePillsVoicePreviewSrc(voiceData, voiceMimeType) {
    const value = String(voiceData || "").trim();
    if (value === "") {
        return "";
    }
    if (value.startsWith("data:")) {
        return value;
    }
    const mimeType = String(voiceMimeType || "audio/webm").trim() || "audio/webm";
    return `data:${mimeType};base64,${value}`;
}

function takePillsFrequencyOptions(meta) {
    const options = meta?.frequency?.options;
    if (Array.isArray(options) && options.length) {
        return options;
    }

    return [
        { value: 1, label: "Uma vez" },
        { value: 2, label: "Diariamente" },
        { value: 3, label: "Personalizado" },
    ];
}

function capabilityForEntry(entry, capabilities) {
    const key = entry.capabilityKey || entry.key;
    if (!key) {
        return null;
    }

    const sectionKey = entry.category === "alerts" ? "alarms" : (entry.category || "");
    const section = capabilities?.[sectionKey];
    if (!section || typeof section !== "object") {
        return null;
    }

    const capability = section[key];
    if (!capability || typeof capability !== "object") {
        return null;
    }

    return capability;
}

function extractCapabilityValue(value) {
    if (
        value &&
        typeof value === "object" &&
        !Array.isArray(value) &&
        Object.prototype.hasOwnProperty.call(value, "value")
    ) {
        return value.value;
    }

    return value;
}

function alarmClockRow(item = {}, typeOptions = [], recurrenceOptions = []) {
    const rowId = nextUid("alarm-clock");
    const recurrenceKind = normalizeAlarmClockRecurrenceKind(
        item.recurrence?.kind ?? item.kind ?? "once",
    );
    const recurrenceValue = recurrenceKind;
    const dayMask = normalizeAlarmClockDaySelection(
        item.recurrence?.days ?? item.days ?? item.custom ?? "",
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
                <div class="col-sm-6 col-lg-${hasTypeSelector ? "1" : "3"}">
                    <label class="form-label form-label-sm">Hora</label>
                    <input class="form-control" type="text" inputmode="numeric" maxlength="5" pattern="[0-9]{2}:[0-9]{2}" placeholder="HH:MM" data-time-format="24h" data-alarm-clock-field="time" value="${esc(formatReminderTime(item.time))}">
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
                    <label class="form-label form-label-sm">Recorrência</label>
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
                    <label class="form-label form-label-sm d-block">Dias personalizados</label>
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
            </div>
        </div>`;
}

function normalizeAlarmClockItems(desired) {
    const base = desired?.items ?? desired?.alarmClock ?? desired?.alarms ?? [];
    const items = Array.isArray(base) ? base : [base];
    return items
        .filter((item) => item && typeof item === "object")
        .map((item) => normalizeAlarmClockItem(item));
}

function normalizeAlarmClockItem(item) {
    const recurrenceKind = normalizeAlarmClockRecurrenceKind(
        item.recurrence?.kind ?? item.kind ?? "once",
    );

    return {
        time: String(item.time ?? item.alarmTime ?? item.reminderTime ?? ""),
        enabled: boolValue(item.enabled ?? item.switchState, true),
        type: item.type === undefined || item.type === null
            ? undefined
            : (parseInt(String(item.type), 10) || 1),
        recurrence: recurrenceKind === "custom"
            ? {
                  kind: "custom",
                  days: normalizeAlarmClockDaySelection(item.recurrence?.days ?? item.days ?? item.custom ?? ""),
              }
            : { kind: recurrenceKind },
    };
}

function defaultAlarmClockItem(withType = false) {
    return withType
        ? {
              time: "",
              enabled: true,
              type: 1,
              recurrence: {kind: "once"},
          }
        : {
              time: "",
              enabled: true,
              recurrence: {kind: "once"},
          };
}

function normalizeAlarmClockRecurrenceKind(value) {
    const raw = String(value || "").trim().toLowerCase();
    if (raw === "daily" || raw === "2") {
        return "daily";
    }
    if (raw === "custom" || raw === "3") {
        return "custom";
    }
    if (raw === "once" || raw === "1" || raw === "") {
        return "once";
    }
    return "once";
}

function normalizeAlarmClockDaySelection(value) {
    if (Array.isArray(value)) {
        return value
            .map((day) => parseInt(String(day), 10))
            .filter((day) => Number.isFinite(day) && day >= 1 && day <= 7)
            .map((day) => String(day));
    }

    const raw = String(value || "").trim();
    if (raw === "") {
        return [];
    }

    if (/^[1-7]+$/.test(raw)) {
        return raw.split("").filter(Boolean);
    }

    if (/^[01]{7}$/.test(raw)) {
        const days = [];
        raw.split("").forEach((bit, index) => {
            if (bit === "1") {
                days.push(String(index === 0 ? 7 : index));
            }
        });
        return days;
    }

    return raw
        .replace(/[^1-7]/g, "")
        .split("")
        .filter(Boolean);
}

function readAlarmClockDays(row) {
    return Array.from(
        row.querySelectorAll('[data-alarm-clock-day="customDays"]:checked'),
    )
        .map((input) => parseInt(String(input.value || ""), 10))
        .filter((day) => Number.isFinite(day) && day >= 1 && day <= 7);
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
