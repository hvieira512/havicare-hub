import { normalizeAlarmClockRecurrenceKind } from "./alarm-fields.js";

export const WONLEX_MEDICATION_PERIODS = [
    { index: 0, key: "Morning", label: "Manhã", defaultTime: "08:00" },
    { index: 1, key: "Midday", label: "Meio-dia", defaultTime: "12:00" },
    { index: 2, key: "Night", label: "Noite", defaultTime: "19:00" },
    { index: 3, key: "Before sleep", label: "Antes de dormir", defaultTime: "22:00" },
];

/** Os valores guardados nas formas que os campos desenham, em funções puras sem DOM nem API. */

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

export const MEDICATION_CONDITIONS = ["hypertension", "diabetes", "cholesterol", "uric_acid"];
export const MEDICATION_DOSE_UNITS = ["tablet", "ampoule", "ml", "mg", "iu", "other"];
export const MEDICATION_MEAL_TIMINGS = ["before_meal", "after_meal"];
export const MEDICATION_PERIODS = ["morning", "midday", "night", "before_sleep"];

/**
 * A forma pública de um plano nos nomes dos campos da Wonlex. Aceita também a já normalizada,
 * porque a lista e a linha normalizam as duas.
 */
export function normalizeWonlexMedicationPlan(plan) {
    const alarmClock = pickObject(plan.alarmClock) ?? {};
    let periods = Array.isArray(plan.periods)
        ? plan.periods.map((value) => parseInt(String(value), 10)).filter((value) => value >= 0 && value <= 3)
        : [];

    for (const entry of Array.isArray(plan.times) ? plan.times : []) {
        const index = MEDICATION_PERIODS.indexOf(String(entry?.period ?? ""));
        if (index < 0) continue;
        alarmClock[WONLEX_MEDICATION_PERIODS[index].key] = String(entry?.time ?? "");
        if (!periods.includes(index)) periods.push(index);
    }

    if (periods.length === 0) {
        periods = WONLEX_MEDICATION_PERIODS
            .filter((period) => String(alarmClock[period.key] || "").trim() !== "")
            .map((period) => period.index);
    }

    const indexOf = (table, value, fallback) => {
        const found = table.indexOf(String(value ?? ""));
        return found < 0 ? fallback : found;
    };

    return {
        drugType: plan.drugType ?? indexOf(MEDICATION_CONDITIONS, plan.condition, 0),
        drugName: String(plan.drugName ?? plan.name ?? ""),
        drugDose: numericValue(plan.drugDose ?? plan.doseCount, 0),
        drugUnit: plan.drugUnit ?? String(indexOf(MEDICATION_DOSE_UNITS, plan.doseUnit, 0)),
        drugStartTime: String(plan.drugStartTime ?? plan.startDate ?? ""),
        drugEndTime: String(plan.drugEndTime ?? plan.endDate ?? ""),
        drugInterval: numericValue(plan.drugInterval ?? plan.intervalDays, 1),
        alarmClock,
        periods: periods.length > 0 ? periods.sort((a, b) => a - b) : [0],
        mealTiming: plan.mealTiming === undefined
            ? 0
            : (parseInt(String(plan.mealTiming), 10) || indexOf(MEDICATION_MEAL_TIMINGS, plan.mealTiming, 0)),
    };
}

const pickObject = (value) => (value && typeof value === "object" ? value : null);

export function defaultWonlexMedicationPlan() {
    return normalizeWonlexMedicationPlan({
        name: "",
        condition: "hypertension",
        doseCount: 1,
        doseUnit: "tablet",
        startDate: "",
        endDate: "",
        intervalDays: 1,
        mealTiming: "before_meal",
        times: [{ time: "08:00", enabled: true, period: "morning", recurrence: { kind: "daily" } }],
    });
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
