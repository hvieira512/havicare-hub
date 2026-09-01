import { html, raw } from "../html.js";
import { ago, displayPersonIndex, eventTime, fieldLabel, rowPayload, when } from "../format.js";
import { DETECTION_TYPE_LABEL, PRESS_TYPE_LABEL } from "../domain.js";

/**
 * Os dois cartões que resumem o histórico de eventos: a última chamada de ajuda por modo de
 * toque, e a última queda que o radar viu.
 *
 * Estão à parte do `telemetry-cards.js` porque são widgets e não entradas de um catálogo --
 * aquele tem um cartão por tipo de telemetria, estes leem o histórico inteiro e resumem-no.
 */

/** Os modos que se mostram quando o protocolo não diz quais são os seus. */
const DEFAULT_PRESS_MODES = ["single", "double", "long"];

// O que separa os modos é quantos toques, ou quanto dura um, e é isso que o ícone diz.
const HELP_CALL_PRESS_ICON = {
    single: "fa-1",
    double: "fa-2",
    triple: "fa-3",
    long: "fa-stopwatch",
};

/** Os modos que contam como chamada de ajuda. Um `pressType` fora disto ignora-se. */
const HELP_CALL_PRESS_MODES = ["single", "double", "triple", "long"];

/**
 * A última chamada de ajuda por modo de toque. A pulseira só anuncia enquanto está em alarme,
 * por isso não há como saber que uma chamada foi cancelada -- reporta-se quando aconteceu.
 */
export function helpCallSummaryCard(events = [], pressModes = []) {
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
        if (
            latest[mode] === undefined ||
            eventTime(call) > eventTime(latest[mode])
        ) {
            latest[mode] = call;
        }
    }

    // Três lado a lado num ecrã grande, empilhadas no telemóvel.
    const modes = pressModes.length > 0 ? pressModes : DEFAULT_PRESS_MODES;
    const columns = modes.map((mode) => {
        const call = latest[mode];
        // A etiqueta partilhada lê-se como sufixo ("... (toque simples)"); aqui titula uma
        // coluna, por isso vai capitalizada.
        const suffix = PRESS_TYPE_LABEL[mode];
        const label = suffix.charAt(0).toUpperCase() + suffix.slice(1);
        const icon = HELP_CALL_PRESS_ICON[mode];
        const called = call !== undefined;
        const occurredAt = called
            ? call.occurredAt || call.recordedAt || ""
            : "";
        // O tempo relativo é o legível; a hora exacta espera atrás da tooltip.
        const tooltip = called
            ? html` data-bs-toggle="tooltip" data-bs-trigger="hover focus" data-bs-placement="top" data-bs-title="${when(occurredAt)}" aria-label="${label}: ${when(occurredAt)}" tabindex="0"`
            : "";
        const occurredAttr = called ? html` data-occurred-at="${occurredAt}"` : "";
        const since = called
            ? html`${ago(occurredAt)}`
            : "<span class=\"help-call-never\">nunca</span>";

        return html`<div class="col-12 col-md-4">
<div class="d-flex align-items-center gap-2 border rounded p-2 h-100${called ? "" : " opacity-50"}"${raw(occurredAttr)}${raw(tooltip)}>
<i class="fa-solid ${icon} ${called ? "text-danger" : "text-body-secondary"}" style="width:1.25rem;text-align:center;flex-shrink:0;"></i>
<div class="min-w-0">
<div class="fw-semibold text-truncate">${label}</div>
<div class="small text-body-secondary">${raw(since)}</div>
</div>
</div>
</div>`;
    }).join("");

    return html`<div class="col-12">
<div class="card h-100 border-danger">
<div class="card-body">
<div class="d-flex align-items-center gap-3 min-w-0 mb-3">
<div class="bg-danger bg-opacity-10 rounded-3 d-flex align-items-center justify-content-center text-danger" style="width:36px;height:36px;flex-shrink:0;">
<i class="fa-solid fa-triangle-exclamation"></i>
</div>
<div class="fw-bold text-danger flex-grow-1 min-w-0">Últimas chamadas de ajuda</div>
</div>
<div class="row g-2">${raw(columns)}</div>
</div>
</div>
</div>`;
}

/**
 * A última queda que o radar viu, tirada do histórico de eventos e não de uma capacidade:
 * aparece só quando houve queda, e não ocupa o mosaico nos dias em que não houve.
 *
 * Ninguém está a olhar para o ecrã no instante em que alguém cai; o que fica é o registo.
 */
export function fallSummaryCard(events = []) {
    const falls = (Array.isArray(events) ? events : [])
        .map(rowPayload)
        .filter((payload) => String(payload?.type || "") === "fall")
        .sort((a, b) => eventTime(b) - eventTime(a));

    const latest = falls[0];
    if (latest === undefined) {
        return "";
    }

    const occurredAt = latest.occurredAt || latest.recordedAt || "";
    const detectionType = String(latest?.data?.detectionType || "");
    const label = DETECTION_TYPE_LABEL[detectionType] || fieldLabel(detectionType);
    const person = latest?.data?.details?.person_index;
    const who =
        person === undefined || person === null
            ? ""
            : html` · Pessoa ${displayPersonIndex(person)}`;

    return html`<div class="col-12">
<div class="card h-100 border-danger">
<div class="card-body d-flex align-items-center gap-3 min-w-0">
<div class="bg-danger bg-opacity-10 rounded-3 d-flex align-items-center justify-content-center text-danger" style="width:36px;height:36px;flex-shrink:0;">
<i class="fa-solid fa-person-falling"></i>
</div>
<div class="min-w-0 flex-grow-1">
<div class="fw-bold text-danger">Última queda</div>
<div class="small text-body-secondary text-truncate" title="${when(occurredAt)}">${ago(occurredAt)} · ${label}${raw(who)}</div>
</div>
</div>
</div>
</div>`;
}
