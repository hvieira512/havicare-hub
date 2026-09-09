import { eventTime, rowPayload } from "./format.js";
import { capabilityLabel } from "./capability-catalog.js";
import { telemetryCard } from "./card-shell.js";
import { cardIcon, cardTone, uplinkCardContent, locationCoordinates } from "./telemetry-cards.js";

/**
 * O cartão de pedido (downlink): o que se *pede* a um dispositivo, com a última leitura da
 * categoria e o estado do comando mais recente. É a contraparte do catálogo de uplink -- usa a
 * mesma casca e os mesmos renderizadores, mas o que o move é o comando, não a telemetria.
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

function commandFeature(command) {
    if (command.feature) return command.feature;
    const haystack =
        `${command.command || ""} ${command.label || ""}`.toLowerCase();
    for (const [needle, feature] of COMMAND_FEATURE_RULES) {
        if (haystack.includes(needle)) return feature;
    }
    return "device_config";
}

/** Só a localização precisa disto: um relatório sem coordenadas é válido e não diz onde. */
const USABLE_PAYLOAD = {
    location: (payload) => locationCoordinates(payload?.data) !== null,
};

export function requestCardContent(type) {
    return {
        icon: cardIcon(type),
        value: capabilityLabel(type),
    };
}

/**
 * Os estados de um pedido que o mosaico mostra. Sem `acked`, porque a resposta já é o
 * valor, nem `superseded`, porque há um pedido mais recente atrás dele.
 */
const REQUEST_CARD_STATE = {
    queued: { label: "em fila", tone: "secondary" },
    sent: { label: "enviado", tone: "secondary" },
    waiting: { label: "à espera", tone: "warning" },
    failed: { label: "falhou", tone: "danger" },
    dropped: { label: "descartado", tone: "danger" },
};

/**
 * O estado do pedido mais recente desta categoria. Uma falha só se mostra enquanto for a
 * última palavra: se chegou uma leitura depois dela, o dispositivo respondeu.
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

    const failed = entry.tone === "danger";
    if (
        failed &&
        lastTelemetryTime &&
        lastTelemetryTime > commandTime(latest)
    ) {
        return null;
    }

    return entry;
}

/** Não é o `eventTime`: um pedido traz `requestedAt`, não `occurredAt` nem `recordedAt`. */
function commandTime(command) {
    const time = Date.parse(command?.requestedAt || "");
    return Number.isNaN(time) ? eventTime(command) : time;
}

function requestTelemetryTypes(type) {
    if (type === "positions") {
        return ["position"];
    }
    if (type === "vitals") {
        return ["vitals"];
    }
    if (type === "position_minute_stats" || type === "vitals_minute_stats") {
        return [type];
    }
    // O índice de humidade é uma capacidade à parte, mas não um cartão à parte: o cartão
    // dos canais mostra-o como valor.
    if (type === "diaper_moisture") {
        return ["diaper_moisture", "diaper_moisture_level"];
    }
    return [type];
}

export function renderRequestCardShell(
    command,
    loading,
    telemetry = [],
    commands = [],
) {
    const type = commandFeature(command);
    const card = requestCardContent(type);
    const requestable = command.requestable !== false;
    const isSystemRequestCard = ["firmware_version", "device_status"].includes(
        type,
    );

    const telemetryTypes = requestTelemetryTypes(type);
    const payloads = telemetry
        .map(rowPayload)
        .filter(
            (payload) =>
                payload && telemetryTypes.includes(String(payload.type || "")),
        )
        .sort((a, b) => eventTime(b) - eventTime(a));

    // A mais recente que serve: um relatório de localização sem posição apagaria o fixo bom
    // de dois minutos antes. Sem nenhuma que sirva, fica a última.
    const usable = USABLE_PAYLOAD[type];
    const pickOfType = (wanted) => {
        const ofType = payloads.filter(
            (payload) => String(payload.type || "") === wanted,
        );
        return (usable ? ofType.find(usable) : null) ?? ofType[0];
    };
    const lastTelemetry =
        (usable ? payloads.find(usable) : null) ?? payloads[0];

    // A mais recente de CADA tipo, e não das duas em conjunto: a humidade da fralda tem os
    // canais numa mensagem e o índice noutra, e ficar com uma apagava a outra.
    const lastData = Object.assign(
        {},
        ...telemetryTypes
            .map(pickOfType)
            .reverse()
            .map((payload) => payload?.data || {}),
    );

    const lastContent = lastTelemetry
        ? uplinkCardContent(type, lastData, {
                occurredAt: lastTelemetry.occurredAt || lastTelemetry.recordedAt,
            })
        : null;
    // Sem leitura não há valor, e o mosaico não leva etiqueta nenhuma a dizê-lo: o lugar do
    // valor vazio, ao lado dos irmãos que têm um, já se lê como ausência de leitura. Escrevê-lo
    // por palavras era repetir o que o vazio diz, multiplicado pelos mosaicos vazios do ecrã.
    const lastValue = lastContent ? lastContent.value : "";
    // Um ícone tirado da leitura vence o estático: um gateway com fios não mostra Wi-Fi.
    const icon = command.icon || lastContent?.icon || card.icon;
    // O título é sempre o nome da categoria: "78%" sozinho não diz 78% de quê.
    const title = capabilityLabel(type) || card.value || type;
    const value = isSystemRequestCard ? card.value : lastValue;
    // Um cartão pode pedir a linha toda e trazer o seu próprio corpo.
    const span = lastContent?.span || card.span || 6;
    const bodyHtml = lastContent?.body || "";
    // A pastilha segue o estado do pedido, e não a chamada HTTP que o pôs na fila.
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
        // O valor só aparece quando diz algo que o título não diga.
        value: value && value !== title ? value : "",
        details: isSystemRequestCard ? "" : lastContent?.details || "",
        detailsTitle: isSystemRequestCard
            ? ""
            : lastContent?.detailsTitle || "",
        body: bodyHtml,
        // O que não responde ao clique não deve parecer que responde.
        feature: requestable ? type : "",
        pending: requestable && loading,
        stateLabel: loading ? "a pedir" : requestState?.label || "",
        stateTone: loading ? "warning" : requestState?.tone || "",
        tone: cardTone(type),
    });
}
