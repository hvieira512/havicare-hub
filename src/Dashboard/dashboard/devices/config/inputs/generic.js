import { esc, fieldLabel } from "../../../format.js";
import { field } from "../../../components/form-field.js";
import { renderPhoneControl, resetPhoneControls } from "../../../phone.js";
import { protocolPhonebookConstraints } from "../protocol-catalog.js";
import { boolValue } from "../normalizers.js";
import { enabledSwitch, numberField } from "./shared.js";
import {
    firstFieldName,
    readCheckbox,
    readNumber,
    readPhone,
    readText,
} from "../readers.js";

/**
 * Os campos que mais do que um fornecedor declara: interruptores, números, texto, telefones e
 * listas de contactos. Um campo aqui é desenhado da mesma maneira venha de onde vier -- o que
 * muda entre protocolos é o nome nativo, e disso trata a definição, não o desenho.
 */

/**
 * O nome do campo no valor guardado, que nem sempre é o nome nativo da definição.
 *
 * A Wonlex declara `switchState` e o hub entrega `enabled`: o `wonlexFromNative` renomeia e
 * apaga o original. Quem procurasse o nome nativo não encontrava nada e desenhava o
 * interruptor ligado, fosse qual fosse o valor guardado.
 */
export function toggleField(entry, protocol = "") {
    const nativeField = entry.fields?.[0] || "enabled";
    return protocol === "wonlex-json" && nativeField === "switchState"
        ? "enabled"
        : nativeField;
}

/** Sem valor guardado nasce ligado, que é como o aparelho vem de fábrica. */
export function toggleValue(entry, desired, protocol = "") {
    const nativeField = entry.fields?.[0] || "enabled";
    return boolValue(desired[toggleField(entry, protocol)] ?? desired[nativeField], true);
}

export function toggleInput(entry, desired, protocol = "") {
    const field = toggleField(entry, protocol);
    const checked = toggleValue(entry, desired, protocol);
    return `
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" data-config-field="${esc(field)}" ${checked ? "checked" : ""}>
            <label class="form-check-label" data-switch-label>${checked ? "Ligado" : "Desligado"}</label>
        </div>`;
}

/**
 * Um número, sem rótulo: quem o nomeia é o título do cartão, e a unidade vai ao lado do
 * campo. O `aria-label` guarda o nome para quem não vê a linha.
 */
function numberControl(entry, desired) {
    const key = entry.fields?.[0] || "value";
    // A escala vem da definição quando ela a declara -- o tom de pele vai de 1 a 6, e partir
    // de zero oferecia um valor que o aparelho recusa.
    const { min = 0, max = "" } = entry.options ?? {};
    return numberField(key, desired[key] ?? min, {
        min,
        max,
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
        `<input class="form-control" type="text" data-config-field="${esc(key)}" value="${esc(String(desired[key] ?? ""))}">`,
    );
}

function pushMessageInput(_entry, desired) {
    return field(
        "Mensagem",
        `<input class="form-control" type="text" data-config-field="message" value="${esc(String(desired.message ?? ""))}" placeholder="Mensagem a mostrar no relógio">`,
        { help: "Envia uma mensagem imediata para o relógio. Não fica guardada como configuração desejada." },
    );
}

function intervalToggleInput(entry, desired) {
    return `
        <div class="row g-3">
            <div class="col-md-4">${enabledSwitch(boolValue(desired.enabled, true), "mt-4")}</div>
            ${field(
                "Intervalo (minutos)",
                numberField("intervalMinutes", desired.intervalMinutes ?? 60),
                { cls: "col-md-8" },
            )}
        </div>`;
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
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="contacts" ${rows.length >= limit ? "disabled" : ""}>Adicionar</button>
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
                                        maxLength: phoneMaxLengthValue,
                                    })}
                                </div>
                                <button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm" data-action="removeRepeatRow">-</button>
                            </div>
                        </div>
                    </div>
                `,
                    )
                    .join("")}
            </div>
        </div>`;
}

function isFourPTouchPhonebookSection(section) {
    return String(section?.dataset?.configProtocol || "") === "four-p-touch" &&
        String(section?.dataset?.configKey || "") === "phonebook";
}

