import { DICTIONARY, UNTRANSLATED, valueLabel } from "./i18n.js";

export const esc = (value) =>
    String(value ?? "").replace(
        /[&<>"']/g,
        (char) =>
            ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                "\"": "&quot;",
                "'": "&#039;",
            })[char],
    );

const relative = new Intl.RelativeTimeFormat("pt-PT", { numeric: "auto", style: "short" });

/** Há quanto tempo, na maior unidade que cabe: segundos, minutos, horas ou dias. */
export const ago = (value) => {
    if (!value) return "nunca";
    const seconds = Math.max(0, Math.floor((Date.now() - Date.parse(value)) / 1000));
    if (seconds < 60) return relative.format(-seconds, "second");
    if (seconds < 3600) return relative.format(-Math.floor(seconds / 60), "minute");
    if (seconds < 86400) return relative.format(-Math.floor(seconds / 3600), "hour");
    return relative.format(-Math.floor(seconds / 86400), "day");
};

/** A data de um instante, ou `null` quando não é instante nenhum. */
const parsedDate = (value) => {
    const parsed = value ? Date.parse(value) : NaN;
    return Number.isNaN(parsed) ? null : new Date(parsed);
};

export const when = (value) => {
    if (!value) return "";
    return parsedDate(value)?.toLocaleString("pt-PT") ?? String(value);
};

/** Só a hora: a data vive no cabeçalho do dia, e repeti-la em cada linha come a coluna. */
export const timeOnly = (value) => {
    if (!value) return "";
    return parsedDate(value)?.toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" }) ?? String(value);
};

/** O dia de um instante, para agrupar. Local e não UTC: o corte é à meia-noite de cá. */
export const dayKey = (value) => {
    const date = parsedDate(value);
    return date ? `${date.getFullYear()}-${date.getMonth() + 1}-${date.getDate()}` : "";
};

const DAY_MONTH = { day: "numeric", month: "long" };
const relativeDay = new Intl.RelativeTimeFormat("pt-PT", { numeric: "auto" });

/** O cabeçalho de um dia, com «Hoje» e «Ontem». */
export const dayLabel = (value) => {
    const date = parsedDate(value);
    if (!date) return "";
    const today = new Date();
    const midnight = (day) => new Date(day.getFullYear(), day.getMonth(), day.getDate()).getTime();
    const daysAgo = Math.round((midnight(today) - midnight(date)) / 86400000);
    const sameYear = date.getFullYear() === today.getFullYear();
    const dayMonth = date.toLocaleDateString("pt-PT", sameYear ? DAY_MONTH : { ...DAY_MONTH, year: "numeric" });
    if (daysAgo === 0 || daysAgo === 1) {
        const word = relativeDay.format(-daysAgo, "day");
        return `${word.charAt(0).toUpperCase()}${word.slice(1)}, ${dayMonth}`;
    }
    return dayMonth;
};

export { fieldLabel } from "./i18n.js";

