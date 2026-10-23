import { displayPersonIndex, fieldLabel, fieldValue, timeOnly } from "../../format.js";
import { fallLabel, zoneLabel } from "../../domain.js";
import { html } from "../../html.js";
import { compactDetails, joinMarkup } from "./shared.js";
import { state } from "../../state.js";
import { connectivityIcon, connectivityValue } from "./gateway.js";
import { diaperMoistureBody, diaperMoistureRowValue } from "./diaper.js";
import { cyclePosition, cycleRunout, doseLabel, doseName, medicationAlarmContent } from "./medication.js";
import { helpCallContent, ncsPagerContent } from "./ncs.js";
import { locationDetails, locationValue } from "./location.js";
import { sleepDetails, sleepQualityValue, sleepValue } from "./sleep.js";
import {
    presenceDetails,
    presenceDetailsTitle,
    presenceValue,
    radarPositionMinuteStatsDetails,
    radarPositionMinuteStatsValue,
    radarVitalsMinuteStatsDetails,
    radarVitalsMinuteStatsValue,
} from "./radar.js";
import { capabilityLabel } from "../../capability-catalog.js";
import { countLabel, valueLabel } from "../../i18n.js";

/** O ícone e o tom de cada capacidade: `[ícone, tom]`. */
const CARD_STYLE = {

    position_minute_stats: ["fa-chart-column", "secondary"],
    vitals_minute_stats: ["fa-chart-line", "secondary"],
    heart_rate: ["fa-heart-pulse", "danger"],
    blood_pressure: ["fa-stethoscope", "danger"],
    blood_oxygen: ["fa-droplet", "info"],
    blood_sugar: ["fa-vial", "warning"],
    uric_acid: ["fa-flask", "warning"],
    blood_lipids: ["fa-vials", "warning"],
    temperature: ["fa-temperature-half", "warning"],
    battery: ["fa-battery-three-quarters", "success"],
    connectivity: ["fa-wifi", "info"],
    motion: ["fa-person-running", "primary"],
    diaper_moisture: ["fa-droplet", "info"],
    diaper_moisture_level: ["fa-percent", "info"],
    diaper_condition: ["fa-baby", "warning"],
    activity: ["fa-person-walking", "primary"],
    steps: ["fa-shoe-prints", "primary"],
    wear_state: ["fa-hand-sparkles", "primary"],
    body_composition: ["fa-weight-scale", "primary"],
    stress: ["fa-face-grimace", "primary"],
    met: ["fa-fire", "primary"],
    location: ["fa-location-dot", "success"],
    sleep: ["fa-bed", "primary"],
    sleep_state: ["fa-bed", "primary"],
    sleep_quality: ["fa-bed", "primary"],
    sleep_apnea: ["fa-bed-pulse", "warning"],
    firmware_version: ["fa-microchip", "secondary"],
    tilt: ["fa-ruler-combined", "secondary"],
    proximity: ["fa-tower-broadcast", "info"],
    proximity_alarm: ["fa-person-walking-arrow-right", "danger"],
    presence: ["fa-location-crosshairs", "success"],
    ecg: ["fa-wave-square", "danger"],
    hrv: ["fa-chart-line", "danger"],
    cardiac_load: ["fa-heart-circle-bolt", "danger"],
    breath_rate: ["fa-lungs", "info"],
    ppg: ["fa-circle-nodes", ""],
    rr_interval: ["fa-stopwatch", "info"],
    "device.connected": ["fa-plug-circle-check", "success"],
    "device.disconnected": ["fa-plug-circle-xmark", "danger"],
    help_call: ["fa-triangle-exclamation", "danger"],
    fall: ["fa-person-falling", "danger"],
    heart_rate_high: ["fa-heart-circle-exclamation", "danger"],
    heart_rate_low: ["fa-heart-circle-minus", "danger"],
    heart_rate_abnormal: ["fa-heart-crack", "danger"],
    breath_rate_high: ["fa-lungs", "warning"],
    breath_rate_low: ["fa-lungs", "warning"],
    apnea: ["fa-lungs", "danger"],
    weak_vital_signs: ["fa-wave-square", "warning"],
    zone_entry: ["fa-door-open", "info"],
    zone_exit: ["fa-door-closed", "info"],
    device_removed: ["fa-hand", "warning"],
    low_battery: ["fa-battery-quarter", "warning"],
    medication_intake: ["fa-pills", "primary"],
    device_fault: ["fa-triangle-exclamation", "warning"],
    medication_alarm_status: ["fa-clock-rotate-left", "primary"],
    device_status: ["fa-arrows-rotate", "secondary"],
    medication_alarm_change: ["fa-pills", "primary"],
    cells_remaining: ["fa-table-cells", "info"],
    storage_environment: ["fa-triangle-exclamation", "danger"],
    ambient_temperature: ["fa-temperature-half", "info"],
    ambient_humidity: ["fa-droplet", "info"],
    reset: ["fa-bell-slash", "warning"],
    weight: ["fa-weight-scale", "primary"],
    body_impedance: ["fa-bolt", "secondary"],
    body_impedance_channel: ["fa-bolt", "secondary"],
    weighing_status: ["fa-scale-balanced", "secondary"],
    unknown: ["fa-bell", ""],
};

