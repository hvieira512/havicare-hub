import { html, raw } from "../html.js";

/**
 * Os cartões do sensor de fralda: humidade, bandas e canais afetados.
 */

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

export function diaperMoistureBody(data) {
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
export function diaperMoistureRowValue(data) {
    const channels = Array.isArray(data?.channels) ? data.channels : [];
    if (channels.length === 0) {
        return "";
    }

    const maximum = Math.max(0, Number(data?.maximumDelta ?? 0) || 0);
    const affected = Math.max(0, Number(data?.affectedChannelCount ?? 0) || 0);
    return `máx. ${maximum} · ${affected} de ${diaperRequiredChannels(data, channels.length)} acima do limiar`;
}
