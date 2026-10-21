import { fieldLabel } from "../../../format.js";
import { html, raw } from "../../../html.js";
import { field } from "../../../components/form-field.js";
import { renderPhoneControl } from "../../../phone.js";
import { protocolPhonebookConstraints } from "../protocol-catalog.js";
import { boolValue } from "../normalizers.js";
import { intervalToggle, numberField } from "./shared.js";
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

/** Sem valor guardado nasce ligado, que é como o aparelho vem de fábrica. */
export function toggleValue(entry, desired) {
    return boolValue(desired[toggleField(entry)], true);
}

export function toggleInput(entry, desired) {
    const field = toggleField(entry);
    const checked = toggleValue(entry, desired);
    return html`
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" data-config-field="${field}" ${checked ? "checked" : ""}>
            <label class="form-check-label" data-switch-label>${checked ? "Ligado" : "Desligado"}</label>
        </div>`;
}

/** Sem rótulo, que o nome é o título do cartão; o `aria-label` guarda-o para quem não vê a linha. */
function numberControl(entry, desired) {
    const key = entry.fields?.[0] || "value";
    // A escala parte do mínimo declarado: o tom de pele vai de 1 a 6.
    const { min = 0, max = "", step = 1 } = entry.options ?? {};
    return numberField(key, desired[key] ?? min, {
        min,
        max,
        step,
        ariaLabel: entry.label || fieldLabel(key),
    });
}

function phoneInput(entry, desired) {
    const key = entry.fields?.[0] || "phone";
    return field(
        fieldLabel(key),
        renderPhoneControl({
            value: String(desired[key] || ""),
            configField: key,
        }),
    );
}

function textInput(entry, desired) {
    const key = entry.fields?.[0] || "value";
    return field(
        fieldLabel(key),
        html`<input class="form-control" type="text" data-config-field="${key}" value="${String(desired[key] ?? "")}">`,
    );
}

function pushMessageInput(_entry, desired) {
    return field(
        "Mensagem",
        html`<input class="form-control" type="text" data-config-field="message" value="${String(desired.message ?? "")}">`,
    );
}

function intervalToggleInput(entry, desired) {
    return intervalToggle(desired, "intervalMinutes", {
        fallback: 60,
        unit: "min",
        bounds: { min: entry.options?.min ?? 0 },
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

    const nameMaxLengthValue = Math.max(
        0,
        parseInt(String(meta.name?.maxLength ?? phonebookConstraints.name?.maxLength ?? 0), 10) || 0,
    );
    const phoneMaxLengthValue = Math.max(
        0,
        parseInt(String(meta.phone?.maxLength ?? phonebookConstraints.phone?.maxLength ?? 0), 10) || 0,
    );
    const nameMaxLength = nameMaxLengthValue > 0 ? html` maxlength="${nameMaxLengthValue}"` : "";
    return html`
        <div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label-sm mb-0">Contactos</label>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="contacts" ${rows.length >= limit ? "disabled" : ""}>Adicionar <span data-repeat-count>(${rows.length} de ${limit})</span></button>
            </div>
            <div class="vstack gap-2" data-repeat-list="contacts" data-repeat-limit="${limit}">
                ${rows
                    .map(
                        (contact, index) => html`
                    <div class="row g-2 align-items-end" data-repeat-row="contacts">
                        <div class="col-md-6">
                            <input class="form-control" type="text" placeholder="Nome ${index + 1}" data-repeat-field="name"${nameMaxLength} value="${String(contact.name || "")}">
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2">
                                <div class="flex-grow-1">
                                    ${renderPhoneControl({
                                        value: String(contact.phone || ""),
                                        repeatField: "phone",
                                        maxLength: phoneMaxLengthValue,
                                    })}
                                </div>
                                <button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm" data-action="removeRepeatRow">-</button>
                            </div>
                        </div>
                    </div>
                `,
                    )}
            </div>
        </div>`;
}

export function selectOptions(entry) {
    const name = entry.fields?.[0] || "value";
    const options = Array.isArray(entry.options?.[name]) ? entry.options[name] : [];
    // Sem `default` declarado seria a primeira, que numa lista ordenada (os fusos) é a ponta.
    const declared = entry.options?.default;

    return {
        name,
        options,
        fallback: String(declared ?? options[0]?.value ?? ""),
    };
}

function selectInput(entry, desired) {
    const { name, options, fallback } = selectOptions(entry);
    const current = String(desired?.[name] ?? fallback);
    const choices = options
        .map((option) => {
            const value = String(option.value);
            return html`<option value="${value}"${value === current ? raw(" selected") : ""}>${String(option.label ?? value)}</option>`;
        });

    return html`<select class="form-select" data-config-field="${name}">${choices}</select>`;
}

export const INPUTS = {
    select: {
        control: selectInput,
        read: (section) => {
            const node = section.querySelector("select[data-config-field]");
            if (!node) return {};
            const raw = String(node.value ?? "");
            // Só o que não parece número passa como texto.
            return { [node.dataset.configField]: raw !== "" && !Number.isNaN(Number(raw)) ? Number(raw) : raw };
        },
        defaults: (entry) => {
            const { name, options } = selectOptions(entry);
            return { [name]: entry.options?.default ?? options[0]?.value ?? 0 };
        },
    },
    toggle: {
        render: (entry, desired) => toggleInput(entry, desired),
        read: (section) => {
            const field = firstFieldName(section);
            return { [field]: readCheckbox(section, field) };
        },
        defaults: (entry) => ({ [entry.fields?.[0] || "value"]: true }),
    },
    number: {
        control: numberControl,
        read: (section) => {
            const field = firstFieldName(section);
            return { [field]: readNumber(section, field) };
        },
        // Do mínimo: um campo de 1 a 6 aberto em zero oferece um valor que o aparelho recusa.
        defaults: (entry) => ({
            [entry.fields?.[0] || "value"]: entry.options?.default ?? entry.options?.min ?? 0,
        }),
    },
    phone: {
        render: phoneInput,
        read: (section) => {
            const field = firstFieldName(section);
            return { [field]: readPhone(section, field) };
        },
        defaults: (entry) => ({ [entry.fields?.[0] || "value"]: "" }),
    },
    text: {
        render: textInput,
        read: (section) => {
            const field = firstFieldName(section);
            return { [field]: readText(section, field) };
        },
        defaults: (entry) => ({ [entry.fields?.[0] || "value"]: "" }),
    },
    pushMessage: {
        render: pushMessageInput,
        read: (section) => ({ message: readText(section, "message") }),
    },
    intervalToggle: {
        render: intervalToggleInput,
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            intervalMinutes: readNumber(section, "intervalMinutes"),
        }),
        defaults: () => ({ enabled: true, intervalMinutes: 60 }),
    },
    // Uma acção não tem campos; sem `render`, o cartão desenha-se numa linha.
    action: {
        read: () => ({}),
    },
};