const degrees = (value) => Number.isFinite(value)
    ? `${value.toLocaleString("pt-PT", { maximumFractionDigits: 1 })}°`
    : "-";

const oneDecimal = (data) => Object.fromEntries(Object.entries(data ?? {})
    .map(([key, value]) => [key, typeof value === "number" ? Math.round(value * 10) / 10 : value]));

/** O nome com que a pessoa está na lista da balança; quem já saiu dela fica pelo identificador. */
function scaleUserName(id) {
    if (!id) return null;
    const users = state.selectedDetail?.configurations?.scale_users?.items ?? [];
    return users.find((user) => user?.id === id)?.name || id;
}

export function cardIcon(type) {
    return CARD_STYLE[type]?.[0] || "fa-circle-info";
}

/** O tamanho do lote; o `sampleCount` primeiro, porque o `VeepooBridge` troca as amostras por ele. */
function sampleTotal(data) {
    return Number.isFinite(data?.sampleCount)
        ? data.sampleCount
        : (Array.isArray(data?.samples) ? data.samples.length : 0);
}

function sampleCount(data) {
    const count = sampleTotal(data);

    return count === 0 ? "Sem amostras" : `${count} amostras`;
}

/**
 * O que um renderizador devolve: o `value`, e opcionais `rowValue`, `icon`, `details`,
 * `detailsKind` ("text"|"chips"), `detailsTitle`, `span` (12 pede a linha toda) e `body`.
 */

