import { esc, titleize } from "../../format.js";
import { emptyPanel } from "../../widgets.js";
import { stateBadge } from "../../components/state-badge.js";
// Os mesmos cinco ícones do catálogo de capacidades: as secções são as mesmas, e um separador
// com outro ícone para a mesma secção lia-se como sendo outra coisa.
import { CAPABILITY_SECTION_ICONS } from "../../capability-catalog.js";
import { takePillsInput, takePillsReminderGroup } from "./four-p-touch-take-pills.js";
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
    diaperSensitivityInput,
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
    voiceMonitorInput,
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

export { takePillsReminderGroup, catalogForProtocol };

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
    sent: { icon: "fa-check", label: "Enviado", className: "btn-info" },
    queued: { icon: "fa-list-check", label: "Em fila", className: "btn-secondary" },
    waiting: { icon: "fa-hourglass-half", label: "À espera", className: "btn-warning" },
    acked: { icon: "fa-circle-check", label: "Confirmado", className: "btn-success" },
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
    diaperSensitivity: (_entry, desired, meta) => diaperSensitivityInput(desired, meta),
    number: numberInput,
    phone: phoneInput,
    text: textInput,
    pushMessage: pushMessageInput,
    makeCall: makeCallInput,
    voiceMonitor: voiceMonitorInput,
    resetAction: resetActionInput,
    requestAction: requestActionInput,
    intervalToggle: intervalToggleInput,
    intervalHoursToggle: (_entry, desired) => intervalHoursToggleInput(desired),
    workingMode: (_entry, desired) => workingModeInput(desired),
    bloodPressure: (_entry, desired) => bloodPressureInput(desired),
    wonlexBloodPressureWarning: (_entry, desired) =>
        wonlexBloodPressureWarningInput(desired),
    languageTimezone: (_entry, desired) => languageTimezoneInput(desired),
    dualToggle: (_entry, desired) => dualToggleInput(desired),
    fallSensitivityLevels: (_entry, desired) => fallSensitivityLevelsInput(desired),
    timeRanges: timeRangesInput,
    timeRange: (_entry, desired) => timeRangeInput(desired),
    wonlexSleepSettings: (_entry, desired) => wonlexSleepSettingsInput(desired),
    wonlexReminderThreshold: wonlexReminderThresholdInput,
    wonlexHeartRateRange: (_entry, desired) => wonlexHeartRateRangeInput(desired),
    list: (entry, desired) => listInput(entry, desired, "numbers", entry.label || "Lista"),
    sos_contacts: sosContactsInput,
    call_whitelist: callWhitelistInput,
    whitelist_enabled: (entry, desired) =>
        toggleInput({ ...entry, fields: ["enabled"] }, desired),
    phonebook: contactsInput,
    contacts: contactsInput,
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
    fallSensitivity: (section) => ({ sensitivity: readNumber(section, "sensitivity") }),
    diaperSensitivity: (section) => ({
        pollutionRange: readNumber(section, "pollutionRange"),
        pollutionValue: readNumber(section, "pollutionValue"),
    }),
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
    pushMessage: (section) => ({ message: readText(section, "message") }),
    makeCall: (section) => ({ phone: readPhone(section, "phone") }),
    voiceMonitor: (section) => ({ phone: readPhone(section, "phone") }),
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
        const payload = { mode };
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
        // O formulário oferece um limiar sistólico e um diastólico, que é o que a
        // configuração `BPEarlyWarning` da Wonlex leva: ler um valor só perdia os dois.
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
    timeRanges: (section) => ({ ranges: readTextArray(section, "ranges") }),
    timeRange: (section) => ({ range: readText(section, "range") }),
    wonlexSleepSettings: (section) => ({
        enabled: readCheckbox(section, "enabled"),
        sleepStartTime: readText(section, "sleepStartTime"),
        sleepEndTime: readText(section, "sleepEndTime"),
        sleepTarget: readNumber(section, "sleepTarget"),
    }),
    wonlexReminderThreshold: (section) => {
        const valueField = section.querySelector(
            "[data-config-field=\"RemindValue\"]",
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
        return { numbers: readPhoneArray(section, "numbers").slice(0, limit) };
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
            return { contacts: readContacts(section).slice(0, limit) };
        }
        return readUniquePhoneArray(section, "numbers", "Lista branca").slice(0, limit);
    },
    whitelist_enabled: (section) => ({
        enabled: readCheckbox(section, "enabled"),
    }),
    phonebook: (section) => ({ contacts: readContacts(section) }),
    contacts: (section) => ({ contacts: readContacts(section) }),
    alarm_clock: (section) => readAlarmClock(section),
    alarms: (section) => ({ alarms: readFourPTouchAlarms(section) }),
    takePills: (section) => readTakePills(section),
    wonlexMedicationPlans: (section) => readWonlexMedicationPlans(section),
    soundProfile: (section) => ({ mode: readNumber(section, "mode") }),
};

