import { html } from "../../../core/html.js";
import { field } from "../../../components/form-field.js";
import { renderPhoneControl } from "../../phone.js";
import { optionLabel } from "../../../core/i18n.js";
import { addAlarmButton, alarmClockRow, readAlarmRows, RECURRENCES } from "../alarm-fields.js";
import { boolValue } from "../normalizers.js";
import { contactsInput } from "./generic.js";
import { deviceCheckCard } from "../../gateways/device-check-card.js";
import { linkSignal } from "../../gateways/gateway-signal.js";
import {
    boundedNumber,
    choiceField,
    enabledSwitch,
    entryFieldLabel,
    entryText,
    fieldChoices,
    nextUid,
    numberField,
    readChoice,
    switchField,
} from "./shared.js";
import {
    readCheckbox,
    readContacts,
    readNumber,
    readText,
    readUniquePhoneArray,
} from "../readers.js";

/** O tom de cada perfil, do mais tolerante ao mais exigente. */
const DIAPER_TONES = { low: "success", normal: "warning", high: "danger" };

function diaperSensitivityInput(entry, desired, meta) {
    const presets = meta.presets ?? {};
    const bounds = meta.bounds ?? {};
    const current = Object.entries(presets).find(([, preset]) =>
        Number(preset.pollutionRange) === Number(desired.pollutionRange) &&
        Number(preset.pollutionValue) === Number(desired.pollutionValue))?.[0] ?? "";
    const name = nextUid("diaper-profile");
    const profiles = Object.entries(presets).map(([profile, preset], index) => html`
        <input type="radio" class="btn-check" name="${name}" id="${name}-${index}" value="${profile}" data-config-preset="${JSON.stringify(preset)}" ${profile === current ? "checked" : ""}>
        <label class="btn btn-outline-${DIAPER_TONES[profile] ?? "secondary"}" for="${name}-${index}">${optionLabel(entry.capabilityKey, meta.protocol, "profile", profile)}</label>`);
    const number = (fieldName) => field(
        entryFieldLabel(entry, meta, fieldName),
        numberField(fieldName, desired[fieldName] ?? "", { min: bounds[fieldName]?.[0] ?? 0, max: bounds[fieldName]?.[1] ?? "" }),
        { cls: "col" },
    );

    return html`
        <div>
            ${profiles.length === 0 ? "" : html`<div class="btn-group w-100 mb-2" role="radiogroup" aria-label="${entryText(entry, meta).label}">${profiles}</div>`}
            <div class="row g-2">${number("pollutionRange")}${number("pollutionValue")}</div>
            <div class="form-text">Muda só como o hub lê o sensor; vale a partir da leitura seguinte.</div>
        </div>`;
}

/** O limiar arrasta-se entre «Longe» e «Perto», ou escreve-se o dBm exacto; os dois são o mesmo campo. */
function proximityAlarmInput(entry, desired, meta) {
    const bounds = meta.bounds ?? {};
    const [min = "", max = ""] = bounds.triggerDbm ?? [];
    const linked = meta.linkedDevices ?? [];
    const chosen = Array.isArray(desired.devices) ? desired.devices.map(String) : [];
    const scope = desired.devices === "all" || desired.devices === undefined ? "all" : "chosen";
    const label = (field) => entryFieldLabel(entry, meta, field);
    const trigger = String(desired.triggerDbm ?? "");
    const timing = (name, unit) => field(label(name), numberField(name, desired[name] ?? "", {
        min: bounds[name]?.[0] ?? 0,
        max: bounds[name]?.[1] ?? "",
        unit,
    }), { cls: "col-6" });

    return html`
        <div class="vstack gap-3 proximity-alarm">
            ${choiceField({ field: "mode", value: desired.mode, options: fieldChoices(entry, meta, "mode"), label: label("mode") })}
            <div>
                <div class="form-label">${label("devices")}</div>
                ${choiceField({
                    field: "devicesScope",
                    value: scope,
                    options: [
                        { value: "all", label: `Todos (${linked.length})` },
                        { value: "chosen", label: "Escolhidos" },
                    ],
                    label: label("devices"),
                })}
                <div class="proximity-alarm-picker gateway-picker d-grid gap-2 mt-2">
                    ${linked.length === 0
                        ? html`<p class="gateway-picker-empty mb-0 small text-secondary">Nenhum dispositivo ligado a este gateway.</p>`
                        : linked.map((device) => deviceCheckCard(device, chosen.includes(String(device.deviceKey)), linkSignal(device), "devices"))}
                </div>
            </div>
            <div data-twin-fields>
                <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                    <label class="form-label mb-0">${label("triggerDbm")}</label>
                    ${numberField("triggerDbm", trigger, { min, max, unit: "dBm", cls: "flex-nowrap w-auto" })}
                </div>
                <input class="form-range" type="range" min="${min}" max="${max}" step="1" data-config-field="triggerDbm" value="${trigger}" aria-label="${label("triggerDbm")}">
                <div class="d-flex justify-content-between small text-secondary"><span>Longe</span><span>Perto</span></div>
            </div>
            <div class="row g-2">
                ${timing("minSamples", "")}
                ${timing("cooldownSeconds", "s")}
            </div>
        </div>`;
}

