import {formatFourPTouchAlarmTime, normalizeAlarmClockRecurrenceKind} from "./alarm-fields.js";

export const WONLEX_MEDICATION_PERIODS = [
    {index: 0, key: "Morning", label: "Manhã", defaultTime: "08:00"},
    {index: 1, key: "Midday", label: "Meio-dia", defaultTime: "12:00"},
    {index: 2, key: "Night", label: "Noite", defaultTime: "19:00"},
    {index: 3, key: "Before sleep", label: "Antes de dormir", defaultTime: "22:00"},
];

/**
 * Trazer os valores de configuração guardados para as formas de que os campos desenham.
 *
 * Funções puras sobre dados simples -- sem DOM e sem API --, e é isso que as separa de quem
 * as consome a desenhar e de quem as desfaz a ler.
 */

export function normalizeWonlexMedicationPlans(desired) {
    const source = desired?.plans ?? desired?.plan ?? desired;
    if (Array.isArray(source)) {
        return source
            .filter((plan) => plan && typeof plan === "object")
            .map((plan) => normalizeWonlexMedicationPlan(plan));
    }
    return source && typeof source === "object" && Object.keys(source).length > 0
        ? [normalizeWonlexMedicationPlan(source)]
        : [];
}

export function normalizeWonlexMedicationPlan(plan = {}) {
    const drugTime = plan.drugTime && typeof plan.drugTime === "object"
        ? plan.drugTime
        : {};
    const alarmClock = drugTime.alarmClock && typeof drugTime.alarmClock === "object"
        ? drugTime.alarmClock
        : {};
    let periods = Array.isArray(drugTime.checkboxes)
        ? drugTime.checkboxes
            .map((value) => parseInt(String(value), 10))
            .filter((value) => Number.isFinite(value) && value >= 0 && value <= 3)
        : [];
    if (periods.length === 0) {
        periods = WONLEX_MEDICATION_PERIODS
            .filter((period) => String(alarmClock[period.key] || "").trim() !== "")
            .map((period) => period.index);
    }

    return {
        drugType: parseInt(String(plan.drugType ?? 0), 10) || 0,
        drugName: String(plan.drugName || ""),
        drugDose: numericValue(plan.drugDose, 0),
        drugUnit: String(plan.drugUnit ?? "0"),
        drugStartTime: String(plan.drugStartTime || ""),
        drugEndTime: String(plan.drugEndTime || ""),
        drugInterval: numericValue(plan.drugInterval, 1),
        alarmClock,
        periods: periods.length > 0 ? periods : [0],
        mealTiming: parseInt(String(drugTime.radio ?? 0), 10) === 1 ? 1 : 0,
    };
}

export function defaultWonlexMedicationPlan() {
    return normalizeWonlexMedicationPlan({
        drugType: 0,
        drugName: "",
        drugDose: 1,
        drugUnit: "0",
        drugStartTime: "",
        drugEndTime: "",
        drugInterval: 1,
        drugTime: {
            alarmClock: {Morning: "08:00"},
            checkboxes: [0],
            radio: 0,
        },
    });
}

export function normalizeFourPTouchAlarms(desired) {
    const base = desired?.items ?? desired?.alarms ?? desired?.alarmClock ?? desired?.fields ?? desired;

    if (Array.isArray(base)) {
        if (base.length && typeof base[0] === "string") {
            return base.map((item) => normalizeFourPTouchAlarmItem(item));
        }

        return base.map((item) => normalizeFourPTouchAlarmItem(item));
    }

    if (typeof base === "string" && base.trim() !== "") {
        return base.split(",").map((item) => normalizeFourPTouchAlarmItem(item));
    }

    if (base && typeof base === "object") {
        return [normalizeFourPTouchAlarmItem(base)];
    }

    return [];
}

function normalizeFourPTouchAlarmItem(item) {
    if (typeof item === "string") {
        return parseFourPTouchAlarmString(item);
    }

    if (!item || typeof item !== "object") {
        return { time: "", enabled: true, mode: 1, custom: "" };
    }

    const recurrenceKind = String(item.recurrence?.kind ?? item.kind ?? "").trim().toLowerCase();
    const mode = recurrenceKind
        ? ({ once: 1, daily: 2, custom: 3 }[recurrenceKind] || 1)
        : (parseInt(
            String(item.mode ?? item.frequency ?? item.reminderFrequency ?? 1),
            10,
        ) || 1);

    return {
        time: formatFourPTouchAlarmTime(
            item.time ?? item.alarmTime ?? item.reminderTime ?? "",
        ),
        enabled: boolValue(item.enabled ?? item.switchState, true),
        mode: [1, 2, 3].includes(mode) ? mode : 1,
        custom:
            mode === 3
                ? normalizeFourPTouchAlarmDays(
                      item.recurrence?.days ?? item.custom ?? item.days ?? item.reminderCustom ?? "",
                  )
                : "",
    };
}

function parseFourPTouchAlarmString(value) {
    const parts = String(value || "").trim().split("-");
    if (parts.length < 3) {
        return { time: "", enabled: true, mode: 1, custom: "" };
    }

    const mode = parseInt(String(parts[2] || "1"), 10) || 1;

    return {
        time: formatFourPTouchAlarmTime(parts[0]),
        enabled: boolValue(parts[1], true),
        mode: [1, 2, 3].includes(mode) ? mode : 1,
        custom:
            mode === 3
                ? normalizeFourPTouchAlarmDays(parts.slice(3).join("-"))
                : "",
    };
}

export function normalizeFourPTouchAlarmDays(value) {
    const raw = String(value || "").trim();
    if (raw === "") {
        return "";
    }

    if (/^[01]{7}$/.test(raw)) {
        return raw;
    }

    const selected = new Set(raw.replace(/[^0-6]/g, "").split(""));
    return ["0", "1", "2", "3", "4", "5", "6"]
        .map((day) => (selected.has(day) ? "1" : "0"))
        .join("");
}

export function isFourPTouchAlarmDaySelected(mask, day) {
    const normalizedMask = String(mask || "").trim();
    const index = parseInt(String(day || ""), 10);
    if (!Number.isFinite(index) || index < 0 || index > 6) {
        return false;
    }

    if (/^[01]{7}$/.test(normalizedMask)) {
        return normalizedMask.charAt(index) === "1";
    }

    return false;
}

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
              recurrence: {kind: recurrenceKind},
          }
        : {
              time: "",
              enabled: true,
              recurrence: {kind: recurrenceKind},
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

export function boolValue(value, fallback = false) {
    if (value === true || value === 1 || value === "1") {
        return true;
    }
    if (value === false || value === 0 || value === "0") {
        return false;
    }
    return fallback;
}

export function numericValue(value, fallback = 0) {
    const parsed = parseFloat(String(value ?? ""));
    return Number.isFinite(parsed) ? parsed : fallback;
}

export function formatReminderTime(value) {
    const digits = String(value || "").replace(/[^0-9]/g, "");
    if (digits.length !== 4) {
        return "";
    }
    return `${digits.slice(0, 2)}:${digits.slice(2, 4)}`;
}
