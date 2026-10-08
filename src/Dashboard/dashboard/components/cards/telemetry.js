import { displayPersonIndex, fieldLabel, fieldValue } from "../../format.js";
import { fallLabel, zoneLabel } from "../../domain.js";
import { html, raw } from "../../html.js";
import { compactDetails, joinMarkup } from "./shared.js";
import { connectivityIcon, connectivityValue } from "./gateway.js";
import { diaperMoistureBody, diaperMoistureRowValue } from "./diaper.js";
import { cyclePosition, cycleRunout, doseLabel, medicationAlarmContent } from "./medication.js";
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

/** Os cartões de telemetria. As peças genéricas de interface estão em `components/`. */

/**
 * O ícone e a cor de cada capacidade: `[ícone, tom]`. O nome vem do catálogo, pelo
 * `capabilityLabel`; o tom fica aqui porque uma cor é escolha de apresentação.
 */
const CARD_STYLE = {
    positions: ["fa-location-crosshairs", "info"],
    vitals: ["fa-heart-pulse", "success"],
    position_minute_stats: ["fa-chart-column", "secondary"],
    vitals_minute_stats: ["fa-chart-line", "secondary"],
    heart_rate: ["fa-heart-pulse", "danger"],
    blood_pressure: ["fa-stethoscope", "danger"],
    blood_oxygen: ["fa-droplet", "info"],
    blood_sugar: ["fa-vial", "warning"],
    // Os outros dois analitos do sangue ficam na família do açúcar: o mesmo tom, e um frasco
    // para cada um em vez do genérico.
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
    // Um índice e um gasto metabólico são medidas de bem-estar e não gravidades: ficam no
    // azul da casa, como a atividade e o sono, e não no vermelho nem no âmbar.
    stress: ["fa-face-grimace", "primary"],
    // `fa-fire` e não `fa-fire-flame-simple`: a chama simples é uma gota, e o mosaico do MET
    // fica ao lado do oxigénio no sangue, que já é uma gota.
    met: ["fa-fire", "primary"],
    location: ["fa-location-dot", "success"],
    sleep: ["fa-bed", "primary"],
    sleep_state: ["fa-bed", "primary"],
    sleep_quality: ["fa-bed", "primary"],
    sleep_apnea: ["fa-bed-pulse", "warning"],
    // A versão de firmware é ficha técnica e não uma leitura: fica no cinzento do sistema.
    firmware_version: ["fa-microchip", "secondary"],
    proximity: ["fa-tower-broadcast", "info"],
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
    // Eventos e não leituras, mas o tom sai daqui como o de todos os outros.
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
    unknown: ["fa-bell", ""],
};

/** O ícone de uma capacidade. Sem entrada na tabela, o genérico. */
export function cardIcon(type) {
    return CARD_STYLE[type]?.[0] || "fa-circle-info";
}

/**
 * O tamanho do lote, que é o que há a dizer de uma onda sem escalar nenhum. O `sampleCount`
 * vem primeiro: o `VeepooBridge::forDashboard()` troca as amostras por ele antes de guardar.
 */
function sampleCount(data) {
    const count = Number.isFinite(data?.sampleCount)
        ? data.sampleCount
        : (Array.isArray(data?.samples) ? data.samples.length : 0);

    return count === 0 ? "Sem amostras" : `${count} amostras`;
}

/**
 * O que um renderizador pode devolver. Tudo é opcional menos o `value`, e o que não vier tem
 * omissão: o ícone sai do `CARD_STYLE`, o `rowValue` cai no `value`, o `span` em 6.
 *
 * @typedef {object} CardContent
 * @property {string} value          o valor principal, no cartão e na linha
 * @property {string} [rowValue]     o que a linha da lista mostra, quando não cabe o `value`
 * @property {string} [icon]         só quando a leitura o escolhe -- um gateway com fios
 * @property {string} [details]      a segunda linha, em texto ou em pastilhas
 * @property {"text"|"chips"} [detailsKind]
 * @property {string} [detailsTitle] o texto inteiro, para o `title` do que se corta
 * @property {number} [span]         colunas do mosaico; 12 pede a linha toda
 * @property {string} [body]         marcação própria por baixo do valor, para quem pede 12
 */

