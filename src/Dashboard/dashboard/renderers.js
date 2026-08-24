import {
    ago,
    commandLabel,
    esc,
    eventTime,
    featureLabel,
    fieldLabel,
    rowPayload,
    titleize,
    when,
} from "./format.js";

const COMMAND_FEATURE_RULES = [
    ["heart", "heart_rate"],
    ["blood pressure", "blood_pressure"],
    ["oxygen", "blood_oxygen"],
    ["bo", "blood_oxygen"],
    ["temp", "temperature"],
    ["location", "location"],
    ["sleep", "sleep"],
    ["ecg", "ecg"],
    ["hrv", "hrv"],
    ["breath", "breath_rate"],
    ["ppg", "ppg"],
    ["rr", "rr_interval"],
];

const REQUEST_CARD_CONTENT_BY_TYPE = {
    positions: {icon: "fa-location-crosshairs", value: "Posições"},
    vitals: {icon: "fa-heart-pulse", value: "Sinais vitais"},
    position_minute_stats: {
        icon: "fa-chart-column",
        value: "Estatísticas de posições por minuto",
    },
    vitals_minute_stats: {
        icon: "fa-chart-line",
        value: "Estatísticas de sinais vitais por minuto",
    },
    heart_rate: {icon: "fa-heart-pulse", value: "Frequência cardíaca"},
    blood_pressure: {icon: "fa-stethoscope", value: "Tensão arterial"},
    blood_oxygen: {icon: "fa-droplet", value: "Oxigénio no sangue"},
    blood_sugar: {icon: "fa-vial", value: "Glicemia"},
    temperature: {icon: "fa-temperature-half", value: "Temperatura"},
    battery: {icon: "fa-battery-three-quarters", value: "Bateria"},
    connectivity: {icon: "fa-wifi", value: "Conectividade"},
    motion: {icon: "fa-person-running", value: "Movimento"},
    diaper_moisture: {icon: "fa-droplet", value: "Humidade da fralda"},
    diaper_moisture_level: {icon: "fa-percent", value: "Nível de humidade"},
    diaper_condition: {icon: "fa-baby", value: "Estado da fralda"},
    activity: {icon: "fa-person-walking", value: "Atividade"},
    location: {icon: "fa-location-dot", value: "Localização"},
    sleep: {icon: "fa-bed", value: "Sono"},
    ecg: {icon: "fa-wave-square", value: "ECG"},
    hrv: {icon: "fa-chart-line", value: "VFC"},
    breath_rate: {icon: "fa-lungs", value: "Frequência respiratória"},
    ppg: {icon: "fa-circle-nodes", value: "PPG"},
    rr_interval: {icon: "fa-stopwatch", value: "Intervalo RR"},
    "device.connected": {icon: "fa-plug-circle-check", value: "Ligado"},
    "device.disconnected": {icon: "fa-plug-circle-xmark", value: "Desligado"},
    help_call: {icon: "fa-triangle-exclamation", value: "Chamada de ajuda"},
    reset: {icon: "fa-bell-slash", value: "Cancelado"},
    unknown: {icon: "fa-bell", value: "Desconhecido"},
};

