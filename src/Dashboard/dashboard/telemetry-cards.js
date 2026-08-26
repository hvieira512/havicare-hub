import {
    ago,
    esc,
    eventTime,
    fieldLabel,
    fieldValue,
    rowPayload,
    titleize,
    when,
} from "./format.js";
import {capabilityLabel} from "./capability-catalog.js";

/**
 * A maquina dos cartoes de telemetria: o que cada evento recebido mostra, o que cada
 * pedido ao dispositivo mostra, e o estado de cada um.
 *
 * As pecas genericas de interface -- licenca, imagem de modelo, mosaico de tipos,
 * pastilhas de filtro, estado vazio -- estao em `widgets.js`, e nada aqui as chama.
 */

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

/**
 * A cor de cada categoria de telemetria.
 *
 * Vive no icone e mais nada. Antes pintava tambem o contorno do cartao e o titulo, o que
 * dava quatro cores por painel e um cartao inteiro a competir com o seguinte; e a
 * frequencia cardiaca e a tensao arterial vinham em vermelho de perigo por serem sinais
 * vitais, o que num cartao que so oferece pedir uma leitura le-se como alarme.
 *
 * Tirar a cor toda foi longe demais: o icone colorido e o que se reconhece de relance
 * numa grelha de oito, e sem ele todos os mosaicos ficam iguais.
 */
const CARD_TONE_BY_TYPE = {
    positions: "info",
    vitals: "success",
    position_minute_stats: "secondary",
    vitals_minute_stats: "secondary",
    blood_oxygen: "info",
    blood_sugar: "warning",
    temperature: "warning",
    battery: "success",
    connectivity: "info",
    motion: "primary",
    diaper_moisture: "info",
    diaper_moisture_level: "info",
    diaper_condition: "warning",
    activity: "primary",
    location: "success",
    breath_rate: "info",
    rr_interval: "info",
    sleep: "primary",
    sleep_state: "primary",
    presence: "success",
    help_call: "danger",
    reset: "warning",
    heart_rate: "danger",
    blood_pressure: "danger",
    ecg: "danger",
    hrv: "danger",
    "device.connected": "success",
    "device.disconnected": "danger",
};

/**
 * O icone de cada capacidade.
 *
 * So o icone: o nome vem do catalogo, pelo `capabilityLabel`. Este mapa tinha tambem uma
 * `value` com o nome escrito a mao, e era a terceira copia dos mesmos nomes na aplicacao
 * -- a que fazia um mosaico dizer "Pressão arterial" no titulo e "Tensão arterial" no
 * valor, ao mesmo tempo.
 */
const REQUEST_CARD_ICON_BY_TYPE = {
    positions: "fa-location-crosshairs",
    vitals: "fa-heart-pulse",
    position_minute_stats: "fa-chart-column",
    vitals_minute_stats: "fa-chart-line",
    heart_rate: "fa-heart-pulse",
    blood_pressure: "fa-stethoscope",
    blood_oxygen: "fa-droplet",
    blood_sugar: "fa-vial",
    temperature: "fa-temperature-half",
    battery: "fa-battery-three-quarters",
    connectivity: "fa-wifi",
    motion: "fa-person-running",
    diaper_moisture: "fa-droplet",
    diaper_moisture_level: "fa-percent",
    diaper_condition: "fa-baby",
    activity: "fa-person-walking",
    location: "fa-location-dot",
    sleep: "fa-bed",
    sleep_state: "fa-bed",
    presence: "fa-location-crosshairs",
    ecg: "fa-wave-square",
    hrv: "fa-chart-line",
    breath_rate: "fa-lungs",
    ppg: "fa-circle-nodes",
    rr_interval: "fa-stopwatch",
    "device.connected": "fa-plug-circle-check",
    "device.disconnected": "fa-plug-circle-xmark",
    help_call: "fa-triangle-exclamation",
    reset: "fa-bell-slash",
    unknown: "fa-bell",
};