const UPLINK_CARD_RENDERERS = {
    // O radar manda as mesmas chaves e formas que um relógio e usa os cartões dele.
    presence: (data) => ({
        value: presenceValue(data),
        details: presenceDetails(data),
        // A única com pastilhas, que já vêm limitadas pelo `PRESENCE_CHIP_LIMIT`.
        detailsKind: "chips",
        detailsTitle: presenceDetailsTitle(data),
    }),
    sleep_state: (data) => ({
        value: fieldValue("sleep_state", data?.state),
    }),
    // Os relógios mandam texto livre, e o dispensador dois bytes.
    firmware_version: (data) => ({
        value: String(data?.version ?? "").trim() || "—",
        details: compactDetails(data, ["communicationVersion"]),
    }),
    tilt: (data) => ({
        value: `X ${degrees(data?.xDegrees)} · Y ${degrees(data?.yDegrees)}`,
        details: data?.factoryCalibrated ? "Calibrado de fábrica" : "Sem calibração de fábrica",
    }),
    // "Queda" sozinho não distingue uma confirmada de uma suspeita, nem de alguém sentado no chão.
    fall: (data) => ({
        value: fallLabel(data),
        details: personDetails(data),
    }),
    zone_entry: (data) => ({
        value: zoneLabel("zone_entry", data),
        details: personDetails(data),
    }),
    zone_exit: (data) => ({
        value: zoneLabel("zone_exit", data),
        details: personDetails(data),
    }),
    heart_rate_high: (data) => vitalValue(data?.bpm, "bpm", "heart_rate_high"),
    heart_rate_low: (data) => vitalValue(data?.bpm, "bpm", "heart_rate_low"),
    breath_rate_high: (data) => vitalValue(data?.breathsPerMinute, "rpm", "breath_rate_high"),
    breath_rate_low: (data) => vitalValue(data?.breathsPerMinute, "rpm", "breath_rate_low"),
    position_minute_stats: (data) => ({
        value: radarPositionMinuteStatsValue(data),
        details: radarPositionMinuteStatsDetails(data),
    }),
    vitals_minute_stats: (data) => ({
        value: radarVitalsMinuteStatsValue(data),
        details: radarVitalsMinuteStatsDetails(data),
    }),
    heart_rate: (data) => ({
        value: `${data.bpm ?? "-"} bpm`,
    }),
    blood_pressure: (data) => ({
        value: `${data.systolicMmHg ?? "-"} / ${data.diastolicMmHg ?? "-"} mmHg`,
        details: compactDetails(data, ["irregularHeartbeat", "memoryGroup", "fromHistory"]),
    }),
    blood_oxygen: (data) => ({
        value: `${data.spo2Percent ?? "-"}%`,
    }),
    blood_sugar: (data) => ({
        value: `${data.glucoseMgDl ?? "-"} mg/dL`,
    }),
    // Nem toda a leitura traz a corporal: há aparelhos que só amostram a superfície.
    temperature: (data) => ({
        value:
            data.bodyCelsius != null
                ? `${data.bodyCelsius} °C`
                : data.surfaceCelsius != null
                    ? `${data.surfaceCelsius} °C na pele`
                    : "-",
        details: compactDetails(data, ["site"]),
    }),
    ambient_temperature: (data) => ({
        value: data.environmentCelsius != null ? `${data.environmentCelsius} °C` : "-",
    }),
    ambient_humidity: (data) => ({
        value: data.humidityPercent != null ? `${data.humidityPercent}%` : "-",
    }),
    // Quantas doses faltam sair desde a posição do carrossel, com o total por denominador e o
    // nível do aparelho como legenda; a posição vai nos detalhes, para quem carrega o prato.
    cells_remaining: (data) => {
        const cell = data.current != null && data.total != null
            ? `Compartimento ${data.current} de ${data.total}`
            : "";
        const level = data.level != null ? fieldValue("level", data.level) : "";
        const inCycle = cyclePosition(data.current);
        const runout = cycleRunout(data.remaining);

        return {
            value: data.remaining == null
                ? fieldValue("level", data.level)
                : data.remaining === 0
                    ? "Nenhuma por dispensar"
                    : `${data.remaining} por dispensar`,
            // A data primeiro, porque diz quando é preciso recarregar; a posição fica no `title`, em
            // texto, porque quem monta o atributo escapa-o.
            details: joinMarkup(
                [runout ? `Acaba ${runout}` : inCycle || cell, level]
                    .filter(Boolean)
                    .map((part) => html`${part}`),
            ),
            detailsTitle: [runout && `Acaba ${runout}`, inCycle, cell, level].filter(Boolean).join(" · "),
        };
    },
    // As horas chegam em UTC e mostram-se na hora de cá.
    medication_intake: (data) => ({
        value: fieldValue("result", data.result),
        details: joinMarkup([
            html`${doseName(data.slot)}`,
            data.scheduledAt ? html`${fieldLabel("scheduledAt")}: ${timeOnly(data.scheduledAt)}` : "",
            data.takenAt ? html`${fieldLabel("takenAt")}: ${timeOnly(data.takenAt)}` : "",
        ]),
    }),
    medication_alarm_status: (data) => medicationAlarmContent(data),
    // A hora identifica a dose melhor do que o número do alarme, que é vocabulário do aparelho.
    medication_alarm_change: (data) => ({
        value: `${data?.scheduledAt ? timeOnly(data.scheduledAt) : doseLabel(data?.slot)}: ${fieldValue("state", data?.state)}`,
        details: html`${doseName(data?.slot)}`,
    }),
    device_config: (data) => deviceConfigContent(data),
    storage_environment: () => ({
        value: "Temperatura ou humidade fora da gama",
    }),
    device_fault: (data) => ({
        value: fieldValue("fault", data.fault),
    }),
    stress: (data) => ({
        value: data?.stressScore != null ? `${data.stressScore}` : capabilityLabel("stress"),
    }),
    // Equivalentes metabólicos: 1 é o gasto em repouso, e por isso o número vale por si.
    met: (data) => ({
        value: data?.met != null ? `${data.met} MET` : capabilityLabel("met"),
    }),
    cardiac_load: (data) => ({
        value: data?.cardiacLoad != null ? `${data.cardiacLoad}` : capabilityLabel("cardiac_load"),
    }),
    sleep_apnea: (data) => ({
        value:
            data?.episodes != null
                ? `${data.episodes} episódios`
                : capabilityLabel("sleep_apnea"),
        details: compactDetails(data, ["hypoxiaSeconds"]),
    }),
    uric_acid: (data) => ({
        value: data?.umolPerL != null ? `${data.umolPerL} µmol/L` : capabilityLabel("uric_acid"),
    }),
    blood_lipids: (data) => ({
        value:
            data?.totalCholesterolMmolPerL != null
                ? `${data.totalCholesterolMmolPerL} mmol/L`
                : capabilityLabel("blood_lipids"),
        details: compactDetails(data, [
            "triglyceridesMmolPerL",
            "hdlMmolPerL",
            "ldlMmolPerL",
        ]),
    }),
    steps: (data) => ({
        value: `${data?.steps ?? 0} passos`,
        details: compactDetails(data, ["periodSeconds"]),
    }),
    wear_state: (data) => ({
        value:
            { worn: "Ao pulso", not_worn: "Fora do pulso" }[data?.state] ??
            capabilityLabel("wear_state"),
    }),
    weight: (data) => ({
        value: data?.weightKg != null ? `${data.weightKg} kg` : capabilityLabel("weight"),
        details: compactDetails({ ...data, scaleUserId: scaleUserName(data?.scaleUserId) }, ["scaleUserId", "fromHistory"]),
    }),
    body_impedance: (data) => ({
        value: data?.impedance50KhzOhm != null ? `${data.impedance50KhzOhm} Ω` : capabilityLabel("body_impedance"),
        details: compactDetails(data, ["impedance100KhzOhm"]),
    }),
    body_impedance_channel: (data) => ({
        value: data?.impedanceOhm != null ? `Canal ${data.channel} · ${data.impedanceOhm} Ω` : capabilityLabel("body_impedance_channel"),
    }),
    weighing_status: (data) => ({
        value: data?.shuttingDown
            ? "A desligar"
            : [valueLabel("heartRatePhase", data?.heartRatePhase), valueLabel("impedancePhase", data?.impedancePhase), valueLabel("weightPhase", data?.weightPhase)]
                    .find(Boolean) ?? capabilityLabel("weighing_status"),
        details: data?.weightKg ? `${data.weightKg} kg` : "",
    }),
    // O IMC é o número que resume a medição; o resto cabe nos detalhes, com uma casa como na app.
    body_composition: (data) => ({
        value: data?.bmi != null ? `${data.bmi} IMC` : capabilityLabel("body_composition"),
        details: compactDetails(oneDecimal(data), [
            "bodyFatPercent",
            "fatMassKg",
            "subcutaneousFatPercent",
            "musclePercent",
            "muscleMassKg",
            "leanMassKg",
            "bodyWaterPercent",
            "boneMassKg",
            "idealWeightKg",
            "weightControlKg",
            "fatControlKg",
            "muscleControlKg",
            "basalMetabolicRateKcal",
        ]),
    }),
    battery: (data) => ({
        value:
            data.percent != null
                ? `${data.percent}%`
                : data.voltageMv != null
                    ? `${data.voltageMv} mV`
                    : "-",
        icon: batteryIcon(data.percent),
        iconBadge: batteryBadge(data),
        tone: batteryTone(data),
        details: batteryDetails(data),
    }),
    connectivity: (data) => ({
        icon: connectivityIcon(data),
        value: connectivityValue(data),
        details: compactDetails(data, ["signalQuality"]),
    }),
    diaper_moisture: (data) => ({
        // O índice 0-100 chega noutra mensagem e não tem cartão próprio: é o valor deste.
        value:
            data?.index != null
                ? `${data.index}%`
                : capabilityLabel("diaper_moisture"),
        // Numa linha não cabe a tira dos canais; o resumo é quantos passaram o limiar.
        rowValue: diaperMoistureRowValue(data),
        span: 12,
        body: diaperMoistureBody(data),
    }),
    diaper_moisture_level: (data) => ({
        value: data?.index != null ? `${data.index}%` : "-",
    }),
    diaper_condition: (data) => ({
        value:
            {
                clean: "Fralda limpa",
                attention: "Atenção",
                change_required: "Mudança necessária",
            }[data.state] || "Estado desconhecido",
    }),
    activity: (data) => ({
        value: `${data.steps ?? 0} passos`,
        details: compactDetails(data, [
            "distanceMeters",
            "caloriesKcal",
            "exerciseSeconds",
            "standMinutes",
        ]),
    }),
    location: (data, meta) => ({
        value: locationValue(data),
        details: locationDetails(data, meta),
    }),
    sleep: (data) => ({
        value: sleepValue(data),
        details: sleepDetails(data),
    }),
    sleep_quality: (data) => ({
        value: sleepQualityValue(data),
        details: compactDetails(data, [
            "efficiencyScore",
            "fallAsleepScore",
            "durationScore",
            "deepSleepScore",
            "nightWakingScore",
            "insomniaScore",
            "awakeningCount",
            "firstDeepSleepMinutes",
            "nightAwakeMinutes",
            "returnToDeepSleepMeanMinutes",
        ]),
    }),
    // A frequência que o exame apurou, que não é a do sensor ótico; sem ela, o tamanho do lote.
    ecg: (data) => ({
        value: data?.heartRateBpm != null
            ? `${data.heartRateBpm} bpm`
            : sampleCount(data),
        details: compactDetails(
            { ...data, sampleCount: sampleTotal(data) || null },
            ["qtcMilliseconds", "hrvMilliseconds", "frequencyHz", "sampleCount"],
        ),
    }),
    hrv: (data) => ({
        value:
            data?.hrvMilliseconds != null
                ? `${data.hrvMilliseconds} ms`
                : capabilityLabel("hrv"),
    }),
    breath_rate: (data) => ({
        value: `${data.breathsPerMinute ?? "-"} rpm`,
    }),
    ppg: (data) => ({
        value: sampleCount(data),
        details: compactDetails(data, ["frequencyHz"]),
    }),
    rr_interval: (data) => ({
        value: rrIntervalValue(data),
    }),
    help_call: (data) => helpCallContent(data),
    // O máximo da janela é o que fez disparar; o gateway diz onde.
    proximity_alarm: (data) => ({
        value: `${data?.rssiDbm ?? "-"} dBm`,
        details: joinMarkup([
            html`gateway ${String(data?.gatewayId || "")}`,
            html`${countLabel(Number(data?.window?.samples) || 0, "leitura", "leituras")}`,
        ]),
    }),
    motion: (data) => ({
        value:
            data?.magnitudeMg != null
                ? `${data.magnitudeMg} mg`
                : capabilityLabel("motion"),
        details: compactDetails(data, ["xMg", "yMg", "zMg"]),
    }),
    reset: (data) => ncsPagerContent("reset", data),
    "device.connected": () => ({ value: "Ligado" }),
    "device.disconnected": () => ({ value: "Desligado" }),
};

