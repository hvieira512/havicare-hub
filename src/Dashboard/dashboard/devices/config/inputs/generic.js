import { esc, fieldLabel } from "../../../format.js";
import { field } from "../../../widgets.js";
import { renderPhoneControl } from "../../../phone.js";
import { protocolPhonebookConstraints } from "../protocol-catalog.js";
import { boolValue } from "../normalizers.js";
import { enabledSwitch, nextUid, numberField } from "./shared.js";
import {
    firstFieldName,
    readCheckbox,
    readContacts,
    readNumber,
    readPhone,
    readPhoneArray,
    readText,
} from "../readers.js";

/**
 * Os campos que mais do que um fornecedor declara: interruptores, números, texto, telefones e
 * listas de contactos. Um campo aqui é desenhado da mesma maneira venha de onde vier -- o que
 * muda entre protocolos é o nome nativo, e disso trata a definição, não o desenho.
 */

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

export function numberInput(entry, desired) {
    const key = entry.fields?.[0] || "value";
    const isWonlexMeasurementInterval =
        entry.command === "deviceMeasuringFrequency" && key === "interval";
    // A escala vem da definição quando ela a declara -- o tom de pele vai de 1 a 6, e partir
    // de zero oferecia um valor que o aparelho recusa.
    const { min = 0, max = "", label = "" } = entry.options ?? {};
    const value = desired[key] ?? (isWonlexMeasurementInterval ? 60 : min);
    return field(
        // O nome do campo vem do protocolo e está em inglês. Quando a definição traz uma
        // etiqueta, é ela que se mostra.
        label || fieldLabel(key),
        numberField(key, value, { min, max }),
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
            <div class="col-md-4">${enabledSwitch(boolValue(desired.enabled, true), "mt-4")}</div>
            ${field(
                "Intervalo (minutos)",
                numberField("intervalMinutes", desired.intervalMinutes ?? 60),
                { cls: "col-md-8" },
            )}
        </div>`;
}

export function resetActionInput(_entry, _desired) {
    return `
        <div>
            <div class="alert alert-warning small py-2 px-3 mb-3">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                Esta ação é enviada imediatamente para o dispositivo e não pode ser desfeita.
            </div>
        </div>`;
}

export function requestActionInput(entry) {
    return `
        <div>
            <div class="alert alert-info small py-2 px-3 mb-3">
                <i class="fa-solid fa-circle-info me-2"></i>
                ${esc(entry.label || "Ação")} é enviada sem parâmetros adicionais.
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

/**
 * Os descritores dos campos partilhados.
 *
 * Cada tipo de campo declara aqui as suas quatro faces juntas -- desenhar, ler de volta, o
 * valor inicial e a legenda. Eram quatro mapas separados indexados pela mesma chave, e nada
 * garantia que ficassem alinhados: uma entrada em falta não dava erro, dava um campo genérico.
 */
/**
 * As opções que a definição declara para um campo, já normalizadas.
 *
 * O catálogo traz `options: { campo: [{value, label}] }`, que é o mesmo formato que a
 * sensibilidade de queda dos relógios usava no seu campo próprio.
 */
function selectOptions(entry) {
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
export function selectInput(entry, desired) {
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

/**
 * A mesma escolha, mas toda à vista.
 *
 * Serve as enumerações curtas em que a ordem diz alguma coisa -- o volume do dispensador tem
 * quatro posições e a escala está invertida, `0` é o mais alto. Numa lista fechada vê-se uma
 * de cada vez e a escala não se lê. O nome é único por grupo porque dois grupos na mesma
 * página com o mesmo nome comportam-se como um só.
 */
export function buttonGroupInput(entry, desired) {
    const { name, options, fallback } = selectOptions(entry);
    const current = String(desired?.[name] ?? fallback);
    const group = nextUid(`cfg-${name}`);

    const buttons = options
        .map((option) => {
            const value = String(option.value);
            const id = `${group}-${value}`;
            return `<input type="radio" class="btn-check" name="${esc(group)}" id="${esc(id)}"
                    value="${esc(value)}" data-config-field="${esc(name)}"${value === current ? " checked" : ""}>
                <label class="btn btn-outline-secondary" for="${esc(id)}">${esc(String(option.label ?? value))}</label>`;
        })
        .join("");

    return `<div class="btn-group flex-wrap" role="group">${buttons}</div>`;
}

export const INPUTS = {
    buttonGroup: {
        render: buttonGroupInput,
        read: (section) => {
            const node = section.querySelector("input[type=radio][data-config-field]:checked");
            if (!node) return {};
            const value = String(node.value ?? "");
            return {
                [node.dataset.configField]:
                    value !== "" && !Number.isNaN(Number(value)) ? Number(value) : value,
            };
        },
        defaults: (entry) => {
            const { name, options } = selectOptions(entry);
            return { [name]: entry.options?.default ?? options[0]?.value ?? 0 };
        },
    },
    select: {
        render: selectInput,
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
        render: numberInput,
        read: (section) => {
            const field = firstFieldName(section);
            return { [field]: readNumber(section, field) };
        },
        defaults: (entry) => ({ [entry.fields?.[0] || "value"]: 0 }),
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
    requestAction: {
        render: requestActionInput,
        read: () => ({}),
        help: () => "sem parâmetros",
    },
    resetAction: {
        render: resetActionInput,
        read: () => ({}),
    },
    list: {
        render: (entry, desired) => listInput(entry, desired, "numbers", entry.label || "Lista"),
        read: (section) => {
            const limit = parseInt(section.dataset.configLimit || "3", 10) || 3;
            return { numbers: readPhoneArray(section, "numbers").slice(0, limit) };
        },
        defaults: () => ({ numbers: ["", "", ""] }),
        help: (entry) => (entry.limit || 0) > 0 ? `limite ${entry.limit}` : "",
    },
    contacts: {
        render: contactsInput,
        read: (section) => ({ contacts: readContacts(section) }),
        defaults: () => ({ contacts: [{ name: "", phone: "" }] }),
        help: (entry) => (entry.limit || 0) > 0 ? `limite ${entry.limit}` : "",
    },
};
