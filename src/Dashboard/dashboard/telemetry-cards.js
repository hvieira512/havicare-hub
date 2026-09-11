import {
    ago,
    displayPersonIndex,
    fieldLabel,
    fieldValue,
    titleize,
} from "./format.js";
import { DETECTION_TYPE_LABEL, PRESS_TYPE_LABEL } from "./domain.js";
import { html, raw } from "./html.js";
import { capabilityLabel } from "./capability-catalog.js";
import { stateBadge } from "./components/state-badge.js";

/** Os cartões de telemetria. As peças genéricas de interface estão em `widgets.js`. */

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
    // Um índice e um gasto metabólico são medidas de bem-estar e não gravidades: ficam no
    // azul da casa, como a atividade e o sono, e não no vermelho nem no âmbar.
    stress: ["fa-face-grimace", "primary"],
    // `fa-fire` e não `fa-fire-flame-simple`: a chama simples é uma gota, e o mosaico do MET
    // fica ao lado do oxigénio no sangue, que já é uma gota.
    met: ["fa-fire", "primary"],
    location: ["fa-location-dot", "success"],
    sleep: ["fa-bed", "primary"],
    sleep_state: ["fa-bed", "primary"],
    sleep_apnea: ["fa-bed-pulse", "warning"],
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
    reset: ["fa-bell-slash", "warning"],
    unknown: ["fa-bell", ""],
};

