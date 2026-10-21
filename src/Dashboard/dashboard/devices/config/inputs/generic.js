import { html, raw } from "../../../html.js";
import { field } from "../../../components/form-field.js";
import { renderPhoneControl } from "../../../phone.js";
import { boolValue } from "../normalizers.js";
import {
    boundedNumber,
    entryFieldLabel,
    entryText,
    fieldChoices,
    intervalToggle,
    switchField,
} from "./shared.js";
import {
    firstFieldName,
    readCheckbox,
    readNumber,
    readPhone,
    readText,
} from "../readers.js";

export function toggleField(entry) {
    return entry.fields?.[0] || "enabled";
}

export function toggleValue(entry, desired) {
    return boolValue(desired[toggleField(entry)], false);
}

export function toggleInput(entry, desired) {
    return switchField(toggleField(entry), toggleValue(entry, desired));
}

/** Sem rótulo, que o nome é o título do cartão; o `aria-label` guarda-o para quem não vê a linha. */
function numberControl(entry, desired, meta) {
    const name = entry.fields?.[0] || "value";
    return boundedNumber(entry, meta, name, desired, { ariaLabel: entry.label || entryText(entry, meta).label, unit: "" });
}

/** O número de quem se liga, se escuta ou se avisa: um campo de telefone com o rótulo do campo. */
function phoneInput(entry, desired, meta) {
    const name = entry.fields?.[0] || "phone";
    return field(
        entryFieldLabel(entry, meta, name),
        renderPhoneControl({ value: String(desired[name] || ""), configField: name }),
    );
}

function pushMessageInput(entry, desired, meta) {
    return field(
        entryFieldLabel(entry, meta, "message"),
        html`<input class="form-control" type="text" data-config-field="message" value="${String(desired.message ?? "")}">`,
    );
}

/** Os contactos com nome; o comprimento máximo do nome vem do `_meta` da capacidade. */
export function contactsInput(entry, desired, meta = {}) {
    const limit = Math.max(1, Number(meta.limit ?? entry.limit) || 1);
    const contacts = Array.isArray(desired) ? desired : Array.isArray(desired.contacts) ? desired.contacts : [];
    const rows = contacts.length ? contacts.slice(0, limit) : [{}];
    const nameMaxLength = Number(meta.name?.maxLength) || 0;
    const phoneMaxLength = Number(meta.phone?.maxLength) || 0;
    const nameAttr = nameMaxLength > 0 ? html` maxlength="${nameMaxLength}"` : "";

    return html`
        <div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label-sm mb-0">${entryFieldLabel(entry, meta, "contacts")}</label>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="contacts" ${rows.length >= limit ? "disabled" : ""}>Adicionar <span data-repeat-count>(${rows.length} de ${limit})</span></button>
            </div>
            <div class="vstack gap-2" data-repeat-list="contacts" data-repeat-limit="${limit}">
                ${rows.map((contact, index) => html`
                    <div class="row g-2 align-items-end" data-repeat-row="contacts">
                        <div class="col-md-6">
                            <input class="form-control" type="text" placeholder="Nome ${index + 1}" data-repeat-field="name"${nameAttr} value="${String(contact.name || "")}">
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2">
                                <div class="flex-grow-1">
                                    ${renderPhoneControl({ value: String(contact.phone || ""), repeatField: "phone", maxLength: phoneMaxLength })}
                                </div>
                                <button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm" data-action="removeRepeatRow">-</button>
                            </div>
                        </div>
                    </div>
                `)}
            </div>
        </div>`;
}

function selectInput(entry, desired, meta) {
    const name = entry.fields?.[0] || "value";
    const current = String(desired?.[name] ?? "");
    const choices = fieldChoices(entry, meta, name).map((option) => {
        const value = String(option.value);
        return html`<option value="${value}"${value === current ? raw(" selected") : ""}>${option.label}</option>`;
    });

    return html`<select class="form-select" data-config-field="${name}">${choices}</select>`;
}

/** O desvio de agora de uma zona da IANA, como o `Intl` o escreve: «GMT+01:00». */
const zoneOffset = (zone) => new Intl.DateTimeFormat("pt-PT", { timeZone: zone, timeZoneName: "longOffset" })
    .formatToParts(new Date())
    .find((part) => part.type === "timeZoneName")?.value ?? "";

function timeZoneInput(entry, desired) {
    const name = entry.fields?.[0] || "timeZone";
    const current = String(desired?.[name] ?? "");
    const zones = (entry.options?.[name] ?? []).map((option) => {
        const zone = String(option.value);
        return html`<option value="${zone}"${zone === current ? raw(" selected") : ""}>${zone} — ${zoneOffset(zone)}</option>`;
    });

    return html`<select class="form-select" data-config-field="${name}">${zones}</select>`;
}

/** O valor do campo: número quando o parece, que um fuso com nome fica texto. */
export function readSelect(section) {
    const node = section.querySelector("select[data-config-field]");
    if (!node) return {};
    const value = String(node.value ?? "");
    return { [node.dataset.configField]: value !== "" && !Number.isNaN(Number(value)) ? Number(value) : value };
}

export const INPUTS = {
    select: {
        control: selectInput,
        read: readSelect,
    },
    timeZone: {
        control: timeZoneInput,
        read: readSelect,
    },
    toggle: {
        render: toggleInput,
        read: (section) => {
            const name = firstFieldName(section);
            return { [name]: readCheckbox(section, name) };
        },
    },
    number: {
        control: numberControl,
        read: (section) => {
            const name = firstFieldName(section);
            return { [name]: readNumber(section, name) };
        },
    },
    phone: {
        render: phoneInput,
        read: (section) => {
            const name = firstFieldName(section);
            return { [name]: readPhone(section, name) };
        },
    },
    makeCall: {
        render: phoneInput,
        read: (section) => ({ phone: readPhone(section, "phone") }),
    },
    voiceMonitor: {
        render: phoneInput,
        read: (section) => ({ phone: readPhone(section, "phone") }),
    },
    pushMessage: {
        render: pushMessageInput,
        read: (section) => ({ message: readText(section, "message") }),
    },
    intervalToggle: {
        render: (entry, desired, meta) => intervalToggle(entry, desired, meta, "intervalMinutes"),
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            intervalMinutes: readNumber(section, "intervalMinutes"),
        }),
    },
    intervalHoursToggle: {
        render: (entry, desired, meta) => intervalToggle(entry, desired, meta, "intervalHours"),
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            intervalHours: readNumber(section, "intervalHours"),
        }),
    },
    // Uma acção não tem campos; sem `render`, o cartão desenha-se numa linha.
    action: {
        read: () => ({}),
    },
};
