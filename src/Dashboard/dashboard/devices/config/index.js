import { esc, titleize } from "../../format.js";
import { emptyPanel } from "../../widgets.js";
import {takePillsInput, takePillsReminderGroup} from "./four-p-touch-take-pills.js";
import {
    defaultWonlexMedicationPlan,
    numericValue,
    WONLEX_MEDICATION_PERIODS,
} from "./normalizers.js";
import {
    alarmClockInput,
    alarmsInput,
    bloodPressureInput,
    callWhitelistInput,
    contactsInput,
    dualToggleInput,
    fallSensitivityInput,
    fallSensitivityLevelsInput,
    intervalHoursToggleInput,
    intervalToggleInput,
    languageTimezoneInput,
    listInput,
    makeCallInput,
    numberInput,
    phoneInput,
    pushMessageInput,
    requestActionInput,
    resetActionInput,
    sosContactsInput,
    soundProfileInput,
    textInput,
    timeRangeInput,
    timeRangesInput,
    toggleInput,
    wonlexBloodPressureWarningInput,
    wonlexHeartRateRangeInput,
    wonlexMedicationPlansInput,
    wonlexReminderThresholdInput,
    wonlexSleepSettingsInput,
    workingModeInput,
} from "./inputs.js";
import {
    firstFieldName,
    readAlarmClock,
    readCheckbox,
    readContacts,
    readFourPTouchAlarms,
    readJson,
    readNumber,
    readPhone,
    readPhoneArray,
    readTakePills,
    readText,
    readTextArray,
    readUniquePhoneArray,
    jsonInput,
} from "./readers.js";
import {
    catalogForProtocol,
    protocolFieldConstraints,
    protocolGroupedCapabilities,
} from "./protocol-catalog.js";

export {takePillsReminderGroup, catalogForProtocol};

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

const CONFIG_SECTION_ORDER = [
    "health",
    "contacts",
    "alarms",
    "settings_system",
];

const CONFIGURATION_DELIVERY_META = {
    pending_delivery: {
        label: "Em envio",
        tone: "warning",
        message: "O valor está guardado no Hub e aguarda entrega ao dispositivo.",
    },
    awaiting_ack: {
        label: "A aguardar",
        tone: "warning",
        message: "O valor foi enviado e aguarda resposta do dispositivo.",
    },
    confirmation_unavailable: {
        label: "Não verificável",
        tone: "warning",
        message: "O dispositivo confirmou a receção, mas este comando não permite verificar o valor efetivo.",
    },
    confirmed: {
        label: "Aplicado",
        tone: "success",
        message: "",
    },
    waiting_device: {
        label: "A aguardar",
        tone: "warning",
        message: "O valor está guardado no Hub e aguarda confirmação do dispositivo.",
    },
    failed: {
        label: "Falhou",
        tone: "danger",
        message: "O último valor está guardado no Hub, mas não foi aplicado pelo dispositivo.",
    },
    never_reported: {
        label: "Não confirmado",
        tone: "warning",
        message: "O valor está guardado no Hub, mas nunca foi confirmado pelo dispositivo.",
    },
    diverged: {
        label: "Divergente",
        tone: "danger",
        message: "O dispositivo reportou um valor diferente do valor guardado no Hub.",
    },
    applied: {
        label: "Aplicado",
        tone: "success",
        message: "",
    },
};

const CONFIGURATION_FAILURE_LABELS = {
    retry_exhausted: "Foram esgotadas todas as tentativas de envio.",
    response_timeout: "O dispositivo não respondeu dentro do tempo esperado.",
    delivery_failed: "Não foi possível entregar o comando ao dispositivo.",
    dropped: "O comando foi descartado antes de ser entregue.",
    failed: "O dispositivo não confirmou a aplicação do valor.",
};