const CONFIG_INPUT_DEFAULTS = {
    toggle: (entry, protocol) => ({
        [protocol === "wonlex-json" && entry.fields?.[0] === "switchState"
            ? "enabled"
            : entry.fields?.[0] || "value"]: true,
    }),
    fallSensitivity: () => ({ sensitivity: 2 }),
    number: (entry) => ({ [entry.fields?.[0] || "value"]: 0 }),
    phone: (entry) => ({ [entry.fields?.[0] || "value"]: "" }),
    text: (entry) => ({ [entry.fields?.[0] || "value"]: "" }),
    intervalToggle: () => ({ enabled: true, intervalMinutes: 60 }),
    intervalHoursToggle: () => ({ enabled: true, intervalHours: 2 }),
    workingMode: () => ({ mode: 1 }),
    bloodPressure: () => ({ systolic: 120, diastolic: 80 }),
    wonlexBloodPressureWarning: () => ({ enabled: true, hpWarn: 135, LPWarn: 90 }),
    languageTimezone: () => ({ preset: "0|0" }),
    dualToggle: () => ({ enabled: true, callCenterOnFall: false }),
    fallSensitivityLevels: () => ({ sensitivity: 5, levels: 8 }),
    timeRanges: () => ({ ranges: ["08:10-09:30"] }),
    timeRange: () => ({ range: "21:10-07:30" }),
    wonlexSleepSettings: () => ({
        enabled: true,
        sleepStartTime: "220000",
        sleepEndTime: "100000",
        sleepTarget: 480,
    }),
    wonlexReminderThreshold: () => ({ enabled: true, reminderValue: 90 }),
    wonlexHeartRateRange: () => ({
        enabled: true,
        remindValue: 120,
        exerciseEnabled: true,
        exerciseHRMin: 100,
        exerciseHRMax: 140,
        exerciseRemindValue: 140,
    }),
    list: () => ({ numbers: ["", "", ""] }),
    sos_contacts: () => [],
    call_whitelist: (entry, protocol) => protocol === "vivistar-iw"
        ? { contacts: [{ name: "", phone: "" }] }
        : ["", "", "", "", "", "", "", "", "", ""],
    whitelist_enabled: () => ({ enabled: true }),
    phonebook: () => ({ contacts: [] }),
    contacts: () => ({ contacts: [{ name: "", phone: "" }] }),
    alarm_clock: () => ({ items: [] }),
    alarms: () => ({ alarms: [] }),
    takePills: () => ({
        reminderSettings: [],
        number: 0,
        reminderText: "",
        voiceData: "",
        voiceMimeType: "audio/webm",
    }),
    wonlexMedicationPlans: () => ({ plans: [defaultWonlexMedicationPlan()] }),
    soundProfile: () => ({ mode: 1 }),
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
        (!definition?.isConfigurable && !definition?.isRequestable) ||
        !CONFIG_SECTION_ORDER.includes(section)
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

/** O prazo na unidade em que é redondo: 300 são cinco minutos, e 90 são noventa segundos. */
function queueDeadline(seconds) {
    for (const [size, one, many] of [[3600, "1 hora", "horas"], [60, "1 minuto", "minutos"]]) {
        if (seconds % size !== 0) continue;
        const count = seconds / size;
        return count === 1 ? one : `${count} ${many}`;
    }
    return `${seconds} segundos`;
}

/**
 * O aviso de que o aparelho não está a ouvir.
 *
 * O painel apresentava os blocos e os «Enviar» de um aparelho desligado exactamente como os
 * de um ligado. O comando não se perde -- o `submitDownlink` mete-o em fila quando não há
 * ligação --, mas a fila tem prazo, e nada disso estava no ecrã.
 */
export function offlineQueueNotice(online, ttlSeconds) {
    if (online) return "";

    const seconds = Math.max(0, Number(ttlSeconds) || 0);
    const deadline = seconds > 0
        ? ` Ao fim de ${queueDeadline(seconds)} sem ligação, é descartado.`
        : "";

    return `Este dispositivo está desligado. O que enviar fica em fila e sai quando ele voltar.${deadline}`;
}

export function renderDeviceConfigurationRoot(context) {
    const {
        protocol,
        catalog,
        configurations = {},
        capabilities = {},
        capabilityCatalog = [],
        configurationSync = { entries: {} },
        supplier = "",
        model = "",
        disabled = false,
        activeCategory = "",
        uiByKey = {},
        actionDeliveries = {},
        quietWhenEmpty = false,
        online = true,
        queueTtlSeconds = 0,
    } = context;
    if (!protocol) {
        return emptyPanel(
            "Selecione fornecedor e modelo para ver as configurações.",
        );
    }

    if (!catalog.length) {
        // Calado quando quem chama já mostrou uma configuração decidida no hub: "este
        // protocolo não tem configurações suportadas" é verdade sobre downlinks e mentira
        // sobre o ecrã, que tem uma configuração logo acima.
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

    const offlineNotice = offlineQueueNotice(online, queueTtlSeconds);

    return `
        <div class="vstack gap-3">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="fw-semibold">Configurações do dispositivo</div>
                    <div class="small text-secondary">${supplier || model ? `${esc(supplier)} ${esc(model)}` : ""}</div>
                </div>
            </div>
            ${offlineNotice === ""
                ? ""
                : `
            <div class="alert alert-warning d-flex align-items-start gap-2 py-2 px-3 small mb-0" role="status">
                <i class="fa-solid fa-clock mt-1" aria-hidden="true"></i>
                <span>${esc(offlineNotice)}</span>
            </div>`}
            <div class="nav nav-underline flex-wrap gap-3" role="tablist">
                ${groups
                    .map(
                        (group) => `
                    <button type="button" class="nav-link d-inline-flex align-items-center gap-2 ${group.key === currentCategory ? "active" : ""}" data-config-category="${esc(group.key)}">
                        <i class="fa-solid ${esc(CAPABILITY_SECTION_ICONS[group.key] || "fa-gear")} fa-fw" aria-hidden="true"></i>
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
                        ${configRuns(group.entries).map((run) => {
                            if (run.grouped) {
                                return renderConfigToggleGroup(protocol, run.entries, {
                                    rowsByKey, capabilities, disabled, uiByKey, configurationSync,
                                });
                            }
                            return run.entries.map((entry) => {
                                const row = entry.requestOnly
                                    ? null
                                    : resolveConfigRow(entry, rowsByKey);
                                const stored = entry.requestOnly
                                    ? null
                                    : resolveConfigStored(entry, rowsByKey);
                                // Uma acção não tem entrada no `configurationSync` -- não é
                                // uma configuração guardada. O estado que ela tem é o do
                                // último pedido que disparou.
                                const delivery = entry.requestOnly
                                    ? actionDeliveries[entry.capabilityKey || entry.key] || null
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
                            }).join("");
                        }).join("")}
                    </div>
                `,
                    )
                    .join("")}
            </div>
        </div>`;
}

/**
 * Um interruptor que se guarda, e não uma acção nem um campo composto.
 *
 * É o único caso em que uma linha diz tudo o que há a dizer: um nome, o que faz, e ligado ou
 * desligado. Tudo o resto -- alarmes, agendas, listas, intervalos -- precisa do espaço do
 * cartão, e agrupá-lo espremia-o.
 */
function isPlainToggle(entry) {
    return entry.input === "toggle" &&
        entry.transient !== true &&
        entry.requestOnly !== true;
}

/**
 * As entradas por ordem, com as corridas de interruptores marcadas para agrupar.
 *
 * Corridas e não «todos os interruptores da secção»: a ordem do catálogo é editorial, e
 * juntar interruptores que estão separados por um alarme trocava-a por uma arrumação que o
 * autor do catálogo não pediu.
 *
 * Um interruptor sozinho também é uma corrida. Deixá-lo como cartão dava-lhe quatro linhas
 * de altura para um bit, que é o problema que isto existe para resolver -- e punha dois
 * desenhos diferentes na mesma lista, conforme a definição tivesse ou não vizinhas.
 *
 * @returns {Array<{grouped: boolean, entries: Array<object>}>}
 */
function configRuns(entries) {
    const runs = [];
    for (const entry of entries) {
        const grouped = isPlainToggle(entry);
        const last = runs[runs.length - 1];
        if (last && last.grouped === grouped) {
            last.entries.push(entry);
            continue;
        }
        runs.push({ grouped, entries: [entry] });
    }

    return runs;
}

function renderConfigToggleGroup(protocol, entries, ctx) {
    const { rowsByKey, capabilities, disabled, uiByKey, configurationSync } = ctx;

    const rows = entries.map((entry) => {
        const capability = capabilityForEntry(entry, capabilities);
        const desired = normalizeDesired(entry, resolveConfigRow(entry, rowsByKey), capability ? extractCapabilityValue(capability) : null, protocol);
        const field = entry.fields?.[0] || "enabled";
        const on = desired[field] !== false;
        const stored = resolveConfigStored(entry, rowsByKey);
        const delivery = resolveConfigDelivery(entry, configurationSync);
        const deliveryMeta = configurationDeliveryMeta(stored, delivery);
        const help = configHelp(entry);
        const uiState = uiByKey[entry.key] || null;
        const busy = uiState?.phase === "submitting";

        return `
            <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom" data-config-row
                 data-config-key="${esc(entry.key)}" data-capability-key="${esc(entry.capabilityKey || entry.key)}"
                 data-config-input="toggle" data-config-stored="${stored ? "1" : "0"}"
                 data-config-protocol="${esc(protocol)}"
                 data-config-pristine='${esc(JSON.stringify({ [field]: on }))}'>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold">${esc(entry.label || entry.key)}</div>
                    ${help ? `<div class="small text-secondary">${esc(help)}</div>` : ""}
                </div>
                ${stateBadge(deliveryMeta.label, deliveryMeta.tone)}
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                           data-config-field="${esc(field)}" ${on ? "checked" : ""}
                           ${disabled || busy ? "disabled" : ""}
                           aria-label="${esc(entry.label || entry.key)}">
                </div>
            </div>`;
    }).join("");

    return `
        <section class="border rounded-3 mb-3" data-config-group data-config-protocol="${esc(protocol)}">
            ${rows}
            <div class="d-flex align-items-center justify-content-between gap-3 px-3 py-2 bg-body-tertiary rounded-bottom-3">
                <span class="small text-secondary" data-config-group-status>Sem alterações por enviar</span>
                <span class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="resetConfigGroup" ${disabled ? "disabled" : ""}>Repor</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="saveConfigGroup" data-config-phase="idle" disabled>Enviar alterações</button>
                </span>
            </div>
        </section>`;
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
    // Uma acção com dois sentidos mostra os dois verbos em vez de um interruptor e um
    // «Enviar»: o botão passa a dizer o que vai acontecer, e parar deixa de ser uma
    // descoberta. Sem verbos declarados, o cartão fica como estava.
    const verbs = configActionVerbs(entry);
    const isStored = stored ?? (row !== null && Object.keys(row).length > 0);
    // Uma acção não tem valor guardado, mas o pedido que ela dispara tem estado: em fila, à
    // espera, confirmado ou falhado. Sem o mostrar, quem carrega no botão fica sem saber se a
    // ordem chegou sequer a sair do hub.
    const showConfigurationBadge = !entry.requestOnly || delivery !== null;
    // «Padrão» quer dizer que o hub ainda não guardou valor nenhum, e uma acção nunca guarda:
    // o que ela tem é o estado do último pedido, e é esse que a pastilha mostra.
    const deliveryMeta = configurationDeliveryMeta(isStored || (entry.requestOnly === true && delivery !== null), delivery);
    // Sem comando nativo, o hub aplica-a sozinho, e então não há vocabulário de protocolo
    // para mostrar: o comando não existe, e o tipo de campo sozinho é ruído.
    const hideNativeCommand = (entry.configKind === "capability" && entry.key === "alarm_clock") ||
        String(entry.command || "") === "";
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
    // O cartão diz o que a definição faz, e mais nada. O nome do comando e o tipo de campo
    // são vocabulário de protocolo: servem os registos e as ferramentas de diagnóstico, não
    // quem gere dispositivos, e estavam a ser a única coisa que se lia em cada cartão.
    const details = [help || ""].filter((part) => part !== "");

    return `
        <section class="border rounded-3 p-3 mb-3" data-config-section data-config-kind="${esc(entry.configKind || "configuration")}" data-config-stored="${isStored ? "1" : "0"}" data-config-key="${esc(entry.key)}" data-capability-key="${esc(entry.capabilityKey || entry.key)}"${configSectionName !== "" ? ` data-config-section-name="${esc(configSectionName)}"` : ""}${phonebookMetaAttrs} data-config-input="${esc(entry.input || "json")}"${verbs.length > 0 ? ` data-config-action-field="${esc(entry.fields?.[0] || "enabled")}"` : ""} data-config-protocol="${esc(protocol)}" data-config-limit="${esc(String(entry.limit ?? ""))}"${entry.transient ? " data-config-transient=\"1\"" : ""}>
            <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
                <div>
                    <div class="fw-semibold">${esc(entry.label || entry.key)}</div>
                    ${details.length > 0 ? `<div class="small text-secondary">${details.map((part) => esc(part)).join(" · ")}</div>` : ""}
                </div>
                ${showConfigurationBadge
                    ? stateBadge(deliveryMeta.label, deliveryMeta.tone)
                    : ""}
            </div>
            ${renderConfigurationDeliveryNotice(deliveryMeta, delivery)}
            <form class="mt-3" data-config-form data-config-key="${esc(entry.key)}" ${disabled ? "data-config-disabled=\"1\"" : ""}>
                ${verbs.length > 0 ? "" : renderConfigInputs(entry, desired, { ...meta, protocol })}
                <div class="d-flex justify-content-end gap-2 mt-3">
                    ${verbs.length > 0
                        ? renderConfigActionVerbs(verbs, disabled)
                        : `${renderConfigActionButton(entry.key, row, uiState, disabled, hideNativeCommand)}
                    <button type="reset" class="btn btn-outline-secondary btn-sm" title="Repor" aria-label="Repor" ${disabled ? "disabled" : ""}>
                        <i class="fa-solid fa-rotate-left"></i>
                    </button>`}
                </div>
            </form>
            ${renderConfigFeedback(entry.key, uiState)}
        </section>`;
}

/**
 * Os verbos de uma acção com dois sentidos, na ordem em que se lêem.
 *
 * Só para acções: uma definição guarda-se, e por isso o que ela precisa é do interruptor com
 * o estado desejado, não de dois botões que disparam.
 *
 * @returns {Array<{value: string, label: string}>}
 */
function configActionVerbs(entry) {
    if (entry.transient !== true) return [];

    const actions = entry.actions || null;
    if (!actions || typeof actions !== "object") return [];

    return ["on", "off"]
        .filter((value) => String(actions[value] || "").trim() !== "")
        .map((value) => ({ value, label: String(actions[value]).trim() }));
}

function renderConfigActionVerbs(verbs, disabled) {
    return verbs.map((verb, index) => `
        <button type="button" class="btn btn-sm ${index === 0 ? "btn-primary" : "btn-outline-secondary"}"
                data-action="saveConfig" data-action-value="${esc(verb.value)}"
                data-config-phase="idle" ${disabled ? "disabled" : ""}>${esc(verb.label)}</button>`).join("");
}

function renderConfigActionButton(key, row, uiState, disabled = false, appliedByHub = false) {
    const state = configButtonState(row, uiState);
    // "Guardar" e não "Enviar" quando não há nada a caminho do dispositivo: o botão não deve
    // prometer um envio que não acontece.
    const idleLabel = appliedByHub
        ? "Guardar"
        : (["pushMessage", "push_message"].includes(key) ? "Enviar mensagem" : "Enviar");
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
        <div class="alert alert-${tone} alert-compact alert-dismissible fade show mt-3 mb-0" role="alert" data-config-feedback-key="${esc(key)}">
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
            section &&
            typeof section === "object" &&
            section[key] &&
            typeof section[key] === "object"
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
    return CONFIGURATION_DELIVERY_META[status] ||
        CONFIGURATION_DELIVERY_META.failed;
}

/**
 * Acerta no sítio a pastilha de estado e o aviso de entrega de cada bloco.
 *
 * Uma mudança de estado de entrega chega pelo stream a qualquer momento, e redesenhar a raiz
 * por causa dela deitava fora o número de telefone, o nome ou a hora que estivessem a meio de
 * ser escritos noutro bloco -- precisamente enquanto se espera pelo envio de um.
 */
export function patchConfigurationDeliveryStates(root, configurationSync) {
    for (const section of root.querySelectorAll("[data-config-section]")) {
        const key = section.dataset.capabilityKey || section.dataset.configKey || "";
        if (key === "") continue;

        const delivery = resolveConfigDelivery({ capabilityKey: key }, configurationSync);
        const meta = configurationDeliveryMeta(
            section.dataset.configStored === "1",
            delivery,
        );

        // Trocada inteira pela do componente, e não remendada classe a classe: eram duas
        // cópias da mesma marcação a ter de andar a par.
        const badge = section.querySelector(".state-badge");
        if (badge) {
            badge.outerHTML = stateBadge(meta.label, meta.tone);
        }

        const notice = section.querySelector("[role=\"status\"]");
        const noticeHtml = renderConfigurationDeliveryNotice(meta, delivery);
        if (notice) {
            if (noticeHtml === "") {
                notice.remove();
            } else {
                notice.outerHTML = noticeHtml;
            }
        } else if (noticeHtml !== "") {
            section
                .querySelector("[data-config-form]")
                ?.insertAdjacentHTML("beforebegin", noticeHtml);
        }
    }
}

function renderConfigurationDeliveryNotice(meta, delivery) {
    if (!meta.message) {
        return "";
    }

    const error = String(delivery?.error || "");
    const errorMessage = CONFIGURATION_FAILURE_LABELS[error] || "";
    return `
        <div class="alert alert-${esc(meta.tone)} alert-compact mt-3 mb-0" role="status">
            <i class="fa-solid fa-circle-info me-2"></i>${esc(meta.message)}
            ${errorMessage ? `<span class="d-block mt-1">${esc(errorMessage)}</span>` : ""}
        </div>`;
}

function configHelp(entry) {
    // A legenda declarada na definição descreve *esta* definição; a tabela por tipo de campo
    // só sabe falar da forma do campo. Quando existe, é a que serve quem está a decidir.
    const declared = String(entry.help || "").trim();
    if (declared !== "") {
        return declared;
    }

    const input = entry.input || "json";
    const key = entry.key || "";
    if (CONFIG_INPUT_HELP[input]) {
        return CONFIG_INPUT_HELP[input](entry);
    }
    return CONFIG_INPUT_HELP[key]?.(entry) || "";
}

function readWonlexMedicationPlans(section) {
    const plans = Array.from(
        section.querySelectorAll("[data-repeat-row=\"wonlexMedicationPlan\"]"),
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
                    row.querySelector("[data-medication-field=\"mealTiming\"]:checked")?.value || "0",
                ), 10) === 1
                    ? 1
                    : 0,
            },
        };
    });

    return { plans };
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