/** `meta` é o que se sabe da leitura fora dela, como quando chegou. */
export function uplinkCardContent(type, data, meta = {}) {
    const rendered = UPLINK_CARD_RENDERERS[type]?.(data, meta) || {
        value: capabilityLabel(type),
        details: compactDetails(data, Object.keys(data).slice(0, 4)),
    };

    return { icon: cardIcon(type), ...rendered };
}

/** O `device_config` traz um mapa da definição para o que o aparelho diz ter lá dentro. */
function deviceConfigContent(data) {
    const settings = Object.entries(data?.settings ?? {});

    return {
        value: settings.length === 1
            ? capabilityLabel(settings[0][0])
            : countLabel(settings.length, "definição", "definições"),
        details: joinMarkup(settings.map(([key]) => html`${capabilityLabel(key)}`)),
        span: 12,
        body: reportedSettingsBody(settings),
    };
}

function reportedSettingsBody(settings) {
    if (settings.length === 0) {
        return "";
    }

    const rows = settings.map(([key, value]) => html`<div class="reported-setting d-flex justify-content-between gap-3" data-setting="${key}">
<span class="text-secondary">${capabilityLabel(key)}</span>
<span class="text-end">${settingSummary(key, value)}</span>
</div>`);

    return html`<div class="reported-settings mt-3">${rows}</div>`;
}

