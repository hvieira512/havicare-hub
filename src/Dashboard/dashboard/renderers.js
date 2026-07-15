import {
    commandLabel,
    esc,
    eventTime,
    featureLabel,
    fieldLabel,
    rowPayload,
    titleize,
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
    ["weather", "weather"],
    ["breath", "breath_rate"],
    ["ppg", "ppg"],
    ["rr", "rr_interval"],
];

const ALERT_CARD_TYPES = new Set([
    "heart_rate",
    "blood_pressure",
    "ecg",
    "hrv",
]);

const CARD_TONE_BY_TYPE = {
    positions: {border: "info", bg: "bg-info", text: "text-info"},
    vitals: {border: "success", bg: "bg-success", text: "text-success"},
    position_minute_stats: {border: "secondary", bg: "bg-secondary", text: "text-secondary"},
    vitals_minute_stats: {border: "secondary", bg: "bg-secondary", text: "text-secondary"},
    blood_oxygen: {border: "info", bg: "bg-info", text: "text-info"},
    blood_sugar: {border: "warning", bg: "bg-warning", text: "text-warning"},
    temperature: {border: "warning", bg: "bg-warning", text: "text-warning"},
    battery: {border: "success", bg: "bg-success", text: "text-success"},
    activity: {border: "primary", bg: "bg-primary", text: "text-primary"},
    location: {border: "success", bg: "bg-success", text: "text-success"},
    heartbeat: {border: "info", bg: "bg-info", text: "text-info"},
    breath_rate: {border: "info", bg: "bg-info", text: "text-info"},
    rr_interval: {border: "info", bg: "bg-info", text: "text-info"},
    sleep: {border: "primary", bg: "bg-primary", text: "text-primary"},
    weather: {
        border: "secondary",
        bg: "bg-secondary",
        text: "text-secondary",
    },
    help_call: {border: "danger", bg: "bg-danger", text: "text-danger"},
    reset: {border: "warning", bg: "bg-warning", text: "text-warning"},
    unknown: {border: "secondary", bg: "bg-secondary", text: "text-secondary"},
    "device.connected": {border: "success", bg: "bg-success", text: "text-success"},
    "device.disconnected": {border: "danger", bg: "bg-danger", text: "text-danger"},
};

const ALERT_CARD_TONE = {border: "danger", bg: "bg-danger", text: "text-danger"};

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
    activity: {icon: "fa-person-walking", value: "Atividade"},
    location: {icon: "fa-location-dot", value: "Localização"},
    sleep: {icon: "fa-bed", value: "Sono"},
    ecg: {icon: "fa-wave-square", value: "ECG"},
    hrv: {icon: "fa-chart-line", value: "VFC"},
    weather: {icon: "fa-cloud-sun", value: "Meteorologia"},
    breath_rate: {icon: "fa-lungs", value: "Frequência respiratória"},
    ppg: {icon: "fa-circle-nodes", value: "PPG"},
    rr_interval: {icon: "fa-stopwatch", value: "Intervalo RR"},
    "device.connected": {icon: "fa-plug-circle-check", value: "Ligado"},
    "device.disconnected": {icon: "fa-plug-circle-xmark", value: "Desligado"},
    help_call: {icon: "fa-triangle-exclamation", value: "Chamada de enfermagem"},
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
        value: `${data.percent ?? "-"}%`,
        details: batteryDetails(data),
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
    weather: (data) => ({
        icon: "fa-cloud-sun",
        value: data.summary || "Dados meteorológicos",
        details: compactDetails(data, [
            "temperatureCelsius",
            "lowCelsius",
            "highCelsius",
            "humidityPercent",
            "reportedAt",
        ]),
    }),
    help_call: () => ncsPagerContent("help_call"),
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
    unknown: "desconhecido",
};

const NCS_PAGER_EVENT_VALUE = {
    help_call: "Chamada de enfermagem",
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
        : '<div class="text-secondary border rounded bg-body-tertiary px-3 py-2 small">Sem opções disponíveis</div>';
}

export function emptyPanel(text) {
    return `<div class="text-secondary border rounded bg-body-tertiary p-3">${esc(text)}</div>`;
}

export function commandFeature(command) {
    if (command.feature) return command.feature;
    const haystack =
        `${command.command || ""} ${command.label || ""}`.toLowerCase();
    for (const [needle, feature] of COMMAND_FEATURE_RULES) {
        if (haystack.includes(needle)) return feature;
    }
    return "device_config";
}

export function cardTone(type, command = {}) {
    const key = type || commandFeature(command);
    if (ALERT_CARD_TYPES.has(key)) {
        return ALERT_CARD_TONE;
    }
    return CARD_TONE_BY_TYPE[key] || {
        border: "secondary",
        bg: "bg-secondary",
        text: "text-secondary",
    };
}

export function requestCardContent(type) {
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

function ncsPagerContent(type) {
    const value = NCS_PAGER_EVENT_VALUE[type] || featureLabel(type);
    const icon = NCS_PAGER_EVENT_ICON[type] || "fa-bell";
    return { icon, value };
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
    const tone = cardTone(type, command);
    const icon = command.icon || card.icon;
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

    const lastValue = lastTelemetry
        ? uplinkCardContent(type, lastTelemetry.data).value
        : card.value;
    const title = isSystemRequestCard
        ? card.value || featureLabel(type)
        : lastValue || card.value || featureLabel(type);
    const buttonHtml = requestable
        ? `<button class="btn btn-primary btn-sm w-100" data-feature="${esc(type)}" data-action="requestFeature" ${loading ? "disabled" : ""}>${loading ? '<span class="spinner-border spinner-border-sm me-2"></span>A pedir' : '<i class="fa-solid fa-paper-plane me-2"></i>Pedir'}</button>`
        : "";
    const buttonRowHtml = buttonHtml
        ? `<div class="mt-3 d-grid gap-2 min-w-0">${buttonHtml}</div>`
        : "";

    return `
        <div class="col-12 col-md-6">
        <div class="card h-100 border-${tone.border}">
        <div class="card-body">
        <div class="d-flex align-items-center gap-3 min-w-0">
        <div class="bg-${tone.border} bg-opacity-10 rounded-3 d-flex align-items-center justify-content-center text-${tone.border}" style="width:36px;height:36px;flex-shrink:0;" data-bs-toggle="tooltip" data-bs-trigger="hover focus" data-bs-placement="top" data-bs-title="${esc(tooltip)}" aria-label="${esc(tooltip)}" tabindex="0">
        <i class="fa-solid ${esc(icon)}"></i>
        </div>
        <div class="fw-bold ${tone.text} text-truncate flex-grow-1 min-w-0" title="${esc(title)}">${esc(title)}</div>
        </div>
        ${buttonRowHtml}
        </div>
        </div>
        </div>`;
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
