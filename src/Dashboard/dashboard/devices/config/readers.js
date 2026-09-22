import { esc } from "../../format.js";
import { normalizePhoneControl } from "../../phone.js";
import { protocolPhonebookConstraints } from "./protocol-catalog.js";

/**
 * Os leitores que servem qualquer campo: transformam uma secção de configuração desenhada no
 * payload que vai para o dispositivo. O que só um campo lê vive com esse campo.
 *
 * São a metade que toca no DOM, e a metade onde um erro é silencioso: um campo perdido
 * parece uma gravação com sucesso. A ida e volta está no `config-payload-roundtrip.test.js`.
 */

export function firstFieldName(section) {
    return (
        section.querySelector("[data-config-field]")?.dataset.configField ||
        "value"
    );
}

export function readCheckbox(section, field) {
    return (
        section.querySelector(`[data-config-field="${CSS.escape(field)}"]`)
            ?.checked || false
    );
}

export function readNumber(section, field) {
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

export function readText(section, field) {
    return String(
        section.querySelector(`[data-config-field="${CSS.escape(field)}"]`)
            ?.value || "",
    ).trim();
}

export function readTextArray(section, field) {
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

export function readUniquePhoneArray(section, field, label) {
    const values = readPhoneArray(section, field);
    const duplicates = findDuplicateValues(values);
    if (duplicates.length > 0) {
        throw new Error(`${label}: números repetidos não são permitidos`);
    }

    return values;
}

export function readPhone(section, field) {
    const control = section.querySelector(
        `[data-phone-control][data-config-field="${CSS.escape(field)}"]`,
    );
    return control ? normalizePhoneControl(control) : "";
}

export function readContacts(section) {
    const phonebookConstraints = protocolPhonebookConstraints(
        String(section.dataset.configProtocol || ""),
    );
    const nameMaxLength = parseInt(
        String(section.dataset.phonebookNameMaxLength || phonebookConstraints.name?.maxLength || "0"),
        10,
    ) || 0;
    const contacts = [];
    let sawIncompleteRow = false;

    for (const row of section.querySelectorAll("[data-repeat-row=\"contacts\"]")) {
        const name = readContactName(row, nameMaxLength);
        const phone = readContactPhone(row);
        if (name === "" && phone === "") {
            continue;
        }
        if (name === "" || phone === "") {
            sawIncompleteRow = true;
            if (!phonebookConstraints.allowPartialRows) {
                throw new Error("Nome e telefone são obrigatórios");
            }
            continue;
        }

        contacts.push({ name, phone });
    }

    if (phonebookConstraints.allowPartialRows && contacts.length === 0 && sawIncompleteRow) {
        throw new Error("Nome e telefone são obrigatórios");
    }

    return contacts;
}

function findDuplicateValues(values) {
    const seen = new Set();
    const duplicates = new Set();
    for (const value of values) {
        if (seen.has(value)) {
            duplicates.add(value);
            continue;
        }
        seen.add(value);
    }

    return [...duplicates];
}

function readContactName(row, maxLength) {
    const input = row.querySelector("[data-repeat-field=\"name\"]");
    const value = String(input?.value || "").trim();
    if (maxLength > 0 && unicodeLength(value) > maxLength) {
        throw new Error(`O nome deve ter no máximo ${maxLength} caracteres`);
    }

    return value;
}

function readContactPhone(row) {
    return normalizePhoneControl(
        row.querySelector("[data-phone-control][data-repeat-field=\"phone\"]"),
    );
}

function unicodeLength(value) {
    return Array.from(String(value || "")).length;
}

export function jsonInput(desired) {
    return `
        <div>
            <label class="form-label-sm">JSON</label>
            <textarea class="form-control font-monospace" rows="4" data-config-field="json">${esc(JSON.stringify(desired, null, 2))}</textarea>
        </div>`;
}

export function readJson(section) {
    const textarea = section.querySelector("[data-config-field=\"json\"]");
    if (!textarea) {
        return {};
    }

    try {
        return JSON.parse(textarea.value || "{}");
    } catch {
        throw new Error("JSON inválido para esta configuração");
    }
}