const UPLINK_CARD_RENDERERS = {
    positions: (data) => ({
        icon: "fa-location-crosshairs",
        value: radarPositionsValue(data),
        details: radarPositionsDetails(data),
    }),
    position: (data) => ({
        icon: "fa-location-crosshairs",
        value: radarPositionsValue(data),
        details: radarPositionsDetails(data),
    }),
    vitals: (data) => ({
        icon: "fa-heart-pulse",
        value: radarVitalsValue(data),
        details: radarVitalsDetails(data),
    }),
    heartbreath: (data) => ({
        icon: "fa-heart-pulse",
        value: radarVitalsValue(data),
        details: radarVitalsDetails(data),
    }),
    position_minute_stats: (data) => ({
        icon: "fa-chart-column",
        value: radarPositionMinuteStatsValue(data),
        details: radarPositionMinuteStatsDetails(data),
    }),
    minute_stats: (data) => ({
        icon: "fa-chart-column",
        value: radarPositionMinuteStatsValue(data),
        details: radarPositionMinuteStatsDetails(data),
    }),
    vitals_minute_stats: (data) => ({
        icon: "fa-chart-line",
        value: radarVitalsMinuteStatsValue(data),
        details: radarVitalsMinuteStatsDetails(data),
    }),
    hbstatics: (data) => ({
        icon: "fa-chart-line",
        value: radarVitalsMinuteStatsValue(data),
        details: radarVitalsMinuteStatsDetails(data),
    }),
    heart_rate: (data) => ({icon: "fa-heart-pulse", value: `${data.bpm ?? "-"} bpm`}),
    blood_pressure: (data) => ({
        icon: "fa-stethoscope",
        value: `${data.systolicMmHg ?? "-"} / ${data.diastolicMmHg ?? "-"} mmHg`,
    }),
    blood_oxygen: (data) => ({
        icon: "fa-droplet",
        value: `${data.spo2Percent ?? "-"}%`,
    }),
    blood_sugar: (data) => ({icon: "fa-vial", value: `${data.glucoseMgDl ?? "-"} mg/dL`} ),
    temperature: (data) => ({
        icon: "fa-temperature-half",
        value: `${data.bodyCelsius ?? "-"} °C`,
    }),
    battery: (data) => ({
        icon: "fa-battery-three-quarters",
        value: data.percent != null ? `${data.percent}%` : (data.voltageMv != null ? `${data.voltageMv} mV` : "-"),
        details: batteryDetails(data),
    }),
    connectivity: (data) => ({
        icon: connectivityIcon(data),
        value: connectivityValue(data),
        details: compactDetails(data, ["signalQuality"]),
    }),
    diaper_moisture: (data) => ({
        icon: "fa-droplet",
        value: featureLabel("diaper_moisture"),
        span: 12,
        body: diaperMoistureBody(data),
    }),
    diaper_moisture_level: (data) => ({
        icon: "fa-percent",
        value: data?.index != null ? `${data.index}` : "-",
        span: 12,
        body: diaperMoistureLevelBody(data),
    }),
    diaper_condition: (data) => ({
        icon: "fa-baby",
        value:
            ({
                clean: "Fralda limpa",
                attention: "Atenção",
                change_required: "Mudança necessária",
            })[data.state] || "Estado desconhecido",
    }),
    activity: (data) => ({
        icon: "fa-person-walking",
        value: `${data.steps ?? 0} passos`,
        details: compactDetails(data, [
            "distanceMeters",
            "caloriesKcal",
            "exerciseSeconds",
            "standMinutes",
        ]),
    }),
    location: (data) => ({
        icon: "fa-location-dot",
        value:
        data.lat && data.lon ? `${data.lat}, ${data.lon}` : "Atualização de localização",
        details: compactDetails(data, [
            "source",
            "gpsValid",
            "speedKmh",
            "accuracyMeters",
        ]),
    }),
    alarm: (data) => ({
        icon: "fa-triangle-exclamation",
        value: alarmValue(data),
        details: compactDetails(data, ["code", "lowBattery", "fall", "wearingNotice"]),
    }),
    heartbeat: (data) => ({
        icon: "fa-signal",
        value: "Sinal de vida",
        details: compactDetails(data, [
            "batteryPercent",
            "gsmSignal",
            "satelliteCount",
            "steps",
            "workMode",
        ]),
    }),
    sleep: () => ({icon: "fa-bed", value: "Dados de sono"}),
    ecg: () => ({icon: "fa-wave-square", value: "Dados de ECG"}),
    hrv: () => ({icon: "fa-chart-line", value: "Dados de VFC"}),
    breath_rate: () => ({
        icon: "fa-lungs",
        value: "Dados de frequência respiratória",
    }),
    ppg: () => ({icon: "fa-circle-nodes", value: "Dados de PPG"}),
    rr_interval: (data) => ({
        icon: "fa-stopwatch",
        value: "Intervalo RR",
        details: compactDetails(data, ["intervalMs"]),
    }),
    help_call: (data) => helpCallContent(data),
    motion: (data) => ({
        icon: "fa-person-running",
        value: data?.magnitudeMg != null ? `${data.magnitudeMg} mg` : featureLabel("motion"),
        details: compactDetails(data, ["xMg", "yMg", "zMg"]),
    }),
    reset: () => ncsPagerContent("reset"),
    "device.connected": () => ({icon: "fa-plug-circle-check", value: "Ligado"}),
    "device.disconnected": () => ({icon: "fa-plug-circle-xmark", value: "Desligado"}),
};

