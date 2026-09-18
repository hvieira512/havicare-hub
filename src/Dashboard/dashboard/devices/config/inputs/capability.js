import { esc } from "../../../format.js";
import { field } from "../../../widgets.js";
import { renderPhoneControl } from "../../../phone.js";
import { normalizeAlarmClockRecurrenceKind, weekdayPicker } from "../alarm-fields.js";
import {
    boolValue,
    defaultAlarmClockItem,
    formatReminderTime,
    normalizeAlarmClockDaySelection,
    normalizeAlarmClockItems,
} from "../normalizers.js";
import { contactsInput, toggleInput } from "./generic.js";
import { enabledSwitch, nextUid, numberField } from "./shared.js";
import {
    readAlarmClock,
    readCheckbox,
    readContacts,
    readNumber,
    readText,
    readUniquePhoneArray,
} from "../readers.js";

/**
 * Os campos das capacidades genéricas do hub -- alarmes, contactos SOS, lista de chamadas autorizadas, dados
 * pessoais, sensibilidade da fralda.
 *
 * Não pertencem a nenhum fornecedor: são a forma que o hub dá a uma capacidade, e cada
 * protocolo liga-se-lhes pelo seu nome nativo. É por isso que o `normalizeConfigEntry` os
 * escolhe pela chave de capacidade e não pelo `input` declarado.
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

/** Um campo numérico de configuração. Dezasseis sítios repetiam esta linha e a sua escapagem. */

export function bloodPressureInput(desired) {
    return `
        <div class="row g-3">
            ${field(
                "Sistólica",
                numberField("systolic", desired.systolic ?? 120),
                { cls: "col-md-6" },
            )}
            ${field(
                "Diastólica",
                numberField("diastolic", desired.diastolic ?? 80),
                { cls: "col-md-6" },
            )}
        </div>`;
}

export function windowToggleInput(_entry, desired) {
    const [start = "22:00", end = "08:00"] = String(desired.range ?? "22:00-08:00").split("-");
    return `
        <div class="row g-3 align-items-end">
            <div class="col-md-4">${enabledSwitch(boolValue(desired.enabled, true), "mt-4")}</div>
            ${field("Início", `<input class="form-control" type="time" data-config-field="rangeStart" value="${esc(start)}">`, { cls: "col-md-4" })}
            ${field("Fim", `<input class="form-control" type="time" data-config-field="rangeEnd" value="${esc(end)}">`, { cls: "col-md-4" })}
        </div>`;
}

/** Os limiares que o aparelho avalia sobre a medição dele. */

export function heartRateThresholdsInput(_entry, desired) {
    return `
        <div class="row g-3 align-items-end">
            <div class="col-md-4">${enabledSwitch(boolValue(desired.enabled, true), "mt-4")}</div>
            ${field("Máximo (bpm)", numberField("maxBpm", desired.maxBpm ?? 150, { min: 40, max: 220 }), { cls: "col-md-4" })}
            ${field("Mínimo (bpm)", numberField("minBpm", desired.minBpm ?? 50, { min: 30, max: 200 }), { cls: "col-md-4" })}
        </div>`;
}

/**
 * O corpo com que a pulseira calcula.
 *
 * Não identifica quem a usa: alimenta as fórmulas das calorias e da composição corporal, que
 * sem isto correm sobre valores de fábrica.
 */

export function personalInfoInput(_entry, desired) {
    const sex = desired.sex === "male" ? "male" : "female";
    return `
        <div class="row g-3">
            ${field("Altura (cm)", numberField("heightCm", desired.heightCm ?? 170, { min: 50, max: 250 }), { cls: "col-md-4" })}
            ${field("Peso (kg)", numberField("weightKg", desired.weightKg ?? 70, { min: 10, max: 300 }), { cls: "col-md-4" })}
            ${field("Idade", numberField("age", desired.age ?? 40, { min: 1, max: 120 }), { cls: "col-md-4" })}
            ${field(
                "Sexo",
                `<select class="form-select" data-config-field="sex">
                    <option value="female"${sex === "female" ? " selected" : ""}>Feminino</option>
                    <option value="male"${sex === "male" ? " selected" : ""}>Masculino</option>
                </select>`,
                { cls: "col-md-4" },
            )}
            ${field("Meta de passos", numberField("stepGoal", desired.stepGoal ?? 8000, { min: 100, max: 100000, step: 100 }), { cls: "col-md-4" })}
            ${field("Meta de sono (min)", numberField("sleepGoalMinutes", desired.sleepGoalMinutes ?? 480, { min: 60, max: 900, step: 15 }), { cls: "col-md-4" })}
        </div>`;
}

