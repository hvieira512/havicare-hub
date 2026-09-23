import { fieldValue } from "../../format.js";
import { html, raw } from "../../html.js";
import { state } from "../../state.js";

/**
 * A faixa do dia: as doses do dispensador, uma coluna cada, na ordem das horas. A hora é o que
 * identifica uma dose, e vem do plano de medicação; o número do alarme fica na legenda.
 */

/** Os nove do protocolo, em `PillDispenserAdapter::ALARM_SLOTS`. O frontend não os partilha. */
const ALARM_SLOTS = 9;

/** Três famílias de cor e não seis: o que correu bem, o que está a decorrer, e o que falhou. */
const DOSE_BAND = {
    taken: "taken",
    preparing: "pending",
    waiting: "pending",
    timed_out: "missed",
    missed: "missed",
};

/**
 * A hora de cada alarme, do plano que o aparelho confirmou ter — do efectivo e não do
 * desejado, que é o que lhe pedimos e ele pode ainda não ter.
 *
 * @returns {Map<number, string>}
 */
function planHours() {
    const plans = state.selectedDetail?.effectiveConfigurations?.medication_reminders?.plans;
    const hours = new Map();
    if (!Array.isArray(plans)) {
        return hours;
    }

    plans.forEach((plan, position) => {
        if (plan?.enabled === false) {
            return;
        }
        const slot = Number(plan?.slot ?? position + 1);
        const hour = Number(plan?.hour ?? 0);
        const minute = Number(plan?.minute ?? 0);
        if (slot >= 1 && slot <= ALARM_SLOTS) {
            hours.set(slot, `${String(hour).padStart(2, "0")}:${String(minute).padStart(2, "0")}`);
        }
    });

    return hours;
}

/** A hora de um alarme, ou o nome dele quando o plano ainda não foi lido. */
export function doseLabel(slot) {
    return planHours().get(Number(slot)) || `Alarme ${slot}`;
}

/**
 * @param {Array<{alarm: number, state: string}>} alarms
 * @returns {string}
 */
export function medicationDoseStrip(alarms) {
    if (!Array.isArray(alarms) || alarms.length < ALARM_SLOTS) {
        return "";
    }

    const hours = planHours();
    const bySlot = new Map(alarms.map((entry) => [Number(entry?.alarm), String(entry?.state || "idle")]));

    // Uma dose é um slot que o plano marcou. Sem plano, é um slot que o aparelho reportou
    // fora do repouso -- senão a faixa abria com nove colunas a dizer «Sem toma marcada».
    const marked = Array.from({ length: ALARM_SLOTS }, (_unused, index) => index + 1)
        .filter((slot) => (hours.size > 0 ? hours.has(slot) : (bySlot.get(slot) || "idle") !== "idle"))
        .sort((left, right) => (hours.get(left) || "").localeCompare(hours.get(right) || "") || left - right);

    const free = Array.from({ length: ALARM_SLOTS }, (_unused, index) => index + 1)
        .filter((slot) => !marked.includes(slot));

    const columns = marked.map((slot) => {
        const doseState = bySlot.get(slot) || "idle";
        const band = DOSE_BAND[doseState] || "idle";

        // A hora é o título quando existe, e o número do alarme desce para a legenda. Sem
        // plano lido é o número que sobe: é o que o aparelho garantidamente disse.
        return html`<div class="dose dose--${band} text-center" data-dose-slot="${slot}" data-dose-state="${doseState}">
<div class="dose-slot">${hours.has(slot) ? `Alarme ${slot}` : ""}</div>
<div class="dose-hour">${hours.get(slot) || `Alarme ${slot}`}</div>
<div class="dose-state">${fieldValue("state", doseState)}</div>
</div>`;
    }).join("");

    // Os lugares por marcar num só: nove posições a dizer «Sem toma marcada» são nove
    // posições a dizer nada.
    const spare = free.length === 0
        ? ""
        : html`<div class="dose dose--free text-center" data-dose-free="${free.length}">
<div class="dose-slot">Alarmes</div>
<div class="dose-hour">${free.length === 1 ? free[0] : `${free[0]}–${free[free.length - 1]}`}</div>
<div class="dose-state">Livres</div>
</div>`;

    return html`<div class="dose-strip d-grid mt-3">${raw(columns)}${raw(spare)}</div>`;
}