const STATUS_BADGE_CLASS = {
    queued: "text-bg-secondary",
    sent: "text-bg-primary",
    waiting: "text-bg-warning",
    acked: "text-bg-success",
    failed: "text-bg-danger",
    dropped: "text-bg-danger",
};

const STATUS_BADGE_LABEL = {
    queued: "em fila",
    sent: "enviado",
    waiting: "à espera",
    acked: "confirmado",
    failed: "falhou",
    dropped: "descartado",
    superseded: "substituído",
    unknown: "desconhecido",
};

const NCS_PAGER_EVENT_VALUE = {
    help_call: "Chamada de ajuda",
    reset: "Cancelado",
};

const NCS_PAGER_EVENT_ICON = {
    help_call: "fa-triangle-exclamation",
    reset: "fa-bell-slash",
};

const BATTERY_CHARGING_STATE_LABEL = {
    1: "A carregar",
    0: "Não está a carregar",
};

const ALARM_VALUE_BY_PRIORITY = [
    ["sos", "SOS"],
    ["fall", "Queda detetada"],
    ["lowBattery", "Bateria fraca"],
];

export function modelImageHtml(modelInfo) {
    const label =
        modelInfo?.commercial_name ||
        modelInfo?.commercialName ||
        modelInfo?.internal_model ||
        modelInfo?.internalModel ||
        modelInfo?.model ||
        "Modelo";
    return modelInfo?.image
        ? `<img src="${esc(modelInfo.image)}" class="object-fit-contain" alt="${esc(label)}" style="width:40px;height:40px;">`
        : '<i class="fa-solid fa-microchip fa-xl text-secondary" style="width:40px"></i>';
}

export function modelPreviewHtml(modelInfo, label = "Modelo") {
    const imageLabel =
        modelInfo?.commercial_name ||
        modelInfo?.commercialName ||
        modelInfo?.internal_model ||
        modelInfo?.internalModel ||
        modelInfo?.model ||
        label;
    return modelInfo?.image
        ? `<img src="${esc(modelInfo.image)}" class="object-fit-contain" alt="${esc(imageLabel)}">`
        : `<div class="text-center text-secondary"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${esc(label)}</div></div>`;
}

export function renderButtonGroup(
    container,
    items,
    selected,
    action,
    valueKey = "value",
    labelKey = "label",
) {
    container.innerHTML = items.length
        ? items
        .map((item) => {
            const value = String(item[valueKey] ?? "");
            const label = String(item[labelKey] ?? value);
            return `<button type="button" class="btn btn-sm ${value === selected ? "btn-primary" : "btn-outline-primary"}" data-action="${esc(action)}" data-value="${esc(value)}">${esc(label)}</button>`;
        })
        .join("")
        : '<div class="text-secondary small py-2">Sem opções disponíveis</div>';
}

/**
 * O estado vazio de um painel.
 *
 * Texto e nao caixa: um painel vazio dentro de um cartao branco ganhava uma segunda
 * moldura cinzenta a dizer que nao ha nada, e a moldura lia-se como conteudo.
 */
export function emptyPanel(text) {
    return `<div class="text-secondary py-3">${esc(text)}</div>`;
}

function commandFeature(command) {
    if (command.feature) return command.feature;
    const haystack =
        `${command.command || ""} ${command.label || ""}`.toLowerCase();
    for (const [needle, feature] of COMMAND_FEATURE_RULES) {
        if (haystack.includes(needle)) return feature;
    }
    return "device_config";
}

/**
 * A casca de um cartao de telemetria: o icone, o titulo, e o corpo que quem chama traz.
 *
 * Era a mesma marcacao escrita em dois sitios -- os pedidos ao dispositivo e os eventos
 * NCS -- e a cor por categoria vivia nas duas copias. O icone passa a identificar a
 * categoria sozinho: a cor fica reservada ao estado, que e o que a pastilha diz.
 */