/** O interruptor de ligado. O `mt-4` alinha-o por baixo de um campo com etiqueta ao lado. */

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
                        const id = `${nextUid("sos-phonebook")}-${index}`;
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
        label: "Lista de chamadas autorizadas",
        emptyLabel: "Adicionar número",
        placeholderPrefix: "Número",
        helpText: "Até 10 números permitidos.",
    });
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
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="${esc(kind)}" ${rows.length >= limit ? "disabled" : ""}>${esc(emptyLabel)}</button>
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
                            <button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm" data-action="removeRepeatRow">-</button>
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
                <div class="col-12 ${customVisible ? "" : "d-none"}" data-alarm-clock-custom-wrapper>
                    ${weekdayPicker(dayMask, rowId)}
                </div>
                <div class="col-12 d-flex justify-content-end">
                    <button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm" data-action="removeRepeatRow" title="Remover" aria-label="Remover">
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

/**
 * Os descritores dos campos de capacidade genérica.
 *
 * Cada tipo de campo declara aqui as suas quatro faces juntas -- desenhar, ler de volta, o
 * valor inicial e a legenda. Eram quatro mapas separados indexados pela mesma chave, e nada
 * garantia que ficassem alinhados: uma entrada em falta não dava erro, dava um campo genérico.
 */
export const INPUTS = {
    diaperSensitivity: {
        render: (_entry, desired, meta) => diaperSensitivityInput(desired, meta),
        read: (section) => ({
            pollutionRange: readNumber(section, "pollutionRange"),
            pollutionValue: readNumber(section, "pollutionValue"),
        }),
        // Sem `defaults`: abre vazio. Não é decisão desta camada qual seria o valor plausível.
    },
    bloodPressure: {
        render: (_entry, desired) => bloodPressureInput(desired),
        read: (section) => ({
            systolic: readNumber(section, "systolic"),
            diastolic: readNumber(section, "diastolic"),
        }),
        defaults: () => ({ systolic: 120, diastolic: 80 }),
    },
    windowToggle: {
        render: windowToggleInput,
        // As horas voltam a juntar-se no formato que o construtor valida.
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            range: `${readText(section, "rangeStart")}-${readText(section, "rangeEnd")}`,
        }),
        // Sem `defaults`: abre vazio. Não é decisão desta camada qual seria o valor plausível.
    },
    personalInfo: {
        render: personalInfoInput,
        read: (section) => ({
            heightCm: readNumber(section, "heightCm"),
            weightKg: readNumber(section, "weightKg"),
            age: readNumber(section, "age"),
            sex: readText(section, "sex"),
            stepGoal: readNumber(section, "stepGoal"),
            sleepGoalMinutes: readNumber(section, "sleepGoalMinutes"),
        }),
        // Sem `defaults`: abre vazio. Não é decisão desta camada qual seria o valor plausível.
    },
    heartRateThresholds: {
        render: heartRateThresholdsInput,
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            maxBpm: readNumber(section, "maxBpm"),
            minBpm: readNumber(section, "minBpm"),
        }),
        // Sem `defaults`: abre vazio. Não é decisão desta camada qual seria o valor plausível.
    },
    sos_contacts: {
        render: sosContactsInput,
        read: (section) => {
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
        defaults: () => [],
        help: () => "",
    },
    call_whitelist: {
        render: callWhitelistInput,
        read: (section) => {
            const limit = parseInt(section.dataset.configLimit || "10", 10) || 10;
            if ((section.dataset.configProtocol || "") === "vivistar-iw") {
                return { contacts: readContacts(section).slice(0, limit) };
            }
            return readUniquePhoneArray(section, "numbers", "Lista de chamadas autorizadas").slice(0, limit);
        },
        defaults: (entry, protocol) => protocol === "vivistar-iw"
            ? { contacts: [{ name: "", phone: "" }] }
            : ["", "", "", "", "", "", "", "", "", ""],
        help: () => "",
    },
    whitelist_enabled: {
        render: (entry, desired) =>
            toggleInput({ ...entry, fields: ["enabled"] }, desired),
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
        }),
        defaults: () => ({ enabled: true }),
        help: () => "ativa ou desativa a lista de chamadas autorizadas",
    },
    phonebook: {
        render: contactsInput,
        read: (section) => ({ contacts: readContacts(section) }),
        defaults: () => ({ contacts: [] }),
        help: (entry) => (entry.limit || 0) > 0 ? `limite ${entry.limit}` : "",
    },
    alarm_clock: {
        render: (_entry, desired, meta) => alarmClockInput(desired, meta),
        read: (section) => readAlarmClock(section),
        defaults: () => ({ items: [] }),
        help: () => "Até 3 alarmes com recorrência e tipo, quando suportado.",
    },
};