/** O ícone de uma capacidade. Sem entrada na tabela, o genérico. */
export function cardIcon(type) {
    return CARD_STYLE[type]?.[0] || "fa-circle-info";
}

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
    // O tipo específico vai no valor: "Queda" não distingue uma queda de alguém no chão.
    fall: (data) => ({
        icon: "fa-person-falling",
        value: detectionValue(data),
        details: detectionDetails(data),
    }),
    vitals_alarm: (data) => ({
        icon: "fa-heart-crack",
        value: detectionValue(data),
        details: detectionDetails(data),
    }),
    presence_event: (data) => ({
        icon: "fa-door-open",
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
    // Nem toda a temperatura é corporal: uma pulseira que só amostre a pele ao longo do dia
    // manda `skinCelsius`, e mostrar "-" escondia uma leitura que existe.
    temperature: (data) => ({
        value:
            data.bodyCelsius != null
                ? `${data.bodyCelsius} °C`
                : data.skinCelsius != null
                    ? `${data.skinCelsius} °C na pele`
                    : "-",
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
    sleep: () => ({ value: "Dados de sono" }),
    ecg: () => ({ value: "Dados de ECG" }),
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
    ppg: () => ({ value: "Dados de PPG" }),
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
const STATUS_BADGE_TONE = {
    queued: "secondary",
    sent: "",
    waiting: "warning",
    acked: "success",
    failed: "danger",
    dropped: "danger",
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

// Uma pulseira W6B diz que tipo de toque foi; um pager NCS diz que comando foi.

/** Os modos que um dispositivo emite vêm do backend; este cartão desenha os que lhe derem. */
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

function helpCallContent(data) {
    const base = ncsPagerContent("help_call", data);
    const pressType = PRESS_TYPE_LABEL[String(data?.pressType || "")];

    return pressType === undefined
        ? base
        : { ...base, value: `${base.value} (${pressType})` };
}

/**
 * O `rowValue` leva o comando porque o nome da linha já diz o que aconteceu, e sem ele a
 * coluna do valor repetia "Chamada de ajuda" ao lado de "Chamada de ajuda".
 */
function ncsPagerContent(type, data) {
    const value = NCS_PAGER_EVENT_VALUE[type] || capabilityLabel(type);
    const icon = NCS_PAGER_EVENT_ICON[type] || "fa-bell";
    const pagerId = String(data?.pagerId || "");

    return pagerId === ""
        ? { icon, value }
        : { icon, value, rowValue: `Pager ${pagerId}` };
}

// As interfaces que o `Hub\Ingress\Mqtt\Moko\GatewayNormalizer` emite.
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
    return (
        CONNECTIVITY_INTERFACE_ICONS[String(data?.interface || "").trim()] ||
        "fa-wifi"
    );
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

    // Um gateway com fios não reporta RSSI e 0 dBm é leitura legítima: testar contra null.
    const dbm = data?.signalStrengthDbm;
    if (
        dbm !== null &&
        dbm !== undefined &&
        dbm !== "" &&
        Number.isFinite(Number(dbm))
    ) {
        parts.push(`${Number(dbm)} dBm`);
    }

    return parts.length > 0
        ? parts.join(" · ")
        : capabilityLabel("connectivity");
}

// O limiar vem no payload, por sensor; 12 é o preset normal, para leituras sem o campo.
const DIAPER_WET_DELTA_FALLBACK = 12;

function diaperWetDelta(data) {
    const wetDelta = Number(data?.wetDelta);
    return Number.isFinite(wetDelta) && wetDelta > 0
        ? wetDelta
        : DIAPER_WET_DELTA_FALLBACK;
}

/** Quantos canais molhados obrigam a muda. Sem o campo, conta sobre o total de canais. */
function diaperRequiredChannels(data, channelCount) {
    const required = Number(data?.requiredChannelCount);
    return Number.isFinite(required) && required > 0 ? required : channelCount;
}

// Espelha o `DiaperSensitivity::cleanMaxDelta`: a divisão por 4 tem de ser igual dos dois
// lados, senão a tira pinta de âmbar um canal que o cartão ao lado conta como seco.
function diaperDampDelta(wetDelta) {
    return Math.floor(wetDelta / 4) + 1;
}

function diaperMoistureBand(delta, wetDelta) {
    if (delta >= wetDelta) return "wet";
    if (delta >= diaperDampDelta(wetDelta)) return "damp";
    return "dry";
}

function diaperMoistureBody(data) {
    const channels = Array.isArray(data?.channels) ? data.channels : [];
    if (channels.length === 0) {
        return "";
    }

    const wetDelta = diaperWetDelta(data);
    // Os deltas são de 6 bits, mas a decisão está no limiar: escalar à gama toda achatava
    // todas as leituras reais, por isso a tira escala ao dobro do limiar e corta aí.
    const scaleDelta = wetDelta * 2;

    const columns = channels
        .map((channel, position) => {
            // As bases diferem uma ordem de grandeza entre canais: só o delta é comparável.
            const delta = Math.max(0, Number(channel?.delta ?? 0) || 0);
            const index = channel?.index ?? position + 1;
            const band = diaperMoistureBand(delta, wetDelta);
            const height = Math.min(100, (delta / scaleDelta) * 100);
            const tooltip = `Canal ${index} · delta ${delta} (base ${channel?.baseline ?? "-"}, leitura ${channel?.value ?? "-"})`;

            return html`<div class="diaper-channel d-flex flex-column min-w-0" title="${tooltip}">
<div class="diaper-channel-value diaper-channel-value--${band} text-center fw-semibold tabular-nums lh-sm">${delta}</div>
<div class="diaper-channel-track position-relative d-flex align-items-end overflow-hidden">
<div class="diaper-channel-fill diaper-channel-fill--${band} w-100" style="height:${height}%"></div>
</div>
<div class="diaper-channel-index text-center tabular-nums lh-sm">${index}</div>
</div>`;
        })
        .join("");

    const maximum = Math.max(0, Number(data?.maximumDelta ?? 0) || 0);
    const affected = Math.max(0, Number(data?.affectedChannelCount ?? 0) || 0);
    const required = diaperRequiredChannels(data, channels.length);
    const thresholdOffset = (wetDelta / scaleDelta) * 100;

    return html`<div class="diaper-moisture mt-3">
<div class="diaper-strip d-grid align-items-end" style="--diaper-threshold:${thresholdOffset}%">${raw(columns)}</div>
<div class="border-top pt-2 small text-secondary mt-2">
Máx. <strong class="text-body">${maximum}</strong> · <strong class="text-body">${affected}</strong> de ${required} canais acima do limiar (${wetDelta})
</div>
</div>`;
}

/** Numa linha só cabe o que a tira resume: o delta mais alto e quantos passaram o limiar. */
function diaperMoistureRowValue(data) {
    const channels = Array.isArray(data?.channels) ? data.channels : [];
    if (channels.length === 0) {
        return "";
    }

    const maximum = Math.max(0, Number(data?.maximumDelta ?? 0) || 0);
    const affected = Math.max(0, Number(data?.affectedChannelCount ?? 0) || 0);
    return `máx. ${maximum} · ${affected} de ${diaperRequiredChannels(data, channels.length)} acima do limiar`;
}

function batteryDetails(data) {
    if (BATTERY_CHARGING_STATE_LABEL[data.chargingState]) {
        return BATTERY_CHARGING_STATE_LABEL[data.chargingState];
    }
    return compactDetails(data, ["batteryType"]);
}

/**
 * Lê o `lat`/`lon` e não o `hasCoordinates`, que falta nos eventos antigos do Redis. O par
 * 0,0 é como os protocolos dizem "sem fixo".
 */
export function locationCoordinates(data) {
    const lat = Number(data?.lat);
    const lon = Number(data?.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return null;
    if (lat === 0 && lon === 0) return null;
    return { lat, lon };
}

/**
 * Onde está, ou um travessão: o slot grande responde a uma pergunta só, e sem posição não
 * há resposta. Cinco decimais são ~1 m, a precisão do melhor fixo do mapa de rádio.
 */
function locationValue(data) {
    const fix = locationCoordinates(data);
    return fix ? `${fix.lat.toFixed(5)}, ${fix.lon.toFixed(5)}` : "—";
}

/**
 * Como se obteve a posição: GPS, ou rádio, e não a origem crua. `cell`, `wifi` e
 * `cell_wifi` são todos triangulação, e o que os distingue do GPS é a proveniência.
 */
function locationFixLabel(data) {
    const source = String(data?.source || "").toLowerCase();
    if (source === "") return "";
    return source === "gps" ? "GPS" : "Rádio";
}

function locationAccuracy(data) {
    const meters = Number(data?.accuracyMeters);
    if (!Number.isFinite(meters) || meters <= 0) return "";
    return `±${Math.max(1, Math.round(meters))} m`;
}

/**
 * A prova de rádio de uma leitura que não deu posição. Sem antenas nem redes é outra falha:
 * o aparelho reportou e não viu nada, o que aponta para ele e não para a cobertura.
 */
function locationRadioEvidence(data) {
    const cells = Array.isArray(data?.baseStations)
        ? data.baseStations.length
        : 0;
    const wifi = Array.isArray(data?.wifiAccessPoints)
        ? data.wifiAccessPoints.length
        : 0;
    if (cells === 0 && wifi === 0) return "Sem dados de rádio";

    return [
        cells ? `${cells} ${cells === 1 ? "antena" : "antenas"}` : "",
        wifi ? `${wifi} ${wifi === 1 ? "rede WiFi" : "redes WiFi"}` : "",
    ]
        .filter(Boolean)
        .join(" · ");
}

/**
 * Com posição, como e com que precisão; sem posição, com que evidência se tentou. A idade só
 * aparece no mosaico -- na lista cronológica a hora já tem coluna.
 */
function locationDetails(data, meta = {}) {
    const parts = locationCoordinates(data)
        ? [locationFixLabel(data), locationAccuracy(data)]
        : [locationRadioEvidence(data)];

    return [...parts, meta?.occurredAt ? ago(meta.occurredAt) : ""]
        .filter(Boolean)
        .map((part) => html`${part}`)
        .join(" · ");
}

/**
 * Quantas pessoas o radar vê. O `count` vem do hub e não se conta o array aqui: um radar
 * que não vê ninguém está a funcionar, e diz "Ninguém" e não "Sem leituras".
 */
function presenceValue(data) {
    const count = Number(data?.count ?? 0) || 0;
    if (count === 0) {
        return "Ninguém";
    }

    return `${count} pessoa${count === 1 ? "" : "s"}`;
}

/**
 * O ícone diz a categoria e o tom a gravidade. A etiqueta vive no
 * `FIELD_VALUE_LABELS.posture` do `format.js`.
 */
const POSTURE_STYLE = {
    standing: { icon: "fa-person", tone: "success" },
    walking: { icon: "fa-person-walking", tone: "success" },
    confirmed_sitting_up_bed: { icon: "fa-bed", tone: "success" },
    lying_down: { icon: "fa-bed", tone: "info" },
    sitting_up_bed: { icon: "fa-bed", tone: "info" },
    suspected_sitting_up_bed: { icon: "fa-bed", tone: "warning" },
    squatting: { icon: "fa-chair", tone: "warning" },
    suspected_sitting_on_ground: { icon: "fa-chair", tone: "warning" },
    suspected_fall: { icon: "fa-triangle-exclamation", tone: "warning" },
    confirmed_sitting_on_ground: { icon: "fa-chair", tone: "danger" },
    fall_confirmation: { icon: "fa-triangle-exclamation", tone: "danger" },
    initialization: { icon: "fa-question", tone: "secondary" },
    unknown: { icon: "fa-question", tone: "secondary" },
};

/** A pastilha é um `badge` do Bootstrap com o par de utilitários subtis do tom. */
const CHIP_CLASS =
    "badge rounded-pill fw-normal d-inline-flex align-items-center gap-1";

/**
 * Uma postura como pastilha. A enumeração vem do payload e vai parar a um `class`, por isso
 * sai escapada: um estado novo do firmware não pode escrever atributos.
 */
function postureChip(posture) {
    const style = POSTURE_STYLE[String(posture)] || POSTURE_STYLE.unknown;
    const tone = style.tone;
    const label = fieldValue("posture", posture);

    return html`<span class="${CHIP_CLASS} bg-${tone}-subtle text-${tone}-emphasis" title="${label}"><i class="fa-solid ${style.icon}" aria-hidden="true"></i>${label}</span>`;
}

/** Quantas pastilhas cabem antes de o mosaico crescer de mais. */
const PRESENCE_CHIP_LIMIT = 3;

/**
 * A postura de cada pessoa, em pastilhas. As coordenadas ficam na tooltip: num mosaico
 * estreito enchiam a linha, e não significam nada sem uma planta da divisão.
 */
function presenceDetails(data) {
    const people = Array.isArray(data?.people) ? data.people : [];
    const chips = people
        .slice(0, PRESENCE_CHIP_LIMIT)
        .map((person) => postureChip(person?.posture));
    const hidden = people.length - chips.length;

    if (hidden > 0) {
        chips.push(
            html`<span class="${CHIP_CLASS} bg-secondary-subtle text-secondary-emphasis">+${hidden}</span>`,
        );
    }

    return chips.join("");
}

/**
 * As pessoas todas, com onde estão, sem corte: é aqui que as coordenadas e a quarta pessoa
 * em diante existem, para quem esteja a comparar com a especificação do fabricante.
 */
function presenceDetailsTitle(data) {
    const people = Array.isArray(data?.people) ? data.people : [];

    return people
        .map((person, index) => {
            const personIndex = displayPersonIndex(
                person?.personIndex ?? index,
            );
            const posture = fieldValue("posture", person?.posture);
            const x = dataPointValue(person?.xPositionDm);
            const y = dataPointValue(person?.yPositionDm);
            const z = dataPointValue(person?.zPositionCm);
            return `Pessoa ${personIndex}: ${posture} · x ${x} dm · y ${y} dm · z ${z} cm`;
        })
        .join(" · ");
}

function radarPositionMinuteStatsValue(data) {
    const people = dataPointValue(data?.people);
    const distance = dataPointValue(data?.walkingDistance);
    if (people === "-" && distance === "-") {
        return "Sem leituras";
    }

    return `${people !== "-" ? `${people} pessoas` : "-"} · ${distance !== "-" ? `${distance} m` : "-"}`;
}

function radarPositionMinuteStatsDetails(data) {
    return compactDetails(data, [
        "walkingTimeS",
        "meditationTimeS",
        "inBedTimeS",
        "standingTimeS",
        "multiplayerTimeS",
        "breathingActive",
    ]);
}

function radarVitalsMinuteStatsValue(data) {
    const heartRate = dataPointValue(data?.avgHeartRate);
    const breathing = dataPointValue(data?.avgBreathing);
    if (heartRate === "-" && breathing === "-") {
        return "Sem leituras";
    }

    return `${heartRate !== "-" ? `${heartRate} bpm` : "-"} · ${breathing !== "-" ? `${breathing} rpm` : "-"}`;
}

function radarVitalsMinuteStatsDetails(data) {
    return compactDetails(data, [
        "breathingStatus",
        "heartRateStatus",
        "vitalSignsStatus",
    ]);
}

function dataPointValue(value) {
    return value === undefined || value === null || value === ""
        ? "-"
        : String(value);
}

/** A cor da categoria, para o ícone. Sem entrada na tabela, o ícone fica neutro. */
export function cardTone(type) {
    return CARD_STYLE[type]?.[1] || "";
}

export function statusBadge(status) {
    return stateBadge(
        STATUS_BADGE_LABEL[status] || titleize(status).toLowerCase(),
        STATUS_BADGE_TONE[status] ?? "secondary",
    );
}

function alarmValue(data) {
    return ALARM_REASON_LABEL[data?.reason] ?? "Alarme";
}

function compactDetails(data, keys) {
    return (
        keys
            .filter(
                (key) =>
                    data[key] !== undefined &&
                    data[key] !== null &&
                    data[key] !== "",
            )
            // O `fieldValue` traduz enumerações; sem ele saía "Estado do sono: awake".
            .map((key) => html`${fieldLabel(key)}: ${fieldValue(key, data[key])}`)
            .join(" · ")
    );
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