export function telemetryCard({span = 6, icon, title, tooltip = "", body = ""}) {
    const tip = tooltip
        ? ` data-bs-toggle="tooltip" data-bs-trigger="hover focus" data-bs-placement="top"`
          + ` data-bs-title="${esc(tooltip)}" aria-label="${esc(tooltip)}" tabindex="0"`
        : "";

    return `
        <div class="col-12 col-md-${span}">
        <div class="card h-100">
        <div class="card-body">
        <div class="d-flex align-items-center gap-3 min-w-0">
        <div class="telemetry-card-icon"${tip}>
        <i class="fa-solid ${esc(icon)}"></i>
        </div>
        <div class="fw-semibold text-truncate flex-grow-1 min-w-0" title="${esc(title)}">${esc(title)}</div>
        </div>
        ${body}
        </div>
        </div>
        </div>`;
}

function requestCardContent(type) {
    return REQUEST_CARD_CONTENT_BY_TYPE[type] || {
        icon: "fa-circle-info",
        value: featureLabel(type),
    };
}

export function uplinkCardContent(type, data) {
    return (
        UPLINK_CARD_RENDERERS[type]?.(data) || {
            icon: "fa-circle-info",
            value: featureLabel(type),
            details: compactDetails(data, Object.keys(data).slice(0, 4)),
        }
    );
}

// A W6R press carries which kind of press it was; an NCS pager does not.
const HELP_CALL_PRESS_MODES = ["single", "double", "long"];

const PRESS_TYPE_LABEL = {
    single: "toque simples",
    double: "toque duplo",
    long: "toque longo",
};

// What separates the modes is how many presses, or how long one lasts, so the
// icons say exactly that.
const HELP_CALL_PRESS_ICON = {
    single: "fa-1",
    double: "fa-2",
    long: "fa-stopwatch",
};

function helpCallContent(data) {
    const base = ncsPagerContent("help_call");
    const pressType = PRESS_TYPE_LABEL[String(data?.pressType || "")];

    return pressType === undefined
        ? base
        : {...base, value: `${base.value} (${pressType})`};
}

function ncsPagerContent(type) {
    const value = NCS_PAGER_EVENT_VALUE[type] || featureLabel(type);
    const icon = NCS_PAGER_EVENT_ICON[type] || "fa-bell";
    return { icon, value };
}

// Interfaces emitted by Hub\Ingress\Mqtt\Moko\GatewayNormalizer.
const CONNECTIVITY_INTERFACE_LABELS = {
    wifi: "Wi-Fi",
    ethernet: "Ethernet",
    ethernet_wifi: "Ethernet + Wi-Fi",
    cellular: "Rede móvel",
};

const CONNECTIVITY_INTERFACE_ICONS = {
    wifi: "fa-wifi",
    ethernet: "fa-ethernet",
    ethernet_wifi: "fa-network-wired",
    cellular: "fa-tower-cell",
};

function connectivityIcon(data) {
    return CONNECTIVITY_INTERFACE_ICONS[String(data?.interface || "").trim()] || "fa-wifi";
}

function connectivityValue(data) {
    const parts = [];
    const iface = String(data?.interface || "").trim();
    if (iface !== "") {
        parts.push(CONNECTIVITY_INTERFACE_LABELS[iface] || titleize(iface));
    }

    const networkType = String(data?.networkType || "").trim();
    if (networkType !== "") {
        parts.push(networkType);
    }

    // A wired gateway reports no RSSI at all, and 0 dBm is a legitimate
    // reading, so test for null rather than falsiness.
    const dbm = data?.signalStrengthDbm;
    if (dbm !== null && dbm !== undefined && dbm !== "" && Number.isFinite(Number(dbm))) {
        parts.push(`${Number(dbm)} dBm`);
    }

    return parts.length > 0 ? parts.join(" · ") : featureLabel("connectivity");
}

// Mirrors the thresholds in Hub\Ingress\Mqtt\Moko\MonitNormalizer so the strip
// can never disagree with the condition card beside it.
const DIAPER_DAMP_DELTA = 4;
const DIAPER_AFFECTED_DELTA = 12;
// Deltas are 6-bit (0-63) but the decision happens at 12, and a dry channel
// reads 0-3. Scaling to the full range would flatten every real reading, so the
// bars scale to twice the threshold and taller readings clamp to full height.
const DIAPER_SCALE_DELTA = 24;

function diaperMoistureBand(delta) {
    if (delta >= DIAPER_AFFECTED_DELTA) return "wet";
    if (delta >= DIAPER_DAMP_DELTA) return "damp";
    return "dry";
}