const FIELD_UNIT = {
    afterMinutes: "min",
    afterSeconds: "s",
    backM: "m",
    exerciseMaxBpm: "bpm",
    exerciseMinBpm: "bpm",
    exerciseThresholdBpm: "bpm",
    frontM: "m",
    heightCm: "cm",
    intervalHours: "h",
    intervalMinutes: "min",
    intervalSeconds: "s",
    leftM: "m",
    maxBpm: "bpm",
    minBpm: "bpm",
    rightM: "m",
    sleepGoalMinutes: "min",
    targetMinutes: "min",
    thresholdBpm: "bpm",
    thresholdCelsius: "°C",
    thresholdPercent: "%",
    accuracyMeters: "m",
    distanceMeters: "m",
    distanceKm: "km",
    caloriesKcal: "kcal",
    exerciseSeconds: "s",
    periodSeconds: "s",
    standMinutes: "min",
    basalMetabolicRateKcal: "kcal",
    fatMassKg: "kg",
    leanMassKg: "kg",
    muscleMassKg: "kg",
    waterMassKg: "kg",
    boneMassKg: "kg",
    idealWeightKg: "kg",
    weightControlKg: "kg",
    fatControlKg: "kg",
    muscleControlKg: "kg",
    proteinMassKg: "kg",
    bodyFatPercent: "%",
    musclePercent: "%",
    subcutaneousFatPercent: "%",
    bodyWaterPercent: "%",
    skeletalMusclePercent: "%",
    proteinPercent: "%",
    firstDeepSleepMinutes: "min",
    nightAwakeMinutes: "min",
    returnToDeepSleepMeanMinutes: "min",
    totalDurationMinutes: "min",
    durationMinutes: "min",
    bpm: "bpm",
    weightKg: "kg",
    impedance50KhzOhm: "Ω",
    impedance100KhzOhm: "Ω",
    impedanceOhm: "Ω",
    breathsPerMinute: "rpm",
    spo2Percent: "%",
    systolicMmHg: "mmHg",
    diastolicMmHg: "mmHg",
    surfaceCelsius: "°C",
    environmentCelsius: "°C",
    bodyCelsius: "°C",
    temperatureCelsius: "°C",
    lowCelsius: "°C",
    highCelsius: "°C",
    humidityPercent: "%",
    wifiSignalDbm: "dBm",
    gsmSignalDbm: "dBm",
    signalStrengthDbm: "dBm",
    xMg: "mg",
    yMg: "mg",
    zMg: "mg",
    magnitudeMg: "mg",
    walkingDistanceMeters: "m",
    walkingTimeS: "s",
    realTimeBreathsPerMinute: "rpm",
    avgBreathsPerMinute: "rpm",
    realTimeHeartRateBpm: "bpm",
    avgHeartRateBpm: "bpm",
    meditationTimeS: "s",
    inBedTimeS: "s",
    standingTimeS: "s",
    multiplayerTimeS: "s",
    umolPerL: "µmol/L",
    totalCholesterolMmolPerL: "mmol/L",
    triglyceridesMmolPerL: "mmol/L",
    hdlMmolPerL: "mmol/L",
    ldlMmolPerL: "mmol/L",
    headingDegrees: "°",
    heartRateBpm: "bpm",
    qtcMilliseconds: "ms",
    hrvMilliseconds: "ms",
    rrIntervalMilliseconds: "ms",
    frequencyHz: "Hz",
    glucoseMgDl: "mg/dL",
    met: "MET",
    rssiDbm: "dBm",
};

/** A unidade de um campo, pelo nome dele; vazia quando não a tem. */
export const fieldUnit = (key) => FIELD_UNIT[key] ?? "";

const numberFormat = new Intl.NumberFormat("pt-PT", { maximumFractionDigits: 2 });

export const formatNumber = (value) => numberFormat.format(value);

export const fieldValue = (key, value) => {
    if (value === undefined || value === null || value === "") return "-";

    if (typeof value === "boolean") return value ? "Sim" : "Não";

    const raw = String(value);
    const translated = valueLabel(key, raw);
    if (translated) return translated;

    if (typeof value === "number") {
        return FIELD_UNIT[key] ? `${formatNumber(value)} ${FIELD_UNIT[key]}` : formatNumber(value);
    }

    // Só o que parece enumeração fica por traduzir: um ISO 8601 ou texto livre passam intactos.
    return /^[a-z][a-z0-9]*(_[a-z0-9]+)*$/.test(raw) ? UNTRANSLATED : raw;
};

export { commandLabel } from "./i18n.js";

/** Por que razão um pedido falhou; um código desconhecido aparece como veio. */
export const commandError = (code) => DICTIONARY.commandErrors[String(code ?? "")] ?? String(code ?? "");

export const eventTime = (payload) => {
    const time = Date.parse(payload?.occurredAt || payload?.recordedAt || "");
    return Number.isNaN(time) ? 0 : time;
};

export const rowPayload = (row) =>
    row?.payload && typeof row.payload === "object" ? row.payload : row;

/** As pessoas que o radar vê são numeradas a partir de zero, e ninguém conta assim. */
export const displayPersonIndex = (value) => {
    const index = Number(value);
    return Number.isInteger(index) ? String(index + 1) : "-";
};