const HOUR = (hour, minute) => `${String(hour ?? 0).padStart(2, "0")}:${String(minute ?? 0).padStart(2, "0")}`;

/** O que uma definição reportada tem lá dentro, sem o nome do campo a repetir o da definição. */
function settingSummary(key, value) {
    if (value === null || typeof value !== "object") {
        return fieldValue("value", value);
    }

    // As duas compostas não se resumem campo a campo: uma lista de alarmes e um par de horas
    // lêem-se como o que são.
    if (key === "medication_reminders") {
        const marked = (value.plans || [])
            .filter((plan) => plan?.enabled !== false)
            .map((plan) => HOUR(plan?.hour, plan?.minute))
            .sort();
        return marked.length === 0 ? "Sem alarmes marcados" : marked.join(" · ");
    }
    if (key === "do_not_disturb") {
        const window = `${HOUR(value.startHour, value.startMinute)}–${HOUR(value.endHour, value.endMinute)}`;
        return value.enabled === false ? `Desligado (${window})` : window;
    }

    return Object.entries(value)
        .map(([field, inner]) => (inner !== null && typeof inner === "object"
            ? `${fieldLabel(field)}: ${Object.values(inner).length}`
            : fieldValue(field, inner)))
        .join(" · ");
}

/** A média dos intervalos R-R: o inverso é a frequência cardíaca, e um lote absurdo salta à vista. */
function rrIntervalValue(data) {
    const intervals = (data?.intervals || [])
        .map((entry) => entry?.rrIntervalMilliseconds)
        .filter((value) => typeof value === "number");

    if (intervals.length === 0) {
        return capabilityLabel("rr_interval");
    }

    const mean = Math.round(
        intervals.reduce((sum, value) => sum + value, 0) / intervals.length,
    );

    return `${intervals.length} × ${mean} ms`;
}