function diaperMoistureBody(data) {
    const channels = Array.isArray(data?.channels) ? data.channels : [];
    if (channels.length === 0) {
        return "";
    }

    const columns = channels
        .map((channel, position) => {
            // Baselines differ by an order of magnitude between channels, so
            // only the delta is comparable across the strip.
            const delta = Math.max(0, Number(channel?.delta ?? 0) || 0);
            const index = channel?.index ?? position + 1;
            const band = diaperMoistureBand(delta);
            const height = Math.min(100, (delta / DIAPER_SCALE_DELTA) * 100);
            const tooltip = `Canal ${index} · delta ${delta} (base ${channel?.baseline ?? "-"}, leitura ${channel?.value ?? "-"})`;

            return `<div class="diaper-channel" title="${esc(tooltip)}">
                <div class="diaper-channel-value diaper-channel-value--${band}">${esc(delta)}</div>
                <div class="diaper-channel-track">
                    <div class="diaper-channel-fill diaper-channel-fill--${band}" style="height:${height}%"></div>
                </div>
                <div class="diaper-channel-index">${esc(index)}</div>
            </div>`;
        })
        .join("");

    const maximum = Math.max(0, Number(data?.maximumDelta ?? 0) || 0);
    const affected = Math.max(0, Number(data?.affectedChannelCount ?? 0) || 0);
    const thresholdOffset = (DIAPER_AFFECTED_DELTA / DIAPER_SCALE_DELTA) * 100;

    return `<div class="diaper-moisture mt-3">
        <div class="diaper-strip" style="--diaper-threshold:${thresholdOffset}%">${columns}</div>
        <div class="diaper-moisture-summary small text-secondary mt-2">
            Máx. <strong class="text-body">${esc(maximum)}</strong> · <strong class="text-body">${esc(affected)}</strong> de ${channels.length} canais acima do limiar (${DIAPER_AFFECTED_DELTA})
        </div>
    </div>`;
}

// The alert mark comes from the payload, never from a constant here. Hardcoding
// the 40 would be a second copy of the four-affected-channels threshold that
// decides the condition, and the normalizer publishes alertIndex precisely so
// this file does not have to know it.
//
// Two tones only, split on that threshold, and deliberately not three: the
// clean/attention distinction is already on the diaper_condition card beside
// this one, so repeating it here would add nothing and could disagree with it.
function diaperMoistureLevelBody(data) {
    const index = Number(data?.index);
    if (!Number.isFinite(index)) {
        return "";
    }

    const alertIndex = Number(data?.alertIndex);
    const hasAlert = Number.isFinite(alertIndex);
    const band = hasAlert && index >= alertIndex ? "wet" : "damp";
    const width = Math.max(0, Math.min(100, index));
    const mark = hasAlert
        ? `style="--diaper-threshold:${Math.max(0, Math.min(100, alertIndex))}%"`
        : "";

    return `<div class="diaper-level mt-3">
        <div class="diaper-level-track" ${mark}>
            <div class="diaper-level-fill diaper-level-fill--${band}" style="width:${width}%"></div>
        </div>
        <div class="diaper-moisture-summary small text-secondary mt-2">
            Índice <strong class="text-body">${esc(index)}</strong> de 100${
                hasAlert ? ` · alerta a partir de <strong class="text-body">${esc(alertIndex)}</strong>` : ""
            }
        </div>
    </div>`;
}

function batteryDetails(data) {
    if (BATTERY_CHARGING_STATE_LABEL[data.chargingState]) {
        return BATTERY_CHARGING_STATE_LABEL[data.chargingState];
    }
    return compactDetails(data, ["batteryType"]);
}

function radarPositionsValue(data) {
    const people = Array.isArray(data?.people) ? data.people : [];
    if (!people.length) {
        return "Sem posições";
    }

    return `${people.length} pessoa${people.length === 1 ? "" : "s"}`;
}

function radarPositionsDetails(data) {
    const people = Array.isArray(data?.people) ? data.people : [];
    if (!people.length) {
        return "";
    }

    return people
        .slice(0, 3)
        .map((person, index) => {
            const personIndex = displayPersonIndex(
                person?.person_index ?? index + 1,
            );
            const x = dataPointValue(person?.x_position_dm);
            const y = dataPointValue(person?.y_position_dm);
            const z = dataPointValue(person?.z_position_cm);
            return `Pessoa ${esc(personIndex)} · x ${esc(x)} dm · y ${esc(y)} dm · z ${esc(z)} cm`;
        })
        .join("<br>");
}