const CONFIG_INPUT_RENDERERS = {
    toggle: (entry, desired, meta) => toggleInput(entry, desired, meta?.protocol),
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
    sos_contacts: (entry, desired, meta) => sosContactsInput(entry, desired, meta),
    call_whitelist: (entry, desired, meta) => callWhitelistInput(entry, desired, meta),
    whitelist_enabled: (entry, desired) =>
        toggleInput({...entry, fields: ["enabled"]}, desired),
    phonebook: (entry, desired, meta) => contactsInput(entry, desired, meta),
    contacts: (entry, desired, meta) => contactsInput(entry, desired, meta),
    alarm_clock: (_entry, desired, meta) => alarmClockInput(desired, meta),
    alarms: (_entry, desired, meta) => alarmsInput(desired, meta),
    takePills: (_entry, desired, meta) => takePillsInput(desired, meta),
    wonlexMedicationPlans: (_entry, desired) => wonlexMedicationPlansInput(desired),
    soundProfile: (_entry, desired) => soundProfileInput(desired),
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
    wonlexBloodPressureWarning: (section) => ({
        // The form offers a systolic and a diastolic threshold, which is what
        // the Wonlex BPEarlyWarning config carries. Reading a single reminder
        // value here would drop both of them.
        enabled: readCheckbox(section, "enabled"),
        hpWarn: readNumber(section, "hpWarn"),
        LPWarn: readNumber(section, "LPWarn"),
    }),
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
    fallSensitivityLevels: (section) => {
        const levels = readNumber(section, "levels");
        if (![6, 8].includes(levels)) {
            throw new Error("Selecione a escala de sensibilidade suportada pelo firmware (6 ou 8 níveis).");
        }
        return {
            sensitivity: readNumber(section, "sensitivity"),
            levels,
        };
    },
    timeRanges: (section) => ({ranges: readTextArray(section, "ranges")}),
    timeRange: (section) => ({range: readText(section, "range")}),
    wonlexSleepSettings: (section) => ({
        enabled: readCheckbox(section, "enabled"),
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
            enabled: readCheckbox(section, "enabled"),
            [valueField]: readNumber(section, valueField),
        };
    },
    wonlexHeartRateRange: (section) => ({
        enabled: readCheckbox(section, "enabled"),
        remindValue: readNumber(section, "remindValue"),
        exerciseEnabled: readCheckbox(section, "exerciseEnabled"),
        exerciseHRMin: readNumber(section, "exerciseHRMin"),
        exerciseHRMax: readNumber(section, "exerciseHRMax"),
        exerciseRemindValue: readNumber(section, "exerciseRemindValue"),
    }),
    list: (section) => {
        const limit = parseInt(section.dataset.configLimit || "3", 10) || 3;
        return {numbers: readPhoneArray(section, "numbers").slice(0, limit)};
    },
    sos_contacts: (section) => {
        const selector = section.querySelectorAll("[data-sos-contact-phone]");
        if (selector.length > 0) {
            return Array.from(selector)
                .filter((input) => input.checked)
                .map((input) => String(input.value || "").trim())
                .filter(Boolean);
        }
        const limit = parseInt(section.dataset.configLimit || "3", 10) || 3;
        return readUniquePhoneArray(section, "numbers", "Contactos SOS").slice(0, limit);
    },
    call_whitelist: (section) => {
        const limit = parseInt(section.dataset.configLimit || "10", 10) || 10;
        if ((section.dataset.configProtocol || "") === "vivistar-iw") {
            return {contacts: readContacts(section).slice(0, limit)};
        }
        return readUniquePhoneArray(section, "numbers", "Lista branca").slice(0, limit);
    },
    whitelist_enabled: (section) => ({
        enabled: readCheckbox(section, "enabled"),
    }),
    phonebook: (section) => ({contacts: readContacts(section)}),
    contacts: (section) => ({contacts: readContacts(section)}),
    alarm_clock: (section) => readAlarmClock(section),
    alarms: (section) => ({alarms: readFourPTouchAlarms(section)}),
    takePills: (section) => readTakePills(section),
    wonlexMedicationPlans: (section) => readWonlexMedicationPlans(section),
    soundProfile: (section) => ({mode: readNumber(section, "mode")}),
};