const UPLINK_CARD_RENDERERS = {
    // O radar já não tem cartões de frequência cardíaca nem respiratória: manda as mesmas
    // chaves e as mesmas formas que um relógio, e usa os cartões dele mais abaixo.
    presence: (data) => ({
        icon: "fa-location-crosshairs",
        value: presenceValue(data),
        details: presenceDetails(data),
    }),
    sleep_state: (data) => ({
        icon: "fa-bed",
        value: fieldValue("sleep_state", data?.state),
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
        // O indice 0-100 e uma capacidade propria e chega noutra mensagem, mas nao tem
        // cartao proprio: e o valor deste, por cima da tira dos canais que o explica.
        value: data?.index != null ? `${data.index}%` : capabilityLabel("diaper_moisture"),
        // Numa linha de lista nao ha espaco para a tira dos dez canais, e o mosaico do
        // cartao ja a mostra. O que resta e o resumo: quantos canais passaram o limiar.
        rowValue: diaperMoistureRowValue(data),
        span: 12,
        body: diaperMoistureBody(data),
    }),
    diaper_moisture_level: (data) => ({
        icon: "fa-percent",
        value: data?.index != null ? `${data.index}%` : "-",
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
    sleep: () => ({icon: "fa-bed", value: "Dados de sono"}),
    ecg: () => ({icon: "fa-wave-square", value: "Dados de ECG"}),
    hrv: () => ({icon: "fa-chart-line", value: "Dados de VFC"}),
    // Um escalar, ao contrario do sono, do ECG e da PPG, que sao series e por isso se
    // anunciam em vez de se resumirem a um numero. Dizia "Dados de frequencia
    // respiratoria" e nunca mostrava a leitura -- nem a de um relogio, que produz a mesma
    // forma `{breathsPerMinute}` desde sempre.
    breath_rate: (data) => ({
        icon: "fa-lungs",
        value: `${data.breathsPerMinute ?? "-"} rpm`,
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
        value: data?.magnitudeMg != null ? `${data.magnitudeMg} mg` : capabilityLabel("motion"),
        details: compactDetails(data, ["xMg", "yMg", "zMg"]),
    }),
    reset: () => ncsPagerContent("reset"),
    "device.connected": () => ({icon: "fa-plug-circle-check", value: "Ligado"}),
    "device.disconnected": () => ({icon: "fa-plug-circle-xmark", value: "Desligado"}),
};

// Uma familia de estado so: e a mesma pastilha das configuracoes e das regras do hub.
// O tom vazio deixa a pastilha no azul subtil da marca, que e o estado neutro.
const STATUS_BADGE_TONE = {
    queued: "config-state-secondary",
    sent: "",
    waiting: "config-state-warning",
    acked: "config-state-success",
    failed: "config-state-danger",
    dropped: "config-state-danger",
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
export function telemetryCard({
    span = 6,
    icon,
    title,
    value = "",
    details = "",
    tooltip = "",
    body = "",
    feature = "",
    pending = false,
    stateLabel = "",
    stateTone = "",
    tone = "",
}) {
    // O cartao e o pedido: quando ha um feature para pedir, e ele o botao. Um botao
    // "Pedir" dentro de cada cartao dava oito primarios escuros no mesmo painel, e o
    // alvo de clique era a parte mais pequena de uma area que ja era toda clicavel.
    //
    // Sem tooltip: o nome da categoria esta escrito no cartao, por isso a tooltip repetia
    // em cima do rato o que ja estava no ecra.
    const clickable = feature !== "";
    const tag = clickable ? "button" : "div";
    const toneClass = tone ? ` telemetry-card-tone-${esc(tone)}` : "";
    const attrs = clickable
        ? ` type="button" class="card h-100 telemetry-card-action text-start${toneClass}"`
          + ` data-action="requestFeature" data-feature="${esc(feature)}"`
          + `${pending ? " disabled" : ""}`
        : ` class="card h-100${toneClass}"`;
    // A pastilha leva a sua linha, e nao o canto da linha do icone. Num mosaico de 206px, o
    // icone (36) mais a largura minima do nome (63) mais a pastilha (72) nao cabem nos 160
    // uteis: "em fila" saia cortado em "em fi" por cima do contorno do cartao. O aviao de
    // papel, com 11px, continua no canto -- esse cabe.
    const state = stateLabel
        ? `<span class="config-state ${esc(stateTone || (pending ? "config-state-warning" : "config-state-secondary"))} align-self-start">`
          + `<span class="config-state-dot"></span>${esc(stateLabel)}</span>`
        : "";
    // O canto superior direito e o lugar do pedido: em repouso, o aviao de papel diz que o
    // mosaico se pode pedir; enquanto o pedido corre, a pastilha diz em que estado esta.
    // Um mosaico que nao se pode pedir nao tem nada ali, e e essa ausencia que os separa --
    // antes so o hover os distinguia, e por isso era preciso passar o rato por cima de oito
    // mosaicos para descobrir quais respondiam ao clique. Decorativo: o nome da categoria e
    // o `aria-label` do botao ja dizem o que ele faz.
    const requestHint = clickable && !stateLabel
        ? '<span class="telemetry-card-hint flex-shrink-0" aria-hidden="true"><i class="fa-solid fa-paper-plane"></i></span>'
        : "";

    return `
        <div class="col-12 col-md-${span}">
        <${tag}${attrs}>
        <div class="card-body telemetry-card-body">
        <div class="d-flex align-items-center gap-3">
        <div class="telemetry-card-icon">
        <i class="fa-solid ${esc(icon)}"></i>
        </div>
        <div class="flex-grow-1 min-w-0">
        <div class="telemetry-card-title">${esc(title)}</div>
        ${value ? `<div class="telemetry-card-value tabular-nums text-break">${esc(value)}</div>` : ""}
        ${details ? `<span class="telemetry-row-details d-block text-truncate" title="${details.replace(/<br\s*\/?>/gi, " · ").replace(/<[^>]*>/g, "")}">${details}</span>` : ""}
        </div>
        ${requestHint}
        </div>
        ${state}
        ${body}
        </div>
        </${tag}>
        </div>`;
}

export function requestCardContent(type) {
    return {
        icon: REQUEST_CARD_ICON_BY_TYPE[type] || "fa-circle-info",
        value: capabilityLabel(type),
    };
}

export function uplinkCardContent(type, data) {
    return (
        UPLINK_CARD_RENDERERS[type]?.(data) || {
            icon: "fa-circle-info",
            value: capabilityLabel(type),
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
    const value = NCS_PAGER_EVENT_VALUE[type] || capabilityLabel(type);
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

    return parts.length > 0 ? parts.join(" · ") : capabilityLabel("connectivity");
}

/**
 * O limiar por canal que decidiu esta leitura, tirado da propria leitura.
 *
 * Os limiares sao configuraveis por sensor e o normalizador publica o `wetDelta` no
 * payload exactamente para que ninguem os escreva aqui. O 12 e o preset normal e serve so
 * as leituras que ficaram no historico antes de os limiares viajarem com elas: a lista de
 * cada dispositivo guarda cem eventos, e os mais antigos nao trazem o campo.
 */
const DIAPER_WET_DELTA_FALLBACK = 12;

function diaperWetDelta(data) {
    const wetDelta = Number(data?.wetDelta);
    return Number.isFinite(wetDelta) && wetDelta > 0 ? wetDelta : DIAPER_WET_DELTA_FALLBACK;
}

/**
 * Quantos canais molhados obrigam a muda, tambem da leitura.
 *
 * Numa leitura antiga, que nao o traz, o resumo volta a contar sobre o total de canais --
 * que e o que dizia enquanto o limiar era o mesmo para todos os sensores.
 */
function diaperRequiredChannels(data, channelCount) {
    const required = Number(data?.requiredChannelCount);
    return Number.isFinite(required) && required > 0 ? required : channelCount;
}

// Espelha o `DiaperSensitivity::cleanMaxDelta`: abaixo disto em todos os canais a fralda
// esta seca. A divisao por 4 tem de ser a mesma dos dois lados, senao a tira pinta de
// ambar um canal que o cartao ao lado ainda conta como seco.
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
    // Deltas are 6-bit (0-63) but the decision happens at the threshold, and a dry
    // channel reads far below it. Scaling to the full range would flatten every real
    // reading, so the bars scale to twice the threshold and taller readings clamp to
    // full height.
    const scaleDelta = wetDelta * 2;

    const columns = channels
        .map((channel, position) => {
            // Baselines differ by an order of magnitude between channels, so
            // only the delta is comparable across the strip.
            const delta = Math.max(0, Number(channel?.delta ?? 0) || 0);
            const index = channel?.index ?? position + 1;
            const band = diaperMoistureBand(delta, wetDelta);
            const height = Math.min(100, (delta / scaleDelta) * 100);
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
    const required = diaperRequiredChannels(data, channels.length);
    const thresholdOffset = (wetDelta / scaleDelta) * 100;

    return `<div class="diaper-moisture mt-3">
        <div class="diaper-strip" style="--diaper-threshold:${thresholdOffset}%">${columns}</div>
        <div class="diaper-moisture-summary small text-secondary mt-2">
            Máx. <strong class="text-body">${esc(maximum)}</strong> · <strong class="text-body">${esc(affected)}</strong> de ${esc(required)} canais acima do limiar (${esc(wetDelta)})
        </div>
    </div>`;
}

/**
 * O valor de uma leitura de humidade da fralda, para uma linha de lista.
 *
 * O cartão mostra a tira dos dez canais; numa linha só cabe o que a tira resume — o delta
 * mais alto e quantos canais passaram o limiar. Sem isto, a linha dizia "Humidade da
 * fralda" na coluna do nome e outra vez na coluna do valor, sem número nenhum.
 */
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
 * Quantas pessoas o radar vê.
 *
 * O `count` vem do hub e não se conta o array aqui: uma leitura sem ninguém tem de dizer
 * "Ninguém" e não "Sem leituras", que é o que o cartão diria se o array vazio passasse por
 * ausência de dados. Um radar que não vê ninguém está a funcionar.
 */
function presenceValue(data) {
    const count = Number(data?.count ?? 0) || 0;
    if (count === 0) {
        return "Ninguém";
    }

    return `${count} pessoa${count === 1 ? "" : "s"}`;
}

/**
 * Uma linha por pessoa: como está e onde está.
 *
 * A postura vem primeiro porque é o que se quer saber -- alguém caído importa mais do que
 * as coordenadas de quem está de pé. E é por pessoa, não do aparelho: numa divisão com
 * duas, uma pode estar deitada e a outra a andar.
 */
function presenceDetails(data) {
    const people = Array.isArray(data?.people) ? data.people : [];

    return people
        .slice(0, 3)
        .map((person, index) => {
            const personIndex = displayPersonIndex(person?.personIndex ?? index + 1);
            const posture = fieldValue("posture", person?.posture);
            const x = dataPointValue(person?.xPositionDm);
            const y = dataPointValue(person?.yPositionDm);
            const z = dataPointValue(person?.zPositionCm);
            return `Pessoa ${esc(personIndex)}: ${esc(posture)} · x ${esc(x)} dm · y ${esc(y)} dm · z ${esc(z)} cm`;
        })
        .join("<br>");
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

/**
 * Os estados de um pedido que o mosaico mostra, e o tom de cada um.
 *
 * `acked` fica de fora porque a resposta já é o valor do mosaico, e `superseded` também:
 * um pedido substituído tem um mais recente atrás dele, e é esse que se mostra. O que não
 * pode faltar é a falha — se um pedido falhou e nada respondeu depois, o mosaico calado
 * era indistinguível de um mosaico a que nunca se pediu nada.
 */
const REQUEST_CARD_STATE = {
    queued: {label: "em fila", tone: "config-state-secondary"},
    sent: {label: "enviado", tone: "config-state-secondary"},
    waiting: {label: "à espera", tone: "config-state-warning"},
    failed: {label: "falhou", tone: "config-state-danger"},
    dropped: {label: "descartado", tone: "config-state-danger"},
};

/**
 * O estado do pedido mais recente desta categoria, para a pastilha do mosaico.
 *
 * Uma falha só se mostra enquanto for a última palavra: se chegou uma leitura depois dela,
 * o dispositivo respondeu e é o valor que conta.
 */
function latestRequestState(type, commands, lastTelemetryTime) {
    const latest = commands
        .filter((command) => commandFeature(command) === type)
        .sort((left, right) => commandTime(right) - commandTime(left))[0];
    if (!latest) {
        return null;
    }

    const entry = REQUEST_CARD_STATE[String(latest.status || "")];
    if (!entry) {
        return null;
    }

    const failed = entry.tone === "config-state-danger";
    if (failed && lastTelemetryTime && lastTelemetryTime > commandTime(latest)) {
        return null;
    }

    return entry;
}

/**
 * A hora de um pedido.
 *
 * Não é o `eventTime`: um evento traz `occurredAt` ou `recordedAt`, e um pedido traz
 * `requestedAt`. Ordenar pedidos com o `eventTime` dava zero em todos, e o "mais recente"
 * passava a ser o primeiro da lista.
 */
function commandTime(command) {
    const time = Date.parse(command?.requestedAt || "");
    return Number.isNaN(time) ? eventTime(command) : time;
}

export function renderRequestCardShell(
    command,
    loading,
    telemetry = [],
    commands = [],
) {
    const type = commandFeature(command);
    const card = requestCardContent(type);
    const tooltip = capabilityLabel(type) || card.value || type;
    const requestable = command.requestable !== false;
    const isSystemRequestCard = [
        "firmware_version",
        "device_status",
    ].includes(type);

    const telemetryTypes = requestTelemetryTypes(type);
    const payloads = telemetry
        .map(rowPayload)
        .filter(
            (payload) =>
            payload && telemetryTypes.includes(String(payload.type || "")),
        )
        .sort((a, b) => eventTime(b) - eventTime(a));
    const lastTelemetry = payloads[0];

    // Um cartao pode mostrar mais do que uma capacidade -- a humidade da fralda tem os
    // canais numa mensagem e o indice noutra, que chega menos vezes -- e por isso junta a
    // leitura mais recente de CADA tipo. Ficar so com a mais recente das duas apagava a
    // outra: o indice desaparecia a cada leitura de canais que nao o trouxesse.
    const lastData = Object.assign(
        {},
        ...telemetryTypes
            .map((wanted) =>
                payloads.find((payload) => String(payload.type || "") === wanted),
            )
            .reverse()
            .map((payload) => payload?.data || {}),
    );

    const lastContent = lastTelemetry ? uplinkCardContent(type, lastData) : null;
    // Sem leitura nao ha valor: o titulo ja diz o nome da capacidade, e repeti-lo por
    // baixo em corpo maior dava dois nomes no mesmo mosaico.
    const lastValue = lastContent ? lastContent.value : "";
    // The card shows the latest telemetry, so an icon derived from that reading
    // wins over the static one -- a wired gateway must not show a Wi-Fi icon.
    const icon = command.icon || lastContent?.icon || card.icon;
    // O titulo e sempre o nome da categoria. A leitura mais recente substituia-o, o que
    // dava mosaicos a dizer "78%" e "Atencao" sem dizer 78% de que: num dispositivo com
    // telemetria a chegar, o cartao perdia o nome exactamente quando tinha o que mostrar.
    const title = capabilityLabel(type) || card.value || type;
    const value = isSystemRequestCard ? card.value : lastValue;
    // A card may ask for the full row and supply its own body, so richer
    // telemetry does not need a special case in this shell.
    const span = lastContent?.span || card.span || 6;
    const bodyHtml = lastContent?.body || "";
    // "A pedir" durava o que durava a chamada HTTP que punha o pedido na fila, e desaparecia
    // no instante em que o pedido passava a existir de verdade: o mosaico esquecia-o
    // exactamente quando havia algo para dizer, e o unico sitio que ainda sabia era a lista
    // ao lado. A pastilha passa a seguir o estado do pedido, que e o que o utilizador
    // esperava ver quando clicou.
    const requestState = requestable
        ? latestRequestState(
              type,
              commands,
              lastTelemetry ? eventTime(lastTelemetry) : 0,
          )
        : null;

    return telemetryCard({
        span,
        icon,
        title,
        // O valor so aparece quando diz algo que o titulo nao diga.
        value: value && value !== title ? value : "",
        // O `details` era calculado e deitado fora: a linha da lista de eventos desenhava-o
        // e o mosaico ignorava-o. Era por isso que o estado de sono nunca aparecia no
        // cartao dos sinais vitais, apesar de o hub o mandar desde sempre.
        details: isSystemRequestCard ? "" : lastContent?.details || "",
        tooltip,
        body: bodyHtml,
        // Um cartao que nao se pode pedir nao e clicavel: o que nao responde ao clique
        // nao deve parecer que responde.
        feature: requestable ? type : "",
        pending: requestable && loading,
        stateLabel: loading ? "a pedir" : requestState?.label || "",
        stateTone: loading ? "config-state-warning" : requestState?.tone || "",
        tone: cardTone(type),
    });
}

/** A cor da categoria, para o icone. Sem entrada na tabela, o icone fica neutro. */
export function cardTone(type) {
    return CARD_TONE_BY_TYPE[type] || "";
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
    // O indice de humidade e uma capacidade a parte, mas nao um cartao a parte: o cartao
    // dos canais mostra-o como valor, e sozinho era um segundo cartao a dizer o mesmo.
    if (type === "diaper_moisture") {
        return ["diaper_moisture", "diaper_moisture_level"];
    }
    return [type];
}

export function statusBadge(status) {
    const tone = STATUS_BADGE_TONE[status] ?? "config-state-secondary";
    const label = STATUS_BADGE_LABEL[status] || titleize(status).toLowerCase();
    return `<span class="config-state ${tone}"><span class="config-state-dot"></span>${esc(label)}</span>`;
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
        // O `fieldValue` traduz o que for enumeracao e deixa passar numeros e texto livre.
        // Sem ele o cartao punha "Estado do sono: awake" -- a etiqueta em portugues e o
        // valor cru ao lado dela.
        .map((key) => `${esc(fieldLabel(key))}: ${esc(fieldValue(key, data[key]))}`)
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