function radarVitalsValue(data) {
    const heartRate = dataPointValue(data?.heart_rate);
    const breathing = dataPointValue(data?.breathing);
    if (heartRate === "-" && breathing === "-") {
        return "Sem leituras";
    }

    return `${heartRate !== "-" ? `${heartRate} bpm` : "-"} · ${breathing !== "-" ? `${breathing} rpm` : "-"}`;
}

function radarVitalsDetails(data) {
    return compactDetails(data, ["sleep_state"]);
}

function radarPositionMinuteStatsValue(data) {
    const people = dataPointValue(data?.people);
    const distance = dataPointValue(data?.walking_distance);
    if (people === "-" && distance === "-") {
        return "Sem leituras";
    }

    return `${people !== "-" ? `${people} pessoas` : "-"} · ${distance !== "-" ? `${distance} m` : "-"}`;
}

function radarPositionMinuteStatsDetails(data) {
    return compactDetails(data, [
        "walking_time",
        "meditation_time",
        "in_bed_time",
        "standing_time",
        "multiplayer_time",
        "breathing_active",
    ]);
}

function radarVitalsMinuteStatsValue(data) {
    const heartRate = dataPointValue(data?.avg_heart_rate_per_minute);
    const breathing = dataPointValue(data?.avg_breathing_per_minute);
    if (heartRate === "-" && breathing === "-") {
        return "Sem leituras";
    }

    return `${heartRate !== "-" ? `${heartRate} bpm` : "-"} · ${breathing !== "-" ? `${breathing} rpm` : "-"}`;
}

function radarVitalsMinuteStatsDetails(data) {
    return compactDetails(data, [
        "breathing_status_per_minute",
        "heart_rate_status_per_minute",
        "vital_signs_status",
    ]);
}

function dataPointValue(value) {
    return value === undefined || value === null || value === "" ? "-" : String(value);
}

function displayPersonIndex(value) {
    return value === undefined || value === null || value === "" ? "-" : String(value);
}

export function renderRequestCardShell(command, loading, telemetry = []) {
    const type = commandFeature(command);
    const card = requestCardContent(type);
    const tooltip = featureLabel(type) || card.value || type;
    const requestable = command.requestable !== false;
    const isSystemRequestCard = [
        "firmware_version",
        "device_status",
    ].includes(type);

    const telemetryTypes = requestTelemetryTypes(type);
    const lastTelemetry = telemetry
        .map(rowPayload)
        .filter(
            (payload) =>
            payload && telemetryTypes.includes(String(payload.type || "")),
        )
        .sort((a, b) => eventTime(b) - eventTime(a))[0];

    const lastContent = lastTelemetry
        ? uplinkCardContent(type, lastTelemetry.data)
        : null;
    const lastValue = lastContent ? lastContent.value : card.value;
    // The card shows the latest telemetry, so an icon derived from that reading
    // wins over the static one -- a wired gateway must not show a Wi-Fi icon.
    const icon = command.icon || lastContent?.icon || card.icon;
    const title = isSystemRequestCard
        ? card.value || featureLabel(type)
        : lastValue || card.value || featureLabel(type);
    // A card may ask for the full row and supply its own body, so richer
    // telemetry does not need a special case in this shell.
    const span = lastContent?.span || card.span || 6;
    const bodyHtml = lastContent?.body || "";
    const buttonHtml = requestable
        ? `<button class="btn btn-primary btn-sm w-100" data-feature="${esc(type)}" data-action="requestFeature" ${loading ? "disabled" : ""}>${loading ? '<span class="spinner-border spinner-border-sm me-2"></span>A pedir' : '<i class="fa-solid fa-paper-plane me-2"></i>Pedir'}</button>`
        : "";
    const buttonRowHtml = buttonHtml
        ? `<div class="mt-3 d-grid gap-2 min-w-0">${buttonHtml}</div>`
        : "";

    return telemetryCard({
        span,
        icon,
        title,
        tooltip,
        body: `${bodyHtml}${buttonRowHtml}`,
    });
}