/** Os cinco degraus que o Font Awesome free desenha. Sem percentagem, nenhum deles mente. */
function batteryIcon(percent) {
    if (typeof percent !== "number") return "fa-battery-three-quarters";
    if (percent >= 95) return "fa-battery-full";
    if (percent >= 63) return "fa-battery-three-quarters";
    if (percent >= 38) return "fa-battery-half";
    if (percent >= 13) return "fa-battery-quarter";
    return "fa-battery-empty";
}

function batteryTone({ percent, lowBattery }) {
    if (typeof percent === "number" && percent < 10) return "danger";
    if (lowBattery === true || (typeof percent === "number" && percent < 25)) return "warning";
    return "success";
}

/** O canto do ícone: o relâmpago a carregar, a tomada na ficha sem carregar, nada fora dela. */
function batteryBadge(data) {
    if (data.charging === true) return "fa-bolt";

    return data.mainsPowered === true ? "fa-plug" : "";
}

function batteryDetails(data) {
    // A carga e a corrente estão no ícone; ficam os dois estados que ele não sabe desenhar.
    if (data.lowBattery === true) return valueLabel("lowBattery", true);

    return joinMarkup([compactDetails(data, ["reportReason"])]);
}

export function cardTone(type) {
    return CARD_STYLE[type]?.[1] || "";
}

/** Quem o radar viu. Escapado, porque os `details` entram sem escapar. */
function personDetails(data) {
    return data?.personIndex === undefined ? "" : html`Pessoa ${displayPersonIndex(data.personIndex)}`;
}

/** O valor que levantou o evento; sem ele, o nome. */
function vitalValue(value, unit, type) {
    return { value: value == null ? capabilityLabel(type) : `${value} ${unit}` };
}