const CONFIG_INPUT_DEFAULTS = {
    toggle: (entry, protocol) => ({
        [protocol === "wonlex-json" && entry.fields?.[0] === "switchState"
            ? "enabled"
            : entry.fields?.[0] || "value"]: true,
    }),
    fallSensitivity: () => ({sensitivity: 2}),
    number: (entry) => ({[entry.fields?.[0] || "value"]: 0}),
    phone: (entry) => ({[entry.fields?.[0] || "value"]: ""}),
    text: (entry) => ({[entry.fields?.[0] || "value"]: ""}),
    intervalToggle: () => ({enabled: true, intervalMinutes: 60}),
    intervalHoursToggle: () => ({enabled: true, intervalHours: 2}),
    workingMode: () => ({mode: 1}),
    bloodPressure: () => ({systolic: 120, diastolic: 80}),
    wonlexBloodPressureWarning: () => ({enabled: true, hpWarn: 135, LPWarn: 90}),
    languageTimezone: () => ({preset: "0|0"}),
    dualToggle: () => ({enabled: true, callCenterOnFall: false}),
    fallSensitivityLevels: () => ({sensitivity: 5, levels: 8}),
    timeRanges: () => ({ranges: ["08:10-09:30"]}),
    timeRange: () => ({range: "21:10-07:30"}),
    wonlexSleepSettings: () => ({
        enabled: true,
        sleepStartTime: "220000",
        sleepEndTime: "100000",
        sleepTarget: 480,
    }),
    wonlexReminderThreshold: () => ({enabled: true, reminderValue: 90}),
    wonlexHeartRateRange: () => ({
        enabled: true,
        remindValue: 120,
        exerciseEnabled: true,
        exerciseHRMin: 100,
        exerciseHRMax: 140,
        exerciseRemindValue: 140,
    }),
    list: () => ({numbers: ["", "", ""]}),
    sos_contacts: () => [],
    call_whitelist: (entry, protocol) => protocol === "vivistar-iw"
        ? {contacts: [{name: "", phone: ""}]}
        : ["", "", "", "", "", "", "", "", "", ""],
    whitelist_enabled: () => ({enabled: true}),
    phonebook: () => ({contacts: []}),
    contacts: () => ({contacts: [{name: "", phone: ""}]}),
    alarm_clock: () => ({items: []}),
    alarms: () => ({alarms: []}),
    takePills: () => ({
        reminderSettings: [],
        number: 0,
        reminderText: "",
        voiceData: "",
        voiceMimeType: "audio/webm",
    }),
    wonlexMedicationPlans: () => ({plans: [defaultWonlexMedicationPlan()]}),
    soundProfile: () => ({mode: 1}),
};

const CONFIG_INPUT_HELP = {
    list: (entry) => (entry.limit || 0) > 0 ? `limite ${entry.limit}` : "",
    contacts: (entry) => (entry.limit || 0) > 0 ? `limite ${entry.limit}` : "",
    alarm_clock: () => "Até 3 alarmes com recorrência e tipo, quando suportado.",
    alarms: () => "até 3 alarmes",
    requestAction: () => "sem parâmetros",
    soundProfile: () => "4 modos",
    whitelist_enabled: () => "ativa ou desativa a lista branca",
    phonebook: (entry) => (entry.limit || 0) > 0 ? `limite ${entry.limit}` : "",
    sos_contacts: () => "",
    call_whitelist: () => "",
    wonlexMedicationPlans: () => "Formulário guiado para medicamento, dose, período e horários.",
};

const CONFIG_INPUT_LABEL = {
    requestAction: "Ação",
    soundProfile: "Perfil de som",
    alarm_clock: "Alarmes",
    sos_contacts: "Contactos SOS",
    call_whitelist: "Lista branca",
    whitelist_enabled: "Lista branca ativa",
    phonebook: "Lista telefónica",
    wonlexMedicationPlans: "Plano de medicação",
};

function groupedCatalog(catalog) {
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

function normalizedCatalogForProtocol(protocol, catalog, capabilityCatalog) {
    const groupedCapabilities = protocolGroupedCapabilities(protocol);
    if (Object.keys(groupedCapabilities).length === 0) {
        return catalog
            .map((entry) => normalizeConfigEntry(entry))
            .map((entry) => assignCapabilitySection(entry, capabilityCatalog))
            .filter(Boolean);
    }

    const grouped = new Map();
    const normalized = [];

    for (const entry of catalog) {
        const nativeKey = String(entry.key || "");
        const normalizedEntry = normalizeConfigEntry(entry);
        const capabilityKey = normalizedEntry.capabilityKey || "";
        const groupedCapability = groupedCapabilities[capabilityKey] || null;
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
                configSectionName: "contacts",
                configKeys: [],
            });
            normalized.push(grouped.get(capabilityKey));
        }

        const groupedEntry = grouped.get(capabilityKey);
        groupedEntry.configKeys.push(nativeKey);
        groupedEntry.command = groupedEntry.configKeys.join(" · ");
    }

    return normalized
        .map((entry) => assignCapabilitySection(entry, capabilityCatalog))
        .filter(Boolean);
}

function assignCapabilitySection(entry, capabilityCatalog) {
    const capabilityKey = String(entry.capabilityKey || entry.key || "");
    const definition = capabilityDefinitionForKey(
        capabilityCatalog,
        capabilityKey,
    );
    const section = String(definition?.section || "");
    if (
        (!definition?.isConfigurable && !definition?.isRequestable)
        || !CONFIG_SECTION_ORDER.includes(section)
    ) {
        return null;
    }

    return {
        ...entry,
        category: section,
        configSectionName: section,
        sectionLabel: String(definition.sectionLabel || section),
        requestOnly: definition.isRequestable && !definition.isConfigurable,
    };
}

