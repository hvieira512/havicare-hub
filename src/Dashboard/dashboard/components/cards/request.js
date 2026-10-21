import { eventTime, rowPayload } from "../../format.js";
import { valueLabel } from "../../i18n.js";
import { capabilityLabel } from "../../capability-catalog.js";
import { stateBadge } from "../state-badge.js";
import { telemetryCard } from "./shell.js";
import { cardIcon, cardTone, uplinkCardContent } from "./telemetry.js";
import { locationCoordinates } from "./location.js";

/** O cartão de pedido: o que se pede, a última leitura da categoria e o estado do pedido. */

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

/** O tom de cada estado de um pedido. O vazio do `sent` deixa-o no azul da marca. */
const DOWNLINK_TONE = {
    queued: "secondary",
    sent: "",
    waiting: "warning",
    acked: "success",
    failed: "danger",
    dropped: "danger",
    superseded: "secondary",
    unknown: "secondary",
};

/** Os que o mosaico mostra: sem `acked`, que a resposta já é o valor, nem `superseded`. */
const REQUEST_CARD_STATES = ["queued", "sent", "waiting", "failed", "dropped"];

const downlinkState = (status) => ({
    label: valueLabel("downlinkStatus", status) ?? valueLabel("downlinkStatus", "unknown"),
    tone: DOWNLINK_TONE[status] ?? "secondary",
});

export function statusBadge(status) {
    const { label, tone } = downlinkState(status);

    return stateBadge(label, tone);
}

/** O pedido mais recente da categoria; uma falha só conta se não chegou leitura depois dela. */
function latestRequestState(type, commands, lastTelemetryTime) {
    const latest = commands
        .filter((command) => command.feature === type)
        .sort((left, right) => commandTime(right) - commandTime(left))[0];
    if (!latest) {
        return null;
    }

    const status = String(latest.status || "");
    if (!REQUEST_CARD_STATES.includes(status)) {
        return null;
    }
    const entry = downlinkState(status);

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
    // O índice de humidade é uma capacidade à parte, mas não um cartão à parte: o cartão
    // dos canais mostra-o como valor.
    if (type === "diaper_moisture") {
        return ["diaper_moisture", "diaper_moisture_level"];
    }
    return [type];
}

export function requestCardShell(
    command,
    loading,
    telemetry = [],
    commands = [],
) {
    const type = command.feature;
    const card = requestCardContent(type);
    const requestable = command.requestable !== false;
    const telemetryTypes = requestTelemetryTypes(type);
    const payloads = telemetry
        .map(rowPayload)
        .filter(
            (payload) =>
                payload && telemetryTypes.includes(String(payload.type || "")),
        )
        .sort((a, b) => eventTime(b) - eventTime(a));

    // A mais recente que serve: um relatório sem posição apagaria o fixo bom de antes.
    const usable = USABLE_PAYLOAD[type];
    const pickOfType = (wanted) => {
        const ofType = payloads.filter(
            (payload) => String(payload.type || "") === wanted,
        );
        return (usable ? ofType.find(usable) : null) ?? ofType[0];
    };
    const lastTelemetry =
        (usable ? payloads.find(usable) : null) ?? payloads[0];

    // A mais recente de cada tipo: a fralda manda os canais numa mensagem e o índice noutra.
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
    const lastValue = lastContent ? lastContent.value : "";
    // Um ícone tirado da leitura vence o estático: um gateway com fios não mostra Wi-Fi.
    const icon = lastContent?.icon || card.icon;
    // Pela mesma razão o tom: uma bateria quase vazia não fica no verde de uma cheia.
    const tone = lastContent?.tone || cardTone(type);
    // O título é sempre o nome da categoria: "78%" sozinho não diz 78% de quê.
    const title = capabilityLabel(type) || card.value || type;
    const value = lastValue;
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
        badge: lastContent?.iconBadge || "",
        title,
        // O valor só aparece quando diz algo que o título não diga.
        value: value && value !== title ? value : "",
        details: lastContent?.details || "",
        detailsTitle: lastContent?.detailsTitle || "",
        body: bodyHtml,
        feature: requestable ? type : "",
        // A presença abre a planta da divisão, e só existe em radares.
        action: type === "presence" ? "openRadarMap" : "",
        pending: requestable && loading,
        stateLabel: loading ? "a pedir" : requestState?.label || "",
        stateTone: loading ? "warning" : requestState?.tone || "",
        tone,
    });
}
