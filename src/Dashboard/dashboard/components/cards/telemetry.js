import { fieldLabel, fieldValue } from "../../format.js";
import { DETECTION_TYPE_LABEL } from "../../domain.js";
import { html, raw } from "../../html.js";
import { compactDetails } from "./shared.js";
import { connectivityIcon, connectivityValue } from "./gateway.js";
import { diaperMoistureBody, diaperMoistureRowValue } from "./diaper.js";
import { cyclePosition, doseLabel, medicationAlarmContent } from "./medication.js";
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
    alarm: ["fa-triangle-exclamation", "danger"],
    vitals_alarm: ["fa-heart-crack", "danger"],
    presence_event: ["fa-door-open", "info"],
    medication_intake: ["fa-pills", "primary"],
    device_fault: ["fa-triangle-exclamation", "warning"],
    medication_alarm_status: ["fa-clock-rotate-left", "primary"],
    medication_alarm_change: ["fa-pills", "primary"],
    cells_remaining: ["fa-table-cells", "info"],
    storage_environment: ["fa-triangle-exclamation", "danger"],
    humidity: ["fa-droplet", "info"],
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
    // Sem isto caía no genérico, que põe a etiqueta da capacidade no lugar do valor -- e em
    // inglês, porque a etiqueta vem da chave. A forma varia com o fabricante: os relógios
    // mandam texto livre, o dispensador manda dois bytes que saem em hexadecimal.
    firmware_version: (data) => ({
        value: String(data?.version ?? "").trim() || "—",
    }),
    // O tipo específico vai no valor: "Queda" não distingue uma queda de alguém no chão.
    fall: (data) => ({
        value: detectionValue(data),
        details: detectionDetails(data),
    }),
    vitals_alarm: (data) => ({
        value: detectionValue(data),
        details: detectionDetails(data),
    }),
    presence_event: (data) => ({
        value: detectionValue(data),
        details: detectionDetails(data),
    }),
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
    // Nem toda a leitura traz a corporal: há aparelhos que só amostram a superfície, e o
    // dispensador mede a divisão onde está em vez de medir alguém.
    temperature: (data) => ({
        value:
            data.bodyCelsius != null
                ? `${data.bodyCelsius} °C`
                : data.surfaceCelsius != null
                    ? `${data.surfaceCelsius} °C na pele`
                    : data.environmentCelsius != null
                        ? `${data.environmentCelsius} °C`
                        : "-",
    }),
    humidity: (data) => ({
        value: data.humidityPercent != null ? `${data.humidityPercent}%` : "-",
    }),
    // Quantas doses faltam, que é a pergunta que se faz a um dispensador; o total é o
    // denominador que lhe dá escala. O nível é o juízo do aparelho sobre esse mesmo número --
    // é ele que sabe que 4 de 28 já é pouco -- e por isso vem como legenda e não como cartão
    // à parte a dizer a mesma coisa sem número nenhum.
    // O `remaining` é `carregados − posição`: quantas doses faltam sair a partir de onde o
    // carrossel está, e não quantos compartimentos ainda têm comprimidos. Ao lado do total
    // lia-se como a segunda coisa, que é outra e é falsa.
    //
    // A posição vai nos detalhes porque é o que se precisa para carregar o prato: sem ela,
    // quem põe a medicação não sabe em que compartimento o aparelho vai pegar a seguir.
    cells_remaining: (data) => {
        const cell = data.current != null && data.total != null
            ? `Compartimento ${data.current} de ${data.total}`
            : "";
        const level = data.level != null ? fieldValue("level", data.level) : "";
        const inCycle = cyclePosition(data.current);

        return {
            value: data.remaining == null
                ? fieldValue("level", data.level)
                : data.remaining === 0
                    ? "Nenhuma por dispensar"
                    : `${data.remaining} por dispensar`,
            // A leitura em dias primeiro, porque é a que se encontra no prato; o número cru
            // fica na gaveta, para o cartão não mentir se o autocolante e o plano não
            // corresponderem.
            details: [inCycle || cell, level].filter(Boolean).join(" · "),
            detailsTitle: [inCycle, cell, level].filter(Boolean).join(" · "),
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
    // Um alerta e não uma leitura: só chega quando dispara, e por isso o valor diz o que
    // aconteceu em vez de dizer em que estado se está. A legenda fixa que aqui estava — «
    // Temperatura e humidade, medidas pelo aparelho» — repetia-se linha após linha sem nunca
    // mudar, que é o contrário de um detalhe.
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
    alarm: (data) => ({
        icon: "fa-triangle-exclamation",
        value: alarmValue(data),
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
    // A VFC é um escalar em milissegundos e não uma série: anunciá-la como "Dados de VFC"
    // escondia o número que já vinha na mensagem.
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

// A mesma pastilha das configurações; o tom vazio deixa-a no azul neutro da marca.
// Os relógios mandam um bit, e o dispensador manda uma enumeração com cinco estados. As duas
// convivem na mesma tabela porque a pergunta é a mesma; sem as cinco, o cartão do dispensador
// ficava sem nada a dizer sobre a carga.
const BATTERY_CHARGING_STATE_LABEL = {
    1: "A carregar",
    0: "Não está a carregar",
    charging: "A carregar",
    full: "Carregada",
    normal: "Não está a carregar",
    low: "Bateria fraca",
    absent: "Sem bateria",
};

// O alarme traz um só motivo; a etiqueta é a única coisa que o cartão mostra.
const ALARM_REASON_LABEL = {
    sos: "SOS",
    low_battery: "Bateria fraca",
    fall: "Queda detetada",
    watch_removed: "Relógio removido",
    geofence_exit: "Saiu da zona segura",
    geofence_entry: "Entrou na zona segura",
    abnormal_heart_rate: "Frequência cardíaca anormal",
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
 * O valor reportado de uma configuração.
 *
 * O `device_config` traz um mapa: a chave é a definição e o valor é o que o aparelho diz ter
 * lá dentro. Sem isto caía no cartão genérico e saía como «[object Object]».
 */
function deviceConfigContent(data) {
    const settings = Object.entries(data?.settings ?? {});

    return {
        value: settings.length === 1
            ? capabilityLabel(settings[0][0])
            : `${settings.length} definições`,
        details: settings.map(([key]) => capabilityLabel(key)).join(" · "),
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
 * Os intervalos R-R chegam em lote e sem instante próprio, e por isso não há um valor
 * único para mostrar. A média é o que permite conferir a leitura de relance: o seu inverso
 * é a frequência cardíaca, e um lote absurdo salta à vista sem abrir a mensagem.
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

function batteryDetails(data) {
    // A corrente vem com a bateria no dispensador: «ligado à corrente» e «a carregar» são a
    // mesma pergunta feita de dois lados, e separá-las punha-as a uma linha de distância.
    const mains = data.mainsPowered == null
        ? ""
        : data.mainsPowered
            ? "Ligado à corrente"
            : "Sem corrente";
    const charging = BATTERY_CHARGING_STATE_LABEL[data.chargingState] ||
        compactDetails(data, ["batteryType"]);

    return [charging, mains].filter(Boolean).join(" · ");
}

/** A cor da categoria, para o ícone. Sem entrada na tabela, o ícone fica neutro. */
export function cardTone(type) {
    return CARD_STYLE[type]?.[1] || "";
}

function alarmValue(data) {
    return ALARM_REASON_LABEL[data?.reason] ?? "Alarme";
}

function detectionValue(data) {
    return (
        DETECTION_TYPE_LABEL[String(data?.detectionType || "")] ||
        fieldLabel(String(data?.detectionType || "unknown"))
    );
}

/**
 * O grau separa um aviso de um perigo. O `info` não se mostra: é o grau de um acontecimento
 * que não é alarme nenhum.
 *
 * Escapado: os `details` são injectados sem escapar, e o `detectionLevel` vem do radar sem
 * passar por ninguém.
 */
function detectionDetails(data) {
    const level = String(data?.detectionLevel || "");
    return level === "" || level === "info"
        ? ""
        : html`${fieldValue("detectionLevel", level)}`;
}
