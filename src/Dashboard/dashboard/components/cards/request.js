import { ago, eventTime, rowPayload, titleize } from "../../format.js";
import { capabilityLabel } from "../../capability-catalog.js";
import { html, raw } from "../../html.js";
import { stateBadge } from "../state-badge.js";
import { telemetryCard } from "./shell.js";
import { cardIcon, cardTone, uplinkCardContent } from "./telemetry.js";
import { locationCoordinates } from "./location.js";

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

/** O estado de um pedido na linha do painel. O tom vazio do `sent` deixa-o no azul da marca. */
const DOWNLINK_STATE = {
    queued: { label: "em fila", tone: "secondary" },
    sent: { label: "enviado", tone: "" },
    waiting: { label: "à espera", tone: "warning" },
    acked: { label: "confirmado", tone: "success" },
    failed: { label: "falhou", tone: "danger" },
    dropped: { label: "descartado", tone: "danger" },
    superseded: { label: "substituído", tone: "secondary" },
    unknown: { label: "desconhecido", tone: "secondary" },
};

export function statusBadge(status) {
    const known = DOWNLINK_STATE[status];

    return stateBadge(
        known?.label || titleize(status).toLowerCase(),
        known?.tone ?? "secondary",
    );
}

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

/** As leituras de uma categoria, da mais recente para a mais antiga. */
function payloadsOfFeature(type, telemetry) {
    const telemetryTypes = requestTelemetryTypes(type);

    return telemetry
        .map(rowPayload)
        .filter(
            (payload) =>
                payload && telemetryTypes.includes(String(payload.type || "")),
        )
        .sort((a, b) => eventTime(b) - eventTime(a));
}

/**
 * Se alguma vez chegou leitura desta categoria. É o que separa um mosaico de uma pastilha:
 * sem nenhuma, o mosaico mostrava um lugar vazio a parecer um valor.
 */
export function hasReading(command, telemetry = []) {
    return payloadsOfFeature(commandFeature(command), telemetry).length > 0;
}

/** A conta que a cabeça da secção mostra. O lado que está a zero não se escreve. */
export function readingCountLabel(withReading, withoutReading) {
    const parts = [];
    if (withReading > 0) {
        parts.push(`${withReading} com leitura`);
    }
    if (withoutReading > 0) {
        parts.push(withReading > 0 ? `${withoutReading} sem` : `${withoutReading} sem leitura`);
    }

    return parts.join(" · ");
}

/**
 * A pastilha de uma capacidade que nunca mediu: o nome, e o botão de pedir quando se pode.
 * O tom fica de fora de propósito -- a cor identifica quem tem leitura.
 */
export function requestPill(command, loading = false) {
    const type = commandFeature(command);
    const label = capabilityLabel(type) || type;
    const button = command.requestable === false
        ? ""
        : html`<button type="button" class="btn btn-sm btn-primary rounded-pill py-0 px-2 flex-shrink-0" data-action="requestFeature" data-feature="${type}"${raw(loading ? " disabled" : "")}>${loading ? "A pedir" : "Pedir"}</button>`;

    return html`<div class="telemetry-pill d-inline-flex align-items-center gap-2 border rounded-pill ps-2 pe-2 py-1 bg-body-tertiary">
        <span class="telemetry-card-icon d-flex align-items-center justify-content-center flex-shrink-0 rounded-2"><i class="fa-solid ${cardIcon(type)}"></i></span>
        <span class="text-secondary">${label}</span>
        ${raw(button)}
    </div>`;
}

export function requestCardShell(
    command,
    loading,
    telemetry = [],
    commands = [],
) {
    const type = commandFeature(command);
    const card = requestCardContent(type);
    const requestable = command.requestable !== false;
    const telemetryTypes = requestTelemetryTypes(type);
    const payloads = payloadsOfFeature(type, telemetry);

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
    // Sem instante não se escreve nada: o `ago` de um vazio diz «nunca», e isso é outra
    // afirmação -- a de que o aparelho nunca mediu, que não é o que se sabe aqui.
    const readingAt = lastTelemetry?.occurredAt || lastTelemetry?.recordedAt || "";
    // Um ícone tirado da leitura vence o estático: um gateway com fios não mostra Wi-Fi.
    const icon = lastContent?.icon || card.icon;
    // O título é sempre o nome da categoria: "78%" sozinho não diz 78% de quê.
    const title = capabilityLabel(type) || card.value || type;
    const value = lastValue;
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
        details: lastContent?.details || "",
        age: readingAt ? ago(readingAt) : "",
        detailsTitle: lastContent?.detailsTitle || "",
        body: bodyHtml,
        // O que não responde ao clique não deve parecer que responde.
        feature: requestable ? type : "",
        // A presença abre a planta da divisão. É o cartão que já diz quantas pessoas lá
        // estão, e a presença só existe em radares -- não é preciso perguntar pelo tipo.
        action: type === "presence" ? "openRadarMap" : "",
        pending: requestable && loading,
        stateLabel: loading ? "a pedir" : requestState?.label || "",
        stateTone: loading ? "warning" : requestState?.tone || "",
        tone: cardTone(type),
    });
}