/** A primeira linha de contactos, quando a lista veio vazia e não há de onde clonar. */
export function createContactRow(section) {
    const phonebook = isFourPTouchPhonebookSection(section);
    const nameMaxLength = parseInt(
        section?.dataset.phonebookNameMaxLength || "0", 10,
    ) || 0;
    const phoneMaxLength = parseInt(
        section?.dataset.phonebookPhoneMaxLength || (phonebook ? "20" : "0"), 10,
    ) || 0;

    const wrapper = document.createElement("div");
    wrapper.className = "row g-2 align-items-end";
    wrapper.dataset.repeatRow = "contacts";
    wrapper.innerHTML = `
        <div class="col-md-6">
            <input class="form-control" type="text" placeholder="Nome"${phonebook && nameMaxLength > 0 ? ` maxlength="${nameMaxLength}"` : ""} data-repeat-field="name">
        </div>
        <div class="col-md-6">
            <div class="d-flex gap-2">
                <div class="flex-grow-1">
                    ${renderPhoneControl({ repeatField: "phone", maxLength: phoneMaxLength })}
                </div>
                <button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm" data-action="removeRepeatRow">-</button>
            </div>
        </div>`;
    resetPhoneControls(wrapper);
    return wrapper;
}

/**
 * As opções que a definição declara para um campo, já normalizadas.
 *
 * O catálogo traz `options: { campo: [{value, label}] }`, que é o mesmo formato que a
 * sensibilidade de queda dos relógios usava no seu campo próprio.
 */
export function selectOptions(entry) {
    const name = entry.fields?.[0] || "value";
    const options = Array.isArray(entry.options?.[name]) ? entry.options[name] : [];
    // A definição pode dizer de onde parte. Sem isso seria a primeira da lista, que numa
    // lista ordenada por valor — os fusos horários — é a ponta e não o meio.
    const declared = entry.options?.default;

    return {
        name,
        options,
        fallback: String(declared ?? options[0]?.value ?? ""),
    };
}

/**
 * Um valor escolhido de uma lista, com o significado à vista.
 *
 * Existe porque cada fornecedor trazia o seu campo para fazer isto -- e sem um genérico, uma
 * definição com `options` caía num número solto: o utilizador via "2" sem saber que 2 é
 * "Baixo", e o significado ficava só na cabeça de quem escreveu o adaptador.
 */
function selectInput(entry, desired) {
    const { name, options, fallback } = selectOptions(entry);
    const current = String(desired?.[name] ?? fallback);
    const choices = options
        .map((option) => {
            const value = String(option.value);
            return `<option value="${esc(value)}"${value === current ? " selected" : ""}>${esc(String(option.label ?? value))}</option>`;
        })
        .join("");

    // Sem rótulo: o cartão da configuração já mostra o nome por cima, e um rótulo aqui
    // repetia-o — ou, pior, mostrava o nome do campo do protocolo, que está em inglês.
    return `<select class="form-select" data-config-field="${esc(name)}">${choices}</select>`;
}

export const INPUTS = {
    select: {
        control: selectInput,
        read: (section) => {
            const node = section.querySelector("select[data-config-field]");
            if (!node) return {};
            const raw = String(node.value ?? "");
            // Os valores do protocolo são inteiros; só um que não pareça número é que passa
            // como texto.
            return { [node.dataset.configField]: raw !== "" && !Number.isNaN(Number(raw)) ? Number(raw) : raw };
        },
        defaults: (entry) => {
            const { name, options } = selectOptions(entry);
            return { [name]: entry.options?.default ?? options[0]?.value ?? 0 };
        },
    },
    toggle: {
        render: (entry, desired, meta) => toggleInput(entry, desired, meta?.protocol),
        read: (section) => {
            const field = firstFieldName(section);
            return { [field]: readCheckbox(section, field) };
        },
        defaults: (entry, protocol) => ({
            [protocol === "wonlex-json" && entry.fields?.[0] === "switchState"
                ? "enabled"
                : entry.fields?.[0] || "value"]: true,
        }),
    },
    number: {
        control: numberControl,
        read: (section) => {
            const field = firstFieldName(section);
            return { [field]: readNumber(section, field) };
        },
        // Parte de onde a escala parte: um campo que vai de 1 a 6 aberto em zero oferece um
        // valor que o aparelho recusa.
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
    // Uma acção não tem campo nenhum: o que se envia é o próprio pedido. Sem `render`, o
    // cartão sabe que pode desenhar a versão de uma linha.
    action: {
        read: () => ({}),
    },
};