function requestTelemetryTypes(type) {
    if (type === "positions") {
        return ["position"];
    }
    if (type === "vitals") {
        return ["vitals"];
    }
    if (type === "position_minute_stats") {
        return ["minute_stats"];
    }
    if (type === "vitals_minute_stats") {
        return ["hbstatics"];
    }
    return [type];
}

export function statusBadge(status) {
    const cls = STATUS_BADGE_CLASS[status] || "text-bg-light";
    const label = STATUS_BADGE_LABEL[status] || titleize(status).toLowerCase();
    return `<span class="badge ${cls}">${esc(label)}</span>`;
}

function alarmValue(data) {
    for (const [key, label] of ALARM_VALUE_BY_PRIORITY) {
        if (data[key]) return label;
    }
    return "Alarme";
}

function compactDetails(data, keys) {
    return keys
        .filter(
            (key) =>
            data[key] !== undefined &&
            data[key] !== null &&
            data[key] !== "",
        )
        .map((key) => `${esc(fieldLabel(key))}: ${esc(data[key])}`)
        .join(" · ");
}

/**
 * Summary of the most recent help call per press mode.
 *
 * The device has no way to tell us a call was cancelled: dismissing is a
 * downlink command, and because the bracelet only advertises while alarmed,
 * every frame we ever see carries alarm_status = 1. So this deliberately does
 * not model an active/cleared alarm -- it reports when each press last
 * happened, which is a fact we can actually observe.
 *
 * @param {Array} events raw event payloads for the device
 * @returns {string} card markup, or "" when the device has never called
 */
export function helpCallSummaryCard(events = []) {
    const calls = (Array.isArray(events) ? events : [])
        .map(rowPayload)
        .filter((payload) => String(payload?.type || "") === "help_call");

    if (calls.length === 0) {
        return "";
    }

    const latest = {};
    for (const call of calls) {
        const mode = String(call?.data?.pressType || "");
        if (!HELP_CALL_PRESS_MODES.includes(mode)) {
            continue;
        }
        if (latest[mode] === undefined || eventTime(call) > eventTime(latest[mode])) {
            latest[mode] = call;
        }
    }

    // Three side by side on a desktop, stacked full width on a phone.
    const columns = HELP_CALL_PRESS_MODES.map((mode) => {
        const call = latest[mode];
        // The shared label reads as a suffix ("... (toque simples)"), so it is
        // capitalised here where it titles a column instead.
        const suffix = PRESS_TYPE_LABEL[mode];
        const label = esc(suffix.charAt(0).toUpperCase() + suffix.slice(1));
        const icon = HELP_CALL_PRESS_ICON[mode];
        const called = call !== undefined;
        const occurredAt = called ? call.occurredAt || call.recordedAt || "" : "";
        // The relative time is the readable one; the exact timestamp is a
        // detail, so it waits behind a tooltip rather than crowding the column.
        const tooltip = called
            ? ` data-bs-toggle="tooltip" data-bs-trigger="hover focus" data-bs-placement="top" data-bs-title="${esc(when(occurredAt))}" aria-label="${label}: ${esc(when(occurredAt))}" tabindex="0"`
            : "";

        return `<div class="col-12 col-md-4">
            <div class="d-flex align-items-center gap-2 border rounded p-2 h-100${called ? "" : " opacity-50"}"${called ? ` data-occurred-at="${esc(occurredAt)}"` : ""}${tooltip}>
            <i class="fa-solid ${icon} ${called ? "text-danger" : "text-body-secondary"}" style="width:1.25rem;text-align:center;flex-shrink:0;"></i>
            <div class="min-w-0">
            <div class="fw-semibold text-truncate">${label}</div>
            <div class="small text-body-secondary">${called ? esc(ago(occurredAt)) : '<span class="help-call-never">nunca</span>'}</div>
            </div>
            </div>
            </div>`;
    }).join("");

    return `<div class="col-12">
        <div class="card h-100 border-danger">
        <div class="card-body">
        <div class="d-flex align-items-center gap-3 min-w-0 mb-3">
        <div class="bg-danger bg-opacity-10 rounded-3 d-flex align-items-center justify-content-center text-danger" style="width:36px;height:36px;flex-shrink:0;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div class="fw-bold text-danger flex-grow-1 min-w-0">Últimas chamadas de ajuda</div>
        </div>
        <div class="row g-2">${columns}</div>
        </div>
        </div>
        </div>`;
}