/** @type {Record<string, (data: object, meta?: object) => CardContent>} */
const UPLINK_CARD_RENDERERS = {
    // O radar manda as mesmas chaves e formas que um relógio e usa os cartões dele.
    presence: (data) => ({
        value: presenceValue(data),
        details: presenceDetails(data),
        // A única que devolve pastilhas em vez de texto: o texto corta-se com reticências, e
        // estas já vêm limitadas pelo `PRESENCE_CHIP_LIMIT`.
        detailsKind: "chips",
        detailsTitle: presenceDetailsTitle(data),
    }),
    sleep_state: (data) => ({
        value: fieldValue("sleep_state", data?.state),
    }),
    // Um cartão próprio para não cair no genérico: os relógios mandam texto livre, e o
    // dispensador dois bytes que saem em hexadecimal.
    firmware_version: (data) => ({
        value: String(data?.version ?? "").trim() || "—",
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
    // O que interessa numa toma é como ela acabou, e numa avaria é qual foi.
    medication_intake: (data) => ({
        value: fieldValue("result", data.result),
    }),
    medication_alarm_status: (data) => medicationAlarmContent(data),
    // A mudança de uma dose: um acontecimento, e por isso lê-se numa linha. A hora identifica
    // a dose melhor do que o número do alarme, que é vocabulário do aparelho e não do dia.
    medication_alarm_change: (data) => ({
        value: `${doseLabel(data?.alarm)}: ${fieldValue("state", data?.state)}`,
    }),
    device_config: (data) => deviceConfigContent(data),
    // Um alerta e não uma leitura: o valor diz o que aconteceu, e não leva legenda fixa.
    storage_environment: () => ({
        value: "Temperatura ou humidade fora da gama",
    }),
    device_fault: (data) => ({
        value: fieldValue("fault", data.fault),
    }),
    stress: (data) => ({
        value: data?.score != null ? `${data.score}` : capabilityLabel("stress"),
    }),
    // Equivalentes metabólicos: 1 é o gasto em repouso, e por isso o número vale por si.
    met: (data) => ({
        value: data?.value != null ? `${data.value} MET` : capabilityLabel("met"),
    }),
    cardiac_load: (data) => ({
        value: data?.value != null ? `${data.value}` : capabilityLabel("cardiac_load"),
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
        value: `${data?.count ?? 0} passos`,
        details: compactDetails(data, ["periodSeconds"]),
    }),
    wear_state: (data) => ({
        value:
            { worn: "Ao pulso", not_worn: "Fora do pulso" }[data?.state] ??
            capabilityLabel("wear_state"),
    }),
    // O IMC é o número que resume a medição; o resto cabe nos detalhes.
    body_composition: (data) => ({
        value: data?.bmi != null ? `${data.bmi} IMC` : capabilityLabel("body_composition"),
        details: compactDetails(data, [
            "bodyFatPercent",
            "musclePercent",
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
    // As pontuações são um juízo sobre a noite: o cartão mostra a nota, e a gaveta como ela
    // se decompõe.
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
        details: compactDetails(data, ["qtcMilliseconds", "hrvMilliseconds", "frequencyHz"]),
    }),
    // A VFC é um escalar em milissegundos e não uma série: mostra-se o número.
    hrv: (data) => ({
        value:
            data?.milliseconds != null
                ? `${data.milliseconds} ms`
                : capabilityLabel("hrv"),
    }),
    // Um escalar, ao contrário do sono, do ECG e da PPG, que são séries e se anunciam.
    breath_rate: (data) => ({
        value: `${data.breathsPerMinute ?? "-"} rpm`,
    }),
    // Uma onda sem escalar nenhum: o que se pode dizer dela é o tamanho do lote.
    ppg: (data) => ({
        value: sampleCount(data),
        details: compactDetails(data, ["frequencyHz"]),
    }),
    // Chegam em lote: o que cabe no cartão é quantos são e a média, que é o inverso da
    // frequência cardíaca e portanto o número que denuncia uma leitura absurda.
    rr_interval: (data) => ({
        value: rrIntervalValue(data),
    }),
    help_call: (data) => helpCallContent(data),
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

// Os dois estados do dispensador que não são carga e que por isso o ícone não desenha.
const BATTERY_STATE_LABEL = {
    low: "Bateria fraca",
    absent: "Sem bateria",
};

/**
 * `meta` é o que se sabe sobre a leitura e não está dentro dela: por agora, quando chegou. O
 * ícone vem da tabela, e o renderizador só o escreve quando é diferente.
 */
export function uplinkCardContent(type, data, meta = {}) {
    const rendered = UPLINK_CARD_RENDERERS[type]?.(data, meta) || {
        value: capabilityLabel(type),
        details: compactDetails(data, Object.keys(data).slice(0, 4)),
    };

    return { icon: cardIcon(type), ...rendered };
}

/**
 * O valor reportado de uma configuração: o `device_config` traz um mapa da definição para o
 * que o aparelho diz ter lá dentro.
 */
function deviceConfigContent(data) {
    const settings = Object.entries(data?.settings ?? {});

    return {
        value: settings.length === 1
            ? capabilityLabel(settings[0][0])
            : `${settings.length} definições`,
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
</div>`).join("");

    return html`<div class="reported-settings mt-3">${raw(rows)}</div>`;
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

/**
 * Os intervalos R-R chegam em lote e sem instante próprio: mostra-se a média, cujo inverso é
 * a frequência cardíaca, e um lote absurdo salta à vista.
 */
function rrIntervalValue(data) {
    const intervals = (data?.intervals || [])
        .map((entry) => entry?.milliseconds)
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

/** A cor é do estado, e numa bateria o estado é quanto falta para alguém ter de lá ir. */
function batteryTone({ percent, lowBattery }) {
    if (typeof percent === "number" && percent < 10) return "danger";
    if (lowBattery === true || (typeof percent === "number" && percent < 25)) return "warning";
    return "success";
}

/** Os relógios mandam um bit e o dispensador uma enumeração. O `full` já acabou de carregar. */
const isCharging = (state) => state === 1 || state === "charging";

/**
 * O canto do ícone diz de onde vem a energia: o relâmpago a carregar, a tomada ligado à ficha
 * sem carregar, e nada fora da ficha. O relâmpago manda sobre a tomada.
 */
function batteryBadge(data) {
    if (isCharging(data.chargingState)) return "fa-bolt";

    return data.mainsPowered === true ? "fa-plug" : "";
}

function batteryDetails(data) {
    // A carga e a corrente estão no ícone; ficam os dois estados que ele não sabe desenhar.
    if (data.lowBattery === true) return BATTERY_STATE_LABEL.low;
    const state = BATTERY_STATE_LABEL[data.chargingState] ||
        (data.chargingState == null ? compactDetails(data, ["batteryType"]) : "");

    return joinMarkup([state]);
}

/** A cor da categoria, para o ícone. Sem entrada na tabela, o ícone fica neutro. */
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