function capabilityDefinitionForKey(capabilityCatalog, capabilityKey) {
    if (capabilityKey === "") {
        return null;
    }

    return (capabilityCatalog || []).find(
        (definition) => String(definition?.key || "") === capabilityKey,
    ) || null;
}

export function renderDeviceConfigurationRoot(context) {
    const {
        protocol,
        catalog,
        configurations = {},
        capabilities = {},
        capabilityCatalog = [],
        configurationSync = {entries: {}},
        supplier = "",
        model = "",
        disabled = false,
        activeCategory = "",
        uiByKey = {},
        quietWhenEmpty = false,
    } = context;
    if (!protocol) {
        return emptyPanel(
            "Selecione fornecedor e modelo para ver as configurações.",
        );
    }

    if (!catalog.length) {
        // Calado quando quem nos chama ja mostrou uma configuracao decidida no hub:
        // "este protocolo não tem configurações suportadas" e verdade sobre downlinks
        // e mentira sobre o ecra, que tem uma configuracao logo acima.
        return quietWhenEmpty
            ? ""
            : emptyPanel("Este protocolo não tem configurações suportadas.");
    }

    const rowsByKey = configurations;
    const normalizedCatalog = normalizedCatalogForProtocol(
        protocol,
        catalog,
        capabilityCatalog,
    );
    const groups = groupedCatalog(normalizedCatalog);
    groups.sort((a, b) => {
        const ai = CONFIG_SECTION_ORDER.indexOf(a.key);
        const bi = CONFIG_SECTION_ORDER.indexOf(b.key);
        if (ai !== bi) {
            return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi);
        }
        return a.key.localeCompare(b.key);
    });
    const currentCategory = groups.some((group) => group.key === activeCategory)
        ? activeCategory
        : groups[0]?.key || "";
    for (const group of groups) {
        group.label = group.entries[0]?.sectionLabel || titleize(group.key);
    }

    return `
        <div class="vstack gap-3">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="fw-semibold">Configurações do dispositivo</div>
                    <div class="small text-secondary">${supplier || model ? `${esc(supplier)} ${esc(model)}` : ""}</div>
                </div>
            </div>
            <div class="nav nav-underline flex-wrap gap-3" role="tablist">
                ${groups
                    .map(
                        (group) => `
                    <button type="button" class="nav-link d-inline-flex align-items-center gap-2 ${group.key === currentCategory ? "active" : ""}" data-config-category="${esc(group.key)}">
                        ${esc(group.label)}
                        <span class="badge rounded-pill text-bg-secondary">${group.entries.length}</span>
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
                            const row = entry.requestOnly
                                ? null
                                : resolveConfigRow(entry, rowsByKey);
                            const stored = entry.requestOnly
                                ? null
                                : resolveConfigStored(entry, rowsByKey);
                            const delivery = entry.requestOnly
                                ? null
                                : resolveConfigDelivery(entry, configurationSync);
                            const uiState = uiByKey[entry.key] || null;
                            return renderConfigSection(
                                protocol,
                                entry,
                                row,
                                capabilities,
                                disabled,
                                uiState,
                                stored,
                                delivery,
                                rowsByKey,
                            );
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
    delivery = null,
    relatedConfigurations = {},
) {
    const capability = capabilityForEntry(entry, capabilities);
    const desired = normalizeDesired(entry, row, capability ? extractCapabilityValue(capability) : null, protocol);
    const meta = {
        ...(capability?._meta || {}),
        protocol,
        phonebookContacts: relatedConfigurations.phonebook || [],
    };
    const help = configHelp(entry);
    const isStored = stored ?? (row !== null && Object.keys(row).length > 0);
    const showConfigurationBadge = !entry.requestOnly;
    const deliveryMeta = configurationDeliveryMeta(isStored, delivery);
    const hideNativeCommand = entry.configKind === "capability" && entry.key === "alarm_clock";
    const configSectionName = entry.configSectionName || entry.configSection || "";
    const phonebookConstraints = protocolFieldConstraints(protocol).phonebook || {};
    const isPhonebookLike = String(entry.key || "") === "phonebook" || String(entry.key || "") === "call_whitelist";
    const phonebookNameMaxLength = isPhonebookLike
        ? parseInt(String(meta.name?.maxLength ?? phonebookConstraints.name?.maxLength ?? 0), 10) || 0
        : 0;
    const phonebookPhoneMaxLength = isPhonebookLike
        ? parseInt(String(meta.phone?.maxLength ?? phonebookConstraints.phone?.maxLength ?? 0), 10) || 0
        : 0;
    const phonebookMetaAttrs = isPhonebookLike
        ? `${phonebookNameMaxLength > 0 ? ` data-phonebook-name-max-length="${esc(String(phonebookNameMaxLength))}"` : ""}${phonebookPhoneMaxLength > 0 ? ` data-phonebook-phone-max-length="${esc(String(phonebookPhoneMaxLength))}"` : ""}`
        : "";
    const details = [
        hideNativeCommand ? "" : (entry.command || ""),
        hideNativeCommand ? "" : configInputLabel(entry.input || "json"),
        help || "",
    ].filter((part) => part !== "");

    return `
        <section class="border rounded-3 p-3 mb-3" data-config-section data-config-kind="${esc(entry.configKind || "configuration")}" data-config-key="${esc(entry.key)}" data-capability-key="${esc(entry.capabilityKey || entry.key)}"${configSectionName !== "" ? ` data-config-section-name="${esc(configSectionName)}"` : ""}${phonebookMetaAttrs} data-config-input="${esc(entry.input || "json")}" data-config-protocol="${esc(protocol)}" data-config-limit="${esc(String(entry.limit ?? ""))}"${entry.transient ? ' data-config-transient="1"' : ""}>
            <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
                <div>
                    <div class="fw-semibold">${esc(entry.label || entry.key)}</div>
                    ${details.length > 0 ? `<div class="small text-secondary">${details.map((part) => esc(part)).join(" · ")}</div>` : ""}
                </div>
                ${showConfigurationBadge
                    ? `<span class="config-state config-state-${esc(deliveryMeta.tone)}">
                        <span class="config-state-dot"></span>${esc(deliveryMeta.label)}
                       </span>`
                    : ""}
            </div>
            ${renderConfigurationDeliveryNotice(deliveryMeta, delivery)}
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
    const idleLabel = ["pushMessage", "push_message"].includes(key) ? "Enviar mensagem" : "Enviar";
    const isDisabled =
        disabled || ["submitting", "sent", "queued", "waiting"].includes(state);
    const meta = CONFIG_ACTION_BUTTON_META[state] || CONFIG_ACTION_BUTTON_META.idle;

    return `
        <button type="button" class="btn ${meta.className} btn-sm" data-action="saveConfig" data-config-key="${esc(key)}" data-config-phase="${esc(state)}" ${isDisabled ? "disabled" : ""}>
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

export function defaultConfigPayload(entry, protocol = "") {
    const input = entry.input || "json";
    return CONFIG_INPUT_DEFAULTS[input]?.(entry, protocol) || {};
}

function normalizeDesired(entry, desired, capabilityDesired = null, protocol = "") {
    const effectiveDesired = desired ?? capabilityDesired;
    if (effectiveDesired && Object.keys(effectiveDesired).length) {
        return extractCapabilityValue(effectiveDesired);
    }
    return defaultConfigPayload(entry, protocol);
}

function normalizeConfigEntry(entry) {
    const capabilityKey = String(entry.capabilityKey || "");
    const key = capabilityKey || String(entry.key || "");
    const genericInputs = new Set([
        "alarm_clock",
        "phonebook",
        "sos_contacts",
        "call_whitelist",
        "whitelist_enabled",
    ]);
    const input = genericInputs.has(capabilityKey)
        ? capabilityKey
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
    return rowsByKey[entry.key] || null;
}

function resolveConfigStored(entry, rowsByKey) {
    if (Object.keys(rowsByKey[entry.key] || {}).length > 0) {
        return true;
    }
    if (Array.isArray(entry.configKeys) && entry.configKeys.length > 0) {
        return entry.configKeys.some((key) => Object.keys(rowsByKey[key] || {}).length > 0);
    }

    return Object.keys(rowsByKey[entry.key] || {}).length > 0;
}

function resolveConfigDelivery(entry, configurationSync) {
    const key = String(entry.capabilityKey || entry.key || "");
    if (key === "") {
        return null;
    }

    for (const section of Object.values(configurationSync?.entries || {})) {
        if (
            section
            && typeof section === "object"
            && section[key]
            && typeof section[key] === "object"
        ) {
            return section[key];
        }
    }

    return null;
}

function configurationDeliveryMeta(isStored, delivery) {
    if (!isStored) {
        return {
            label: "Padrão",
            tone: "secondary",
            message: "",
        };
    }

    const status = String(delivery?.status || "applied");
    return CONFIGURATION_DELIVERY_META[status]
        || CONFIGURATION_DELIVERY_META.failed;
}

function renderConfigurationDeliveryNotice(meta, delivery) {
    if (!meta.message) {
        return "";
    }

    const error = String(delivery?.error || "");
    const errorMessage = CONFIGURATION_FAILURE_LABELS[error] || "";
    return `
        <div class="alert alert-${esc(meta.tone)} small py-2 px-3 mt-3 mb-0" role="status">
            <i class="fa-solid fa-circle-info me-2"></i>${esc(meta.message)}
            ${errorMessage ? `<span class="d-block mt-1">${esc(errorMessage)}</span>` : ""}
        </div>`;
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

function readWonlexMedicationPlans(section) {
    const plans = Array.from(
        section.querySelectorAll('[data-repeat-row="wonlexMedicationPlan"]'),
    ).map((row, index) => {
        const value = (field) => String(
            row.querySelector(`[data-medication-field="${field}"]`)?.value || "",
        ).trim();
        const drugName = value("drugName");
        const start = value("drugStartTime");
        const end = value("drugEndTime");
        if (drugName === "") {
            throw new Error(`Medicamento ${index + 1}: indique o nome`);
        }
        if (start === "" || end === "") {
            throw new Error(`Medicamento ${index + 1}: indique as datas inicial e final`);
        }
        if (end < start) {
            throw new Error(`Medicamento ${index + 1}: a data final não pode ser anterior à inicial`);
        }

        const selected = Array.from(
            row.querySelectorAll("[data-medication-period]:checked"),
        ).map((input) => parseInt(String(input.value), 10));
        if (selected.length === 0) {
            throw new Error(`Medicamento ${index + 1}: selecione pelo menos um período`);
        }

        const alarmClock = {};
        for (const periodIndex of selected) {
            const period = WONLEX_MEDICATION_PERIODS.find(
                (candidate) => candidate.index === periodIndex,
            );
            const time = String(
                row.querySelector(`[data-medication-period-time="${periodIndex}"]`)?.value || "",
            ).trim();
            if (!period || time === "") {
                throw new Error(`Medicamento ${index + 1}: indique a hora de cada período selecionado`);
            }
            alarmClock[period.key] = time;
        }

        const dose = numericValue(value("drugDose"), 0);
        const interval = numericValue(value("drugInterval"), -1);
        if (dose < 0 || interval < 0) {
            throw new Error(`Medicamento ${index + 1}: dose e intervalo não podem ser negativos`);
        }

        return {
            drugType: parseInt(value("drugType"), 10) || 0,
            drugName,
            drugDose: dose,
            drugUnit: value("drugUnit") || "5",
            drugStartTime: start,
            drugEndTime: end,
            drugInterval: interval,
            drugTime: {
                alarmClock,
                checkboxes: selected,
                radio: parseInt(String(
                    row.querySelector('[data-medication-field="mealTiming"]:checked')?.value || "0",
                ), 10) === 1 ? 1 : 0,
            },
        };
    });

    return {plans};
}

 function capabilityForEntry(entry, capabilities) {
    const key = entry.capabilityKey || entry.key;
    if (!key) {
        return null;
    }

    const sectionKeys = capabilitySectionCandidates(entry);
    for (const sectionKey of sectionKeys) {
        const section = capabilities?.[sectionKey];
        if (!section || typeof section !== "object") {
            continue;
        }

        const capability = section[key];
        if (capability && typeof capability === "object") {
            return capability;
        }
    }

    for (const sectionKey of ["telemetry", "health", "contacts", "alarms", "settings_system"]) {
        if (sectionKeys.includes(sectionKey)) {
            continue;
        }
        const section = capabilities?.[sectionKey];
        if (!section || typeof section !== "object") {
            continue;
        }

        const capability = section[key];
        if (capability && typeof capability === "object") {
            return capability;
        }
    }

    return null;
}

function capabilitySectionCandidates(entry) {
    const category = String(entry.category || "");
    const configSection = String(entry.configSectionName || entry.configSection || "");
    const sections = [];

    if (configSection !== "") {
        sections.push(configSection);
    }

    if (category !== "") {
        sections.push(category);
    }

    return [...new Set(sections)];
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