function windowToggleInput(entry, desired) {
    const [start = "", end = ""] = String(desired.range ?? "").split("-");
    return html`
        <div class="row g-3 align-items-end">
            <div class="col-md-4">${enabledSwitch(boolValue(desired.enabled, false), "mt-4")}</div>
            ${field("Início", html`<input class="form-control" type="time" data-config-field="rangeStart" value="${start}">`, { cls: "col-md-4" })}
            ${field("Fim", html`<input class="form-control" type="time" data-config-field="rangeEnd" value="${end}">`, { cls: "col-md-4" })}
        </div>`;
}

/** Os limiares que o aparelho avalia sobre a medição dele. */
function heartRateThresholdsInput(entry, desired, meta) {
    return html`
        <div class="row g-3 align-items-end">
            <div class="col-md-4">${enabledSwitch(boolValue(desired.enabled, false), "mt-4")}</div>
            ${["maxBpm", "minBpm"].map((name) => field(entryFieldLabel(entry, meta, name), boundedNumber(entry, meta, name, desired), { cls: "col-md-4" }))}
        </div>`;
}

/** O corpo com que a pulseira calcula calorias e composição corporal. */
function personalInfoInput(entry, desired, meta) {
    const number = (name) => field(entryFieldLabel(entry, meta, name), boundedNumber(entry, meta, name, desired), { cls: "col-md-4" });
    return html`
        <div class="row g-3">
            ${number("heightCm")}${number("weightKg")}${number("age")}
            ${field(entryFieldLabel(entry, meta, "sex"), choiceField({ field: "sex", value: desired.sex, options: fieldChoices(entry, meta, "sex") }), { cls: "col-md-4" })}
            ${number("stepGoal")}${number("sleepGoalMinutes")}
        </div>`;
}

/** Os números SOS escolhem-se da lista telefónica quando o protocolo assim o declara. */
function sosContactsInput(entry, desired, meta = {}) {
    if (meta.sourceCapability === "phonebook") {
        const selected = new Set(Array.isArray(desired) ? desired.map(String) : []);
        const contacts = (meta.phonebookContacts ?? []).filter((contact) => contact?.phone);
        if (contacts.length === 0) {
            return html`<div class="alert alert-warning small mb-0">Acrescente primeiro os contactos à lista telefónica.</div>`;
        }

        return html`
            <div class="vstack gap-2">
                ${contacts.map((contact, index) => {
                    const phone = String(contact.phone);
                    const name = String(contact.name || "").trim();
                    const id = `${nextUid("sos-phonebook")}-${index}`;
                    return html`
                        <label class="border rounded bg-body p-3 d-flex align-items-center gap-3" for="${id}">
                            <input id="${id}" class="form-check-input mt-0" type="checkbox" data-sos-contact-phone value="${phone}" ${selected.has(phone) ? "checked" : ""}>
                            <span>
                                <span class="d-block fw-semibold">${name || phone}</span>
                                ${name ? html`<span class="small text-secondary">${phone}</span>` : ""}
                            </span>
                        </label>`;
                })}
            </div>`;
    }

    return phoneRepeaterInput(entry, desired, meta, "sos_contacts");
}

/** A lista de chamadas leva nomes quando o campo nativo é `contacts`, e só números quando é `numbers`. */
function callWhitelistInput(entry, desired, meta = {}) {
    return entry.fields?.[0] === "contacts"
        ? contactsInput(entry, desired, meta)
        : phoneRepeaterInput(entry, desired, meta, "call_whitelist");
}

function phoneRepeaterInput(entry, desired, meta, kind) {
    const limit = Math.max(1, Number(meta.limit ?? entry.limit) || 1);
    const values = Array.isArray(desired) ? desired : Array.isArray(desired.numbers) ? desired.numbers : [];
    const rows = values.length ? values.slice(0, limit) : [""];
    const phoneMaxLength = Number(meta.phone?.maxLength) || 0;

    return html`
        <div class="vstack gap-3">
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="${kind}" ${rows.length >= limit ? "disabled" : ""}>Adicionar número <span data-repeat-count>(${rows.length} de ${limit})</span></button>
            </div>
            <div class="vstack gap-2" data-repeat-list="${kind}" data-repeat-limit="${limit}">
                ${rows.map((value) => html`
                    <div class="row g-2 align-items-end" data-repeat-row="${kind}">
                        <div class="col">${renderPhoneControl({ value: String(value || ""), configField: "numbers", maxLength: phoneMaxLength })}</div>
                        <div class="col-auto"><button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm" data-action="removeRepeatRow">-</button></div>
                    </div>`)}
            </div>
        </div>`;
}

