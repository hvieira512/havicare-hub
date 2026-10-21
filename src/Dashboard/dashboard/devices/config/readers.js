import { esc } from "../../format.js";
import { normalizePhoneControl } from "../../phone.js";

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
    const parsed = Number(input?.value ?? "");
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

/** Um contacto leva nome e telefone; uma linha só com um dos dois recusa-se em vez de se perder. */
export function readContacts(section) {
    const contacts = [];
    for (const row of section.querySelectorAll("[data-repeat-row=\"contacts\"]")) {
        const name = readContactName(row);
        const phone = readContactPhone(row);
        if (name === "" && phone === "") {
            continue;
        }
        if (name === "" || phone === "") {
            throw new Error("Nome e telefone são obrigatórios");
        }

        contacts.push({ name, phone });
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

function readContactName(row) {
    const input = row.querySelector("[data-repeat-field=\"name\"]");
    const value = String(input?.value || "").trim();
    const maxLength = input?.maxLength > 0 ? input.maxLength : 0;
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
