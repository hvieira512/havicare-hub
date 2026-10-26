import { html } from "../../html.js";
import { ago, displayPersonIndex, eventTime, rowPayload, when } from "../../format.js";
import { fallLabel } from "../../domain.js";
import { valueLabel } from "../../i18n.js";

/** Cartões que resumem o histórico de eventos inteiro, fora do catálogo de cartões. */

// O que separa os modos é quantos toques, ou quanto dura um, e é isso que o ícone diz.
const HELP_CALL_PRESS_ICON = {
    single: "fa-1",
    double: "fa-2",
    triple: "fa-3",
    long: "fa-stopwatch",
};

/** Um `pressType` fora disto ignora-se. */
const HELP_CALL_PRESS_MODES = ["single", "double", "triple", "long"];

/** A última chamada de ajuda por modo de toque; sem modos declarados não há cartão. */
export function helpCallSummaryCard(events = [], pressModes = []) {
    if (pressModes.length === 0) {
        return "";
    }

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

    const columns = pressModes.map((mode) => {
        const call = latest[mode];
        // A etiqueta partilhada é um sufixo; aqui titula, e vai capitalizada.
        const suffix = valueLabel("pressType", mode);
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
            : html`<span class="help-call-never">nunca</span>`;

        return html`<div class="col-12 col-md-4">
<div class="d-flex align-items-center gap-2 border rounded p-2 h-100${called ? "" : " opacity-50"}"${occurredAttr}${tooltip}>
<i class="fa-solid ${icon} ${called ? "text-danger" : "text-body-secondary"}" style="width:1.25rem;text-align:center;flex-shrink:0;"></i>
<div class="min-w-0">
<div class="fw-semibold text-truncate">${label}</div>
<div class="small text-body-secondary">${since}</div>
</div>
</div>
</div>`;
    });

    return html`<div class="telemetry-card-wide min-w-0">
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

/** O último alarme de proximidade, do histórico, com o gateway que o deu. */
export function proximityAlarmSummaryCard(events) {
    const latest = (Array.isArray(events) ? events : [])
        .map(rowPayload)
        .filter((payload) => String(payload?.type || "") === "proximity_alarm")
        .sort((a, b) => eventTime(b) - eventTime(a))[0];
    if (latest === undefined) {
        return "";
    }

    const occurredAt = latest.occurredAt || latest.recordedAt || "";

    return html`<div class="telemetry-card-wide min-w-0">
<div class="card h-100 border-danger">
<div class="card-body d-flex align-items-center gap-3 min-w-0">
<div class="bg-danger bg-opacity-10 rounded-3 d-flex align-items-center justify-content-center text-danger" style="width:36px;height:36px;flex-shrink:0;">
<i class="fa-solid fa-person-walking-arrow-right"></i>
</div>
<div class="min-w-0 flex-grow-1">
<div class="fw-bold text-danger">Último alarme de proximidade</div>
<div class="small text-body-secondary text-truncate" title="${when(occurredAt)}">${ago(occurredAt)} · gateway ${String(latest?.data?.gatewayId || "")}</div>
</div>
</div>
</div>
</div>`;
}

/** A última queda do radar, do histórico: só aparece se houve. */
export function fallSummaryCard(events) {
    const falls = (Array.isArray(events) ? events : [])
        .map(rowPayload)
        .filter((payload) => String(payload?.type || "") === "fall")
        .sort((a, b) => eventTime(b) - eventTime(a));

    const latest = falls[0];
    if (latest === undefined) {
        return "";
    }

    const occurredAt = latest.occurredAt || latest.recordedAt || "";
    const label = fallLabel(latest?.data);
    const person = latest?.data?.personIndex;
    const who =
        person === undefined || person === null
            ? ""
            : html` · Pessoa ${displayPersonIndex(person)}`;

    return html`<div class="telemetry-card-wide min-w-0">
<div class="card h-100 border-danger">
<div class="card-body d-flex align-items-center gap-3 min-w-0">
<div class="bg-danger bg-opacity-10 rounded-3 d-flex align-items-center justify-content-center text-danger" style="width:36px;height:36px;flex-shrink:0;">
<i class="fa-solid fa-person-falling"></i>
</div>
<div class="min-w-0 flex-grow-1">
<div class="fw-bold text-danger">Última queda</div>
<div class="small text-body-secondary text-truncate" title="${when(occurredAt)}">${ago(occurredAt)} · ${label}${who}</div>
</div>
</div>
</div>
</div>`;
}
