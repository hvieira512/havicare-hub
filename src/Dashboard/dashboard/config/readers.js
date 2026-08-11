import { esc } from "../format.js";
import { normalizePhoneControl } from "../phone.js";
import { protocolPhonebookConstraints } from "./protocol-catalog.js";
import {
    formatFourPTouchAlarmTime,
    normalizeAlarmClockRecurrenceKind,
    readAlarmClockDays,
    readFourPTouchAlarmDays,
} from "./alarm-fields.js";

/**
 * Readers turn a rendered configuration section back into the payload sent to
 * the device.
 *
 * They are the half of config.js that touches the DOM, and the half where a
 * mistake is silent -- a dropped field looks like a successful save. The round
 * trip is characterised in tests/Frontend/config-payload-roundtrip.test.js.
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

export function readPhoneArray(section, field) {
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

    for (const row of section.querySelectorAll('[data-repeat-row="contacts"]')) {
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

        contacts.push({name, phone});
    }

    if (phonebookConstraints.allowPartialRows && contacts.length === 0 && sawIncompleteRow) {
        throw new Error("Nome e telefone são obrigatórios");
    }

    return contacts;
}

export function findDuplicateValues(values) {
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
    const input = row.querySelector('[data-repeat-field="name"]');
    const value = String(input?.value || "").trim();
    if (maxLength > 0 && unicodeLength(value) > maxLength) {
        throw new Error(`O nome deve ter no máximo ${maxLength} caracteres`);
    }

    return value;
}

function readContactPhone(row) {
    return normalizePhoneControl(
        row.querySelector('[data-phone-control][data-repeat-field="phone"]'),
    );
}

function unicodeLength(value) {
    return Array.from(String(value || "")).length;
}

export function readAlarmClock(section) {
    const items = Array.from(
        section.querySelectorAll('[data-repeat-row="alarm_clock"]'),
    )
        .map((row) => {
            const recurrenceKind = normalizeAlarmClockRecurrenceKind(
                row.querySelector('[data-alarm-clock-field="recurrenceKind"]:checked')?.value || "once",
            );
            const item = {
                time: String(
                    row.querySelector('[data-alarm-clock-field="time"]')?.value || "",
                ).trim(),
                enabled:
                    row.querySelector('[data-alarm-clock-field="enabled"]')?.checked ||
                    false,
                recurrence: {kind: recurrenceKind},
            };

            const labelField = row.querySelector('[data-alarm-clock-field="label"]');
            if (labelField) {
                const label = String(labelField.value || "").trim();
                if (label !== "") {
                    item.label = label;
                }
            }

            const urlField = row.querySelector('[data-alarm-clock-field="url"]');
            if (urlField) {
                const url = String(urlField.value || "").trim();
                if (url !== "") {
                    item.url = url;
                }
            }

            const typeField = row.querySelector('[data-alarm-clock-field="type"]:checked');
            if (typeField) {
                const type = parseInt(String(typeField.value || "1"), 10);
                if (Number.isFinite(type)) {
                    item.type = type;
                }
            }

            if (item.recurrence.kind === "custom") {
                const days = readAlarmClockDays(row);
                item.recurrence.days = days;
                if (!Array.isArray(days) || days.length === 0) {
                    throw new Error("Selecione pelo menos um dia para a recorrência personalizada");
                }
            }

            return item;
        })
        .filter((item) => item.time !== "");

    return {items};
}

export function readTakePills(section) {
    const groups = Array.from(
        section.querySelectorAll("[data-takepills-reminder-group]"),
    );
    const number = groups.length;
    const voiceEnabled = readCheckbox(section, "voiceEnabled");
    const voiceData = readText(section, "voiceData");
    const voiceMimeType = readText(section, "voiceMimeType");

    const reminderSettings = groups.map((group) => {
            const frequency =
                parseInt(
                    String(
                        group.querySelector(
                            '[data-takepills-field="reminderFrequency"]',
                        )?.value ?? "1",
                    ),
                    10,
                ) || 1;
            return {
                time:
                    group.querySelector(
                        '[data-takepills-field="reminderTime"]',
                    )?.value || "",
                enabled:
                    group.querySelector(
                        '[data-takepills-field="reminderEnabled"]',
                    )?.checked || false,
                frequency,
                custom:
                    frequency === 3
                        ? group.querySelector(
                              '[data-takepills-field="reminderCustom"]',
                          )?.value || ""
                        : "",
        };
    });

    const payload = {
        reminderSettings,
        number,
        reminderText: readText(section, "reminderText"),
    };

    if (voiceEnabled && voiceData !== "") {
        payload.voiceData = voiceData;
        if (voiceMimeType !== "") {
            payload.voiceMimeType = voiceMimeType;
        }
    } else if (!voiceEnabled) {
        payload.voiceData = "";
    }

    return payload;
}

export function readFourPTouchAlarms(section) {
    return Array.from(section.querySelectorAll("[data-fourptouch-alarm-row]"))
        .map((row) => {
            const mode = parseInt(
                String(row.querySelector('[data-fourptouch-field="mode"]')?.value || "1"),
                10,
            ) || 1;
            const alarm = {
                time: formatFourPTouchAlarmTime(
                    row.querySelector('[data-fourptouch-field="time"]')?.value || "",
                ),
                enabled:
                    row.querySelector('[data-fourptouch-field="enabled"]')?.checked ||
                    false,
                mode,
                custom: mode === 3 ? readFourPTouchAlarmDays(row) : "",
            };

            if (mode === 3 && alarm.custom === "0000000") {
                throw new Error("Selecione pelo menos um dia para o alarme personalizado");
            }

            return alarm;
        })
        .filter((alarm) => alarm.time !== "");
}

export function jsonInput(desired) {
    return `
        <div>
            <label class="form-label form-label-sm">JSON</label>
            <textarea class="form-control font-monospace" rows="4" data-config-field="json">${esc(JSON.stringify(desired, null, 2))}</textarea>
        </div>`;
}

export function readJson(section) {
    const textarea = section.querySelector('[data-config-field="json"]');
    if (!textarea) {
        return {};
    }

    try {
        return JSON.parse(textarea.value || "{}");
    } catch {
        throw new Error("JSON inválido para esta configuração");
    }
}
