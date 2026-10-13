import { normalizeAlarmClockRecurrenceKind } from "./alarm-fields.js";

/** Os valores guardados nas formas que os campos desenham, em funções puras sem DOM nem API. */

export function normalizeAlarmClockItems(desired) {
    const base = Array.isArray(desired) ? desired : desired?.items ?? [];
    const items = Array.isArray(base) ? base : [base];
    return items
        .filter((item) => item && typeof item === "object")
        .map((item) => normalizeAlarmClockItem(item));
}

function normalizeAlarmClockItem(item) {
    const recurrenceKind = normalizeAlarmClockRecurrenceKind(
        item.recurrence?.kind ?? item.kind ?? "once",
    );

    return {
        label: String(item.label ?? ""),
        time: String(item.time ?? item.alarmTime ?? item.reminderTime ?? ""),
        enabled: boolValue(item.enabled ?? item.switchState, true),
        url: String(item.url ?? ""),
        type: item.type === undefined || item.type === null
            ? undefined
            : (parseInt(String(item.type), 10) || 1),
        recurrence: recurrenceKind === "custom"
            ? {
                    kind: "custom",
                    days: normalizeAlarmClockDaySelection(item.recurrence?.days ?? ""),
                }
            : { kind: recurrenceKind },
    };
}

export function defaultAlarmClockItem(withType = false, recurrence = "once") {
    const recurrenceKind = normalizeAlarmClockRecurrenceKind(recurrence);
    return withType
        ? {
                time: "",
                enabled: true,
                type: 1,
                recurrence: { kind: recurrenceKind },
            }
        : {
                time: "",
                enabled: true,
                recurrence: { kind: recurrenceKind },
            };
}

export function normalizeAlarmClockDaySelection(value) {
    if (Array.isArray(value)) {
        return value
            .map((day) => parseInt(String(day), 10))
            .filter((day) => Number.isFinite(day) && day >= 1 && day <= 7)
            .map((day) => String(day));
    }

    const raw = String(value || "").trim();
    if (raw === "") {
        return [];
    }

    if (/^[1-7]+$/.test(raw)) {
        return raw.split("").filter(Boolean);
    }

    if (/^[01]{7}$/.test(raw)) {
        const days = [];
        raw.split("").forEach((bit, index) => {
            if (bit === "1") {
                days.push(String(index === 0 ? 7 : index));
            }
        });
        return days;
    }

    return raw
        .replace(/[^1-7]/g, "")
        .split("")
        .filter(Boolean);
}

/** O `fallback` é o que fica quando o valor não decide nada -- e não «desligado». */
export function boolValue(value, fallback = false) {
    if (typeof value === "boolean") {
        return value;
    }
    // Há aparelhos que mandam o nível em vez do bit, e qualquer nível é estar ligado.
    if (typeof value === "number") {
        return value !== 0;
    }
    const text = String(value ?? "").trim().toLowerCase();
    if (["1", "true", "yes", "on"].includes(text)) {
        return true;
    }
    if (["0", "false", "no", "off"].includes(text)) {
        return false;
    }
    return fallback;
}

export function formatReminderTime(value) {
    const digits = String(value || "").replace(/[^0-9]/g, "");
    if (digits.length !== 4) {
        return "";
    }
    return `${digits.slice(0, 2)}:${digits.slice(2, 4)}`;
}