/** As escolhas de recorrência e de tipo vêm do `_meta` da capacidade; o nome delas, do dicionário. */
function alarmClockInput(entry, desired, meta = {}) {
    const items = Array.isArray(desired) ? desired : desired?.items ?? [];
    const limit = Math.max(1, Number(meta.limit ?? entry.limit) || 1);
    const recurrences = (meta.recurrence?.options ?? []).map((option) => option.value);
    const options = {
        recurrences: recurrences.length > 0 ? recurrences : RECURRENCES,
        types: fieldChoices(entry, meta, "type"),
        label: meta.label?.supported === true,
        url: meta.url?.supported === true,
        group: nextUid("alarm-clock-group"),
    };
    const shown = (items.length > 0 ? items : [{}]).slice(0, limit);

    return html`
        <div class="vstack gap-3">
            ${entry.fields?.includes("masterEnabled")
                ? switchField("masterEnabled", boolValue(desired?.masterEnabled, true), { on: "Lembretes ligados", off: "Lembretes desligados", attr: "data-alarm-clock-field" })
                : ""}
            <div class="vstack gap-2" data-repeat-list="alarm_clock" data-repeat-limit="${limit}">
                ${shown.map((item) => alarmClockRow(item, options))}
            </div>
            ${addAlarmButton("alarm_clock", "Acrescentar alarme", shown.length, limit)}
        </div>`;
}

export const INPUTS = {
    proximityAlarm: {
        render: proximityAlarmInput,
        read: (section) => ({
            mode: readChoice(section, "mode"),
            devices: readChoice(section, "devicesScope") === "all"
                ? "all"
                : [...section.querySelectorAll("[data-config-field=\"devices\"]:checked")].map((input) => input.value),
            triggerDbm: readNumber(section, "triggerDbm"),
            minSamples: readNumber(section, "minSamples"),
            cooldownSeconds: readNumber(section, "cooldownSeconds"),
        }),
    },
    diaperSensitivity: {
        render: diaperSensitivityInput,
        read: (section) => ({
            pollutionRange: readNumber(section, "pollutionRange"),
            pollutionValue: readNumber(section, "pollutionValue"),
        }),
    },
    windowToggle: {
        render: windowToggleInput,
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            range: `${readText(section, "rangeStart")}-${readText(section, "rangeEnd")}`,
        }),
    },
    personalInfo: {
        render: personalInfoInput,
        read: (section) => ({
            heightCm: readNumber(section, "heightCm"),
            weightKg: readNumber(section, "weightKg"),
            age: readNumber(section, "age"),
            sex: readChoice(section, "sex"),
            stepGoal: readNumber(section, "stepGoal"),
            sleepGoalMinutes: readNumber(section, "sleepGoalMinutes"),
        }),
    },
    heartRateThresholds: {
        render: heartRateThresholdsInput,
        read: (section) => {
            const maxBpm = readNumber(section, "maxBpm");
            const minBpm = readNumber(section, "minBpm");
            if (minBpm > maxBpm) throw new Error("O mínimo não pode passar do máximo");
            return { enabled: readCheckbox(section, "enabled"), maxBpm, minBpm };
        },
    },
    sos_contacts: {
        render: sosContactsInput,
        read: (section) => {
            const selector = section.querySelectorAll("[data-sos-contact-phone]");
            if (selector.length > 0) {
                return Array.from(selector).filter((input) => input.checked).map((input) => input.value);
            }
            return readUniquePhoneArray(section, "numbers", "Contactos SOS");
        },
    },
    call_whitelist: {
        render: callWhitelistInput,
        read: (section) => (section.querySelector("[data-repeat-row=\"contacts\"]")
            ? { contacts: readContacts(section) }
            : readUniquePhoneArray(section, "numbers", "Lista de chamadas autorizadas")),
    },
    phonebook: {
        render: contactsInput,
        read: (section) => ({ contacts: readContacts(section) }),
    },
    alarm_clock: {
        render: alarmClockInput,
        read: (section) => {
            const master = section.querySelector("[data-alarm-clock-field=\"masterEnabled\"]");
            const items = readAlarmRows(section);
            return master === null ? { items } : { masterEnabled: master.checked, items };
        },
    },
};
