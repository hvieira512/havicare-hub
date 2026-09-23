import { fieldValue } from "../../format.js";
import { html, raw } from "../../html.js";

/**
 * A grelha dos nove alarmes do dispensador.
 *
 * O cartão listava em texto os alarmes que não estavam parados, uma linha cada. Com nove
 * compartimentos e um estado em cada, o que se quer saber de relance é quais falharam e quais
 * estão à espera — e isso lê-se numa grelha em que cada posição é sempre a mesma, não numa
 * lista que muda de comprimento conforme o dia.
 *
 * É a mesma decisão que a tira de canais da fralda: o corpo do cartão desenha, e o valor
 * continua a ser o número que se lê de longe.
 */

/** O aparelho tem nove alarmes fixos, e a grelha mostra-os todos mesmo quando estão parados. */
const ALARM_SLOTS = 9;

/**
 * A família de cor de cada estado. São três e não seis: o que interessa distinguir é o que
 * correu bem, o que está a acontecer, e o que falhou. Os estados intermédios do aparelho --
 * «a preparar» e «à espera» -- são o mesmo para quem olha, e o título diz qual é.
 */
const ALARM_STATE_BAND = {
    taken: "taken",
    preparing: "pending",
    waiting: "pending",
    timed_out: "missed",
    missed: "missed",
};

export function medicationAlarmGrid(alarms) {
    if (!Array.isArray(alarms) || alarms.length < ALARM_SLOTS) {
        return "";
    }

    const bySlot = new Map(
        alarms.map((entry) => [Number(entry?.alarm), String(entry?.state || "idle")]),
    );

    const slots = Array.from({ length: ALARM_SLOTS }, (_unused, index) => {
        const slot = index + 1;
        const state = bySlot.get(slot) || "idle";
        const band = ALARM_STATE_BAND[state] || "idle";

        return html`<div class="alarm-slot alarm-slot--${band} d-flex align-items-center justify-content-center rounded-2 tabular-nums lh-1" data-alarm-slot="${slot}" data-alarm-state="${state}" title="Alarme ${slot}: ${fieldValue("state", state)}">${slot}</div>`;
    }).join("");

    return html`<div class="alarm-grid d-grid mt-3">${raw(slots)}</div>`;
}
