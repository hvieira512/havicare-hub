import { fieldValue, hhmm } from "../../core/format.js";
import { medicationPlanTimes } from "../../core/medication-plan.js";
import { html } from "../../core/html.js";
import { runoutAt, runoutLabel } from "../../core/medication-runout.js";
import { state } from "../../core/state.js";
import { countLabel } from "../../core/i18n.js";

/** A faixa do dia: uma coluna por dose, na ordem das horas do plano de medicação. */

/** Quantos alarmes o aparelho tem: o `limit` que a API declara para o plano. */
function alarmSlots() {
    return Number(state.selectedDetail?.capabilities?.reminders?.medication_reminders?._meta?.limit) || 0;
}

/** Três famílias de cor e não seis: o que correu bem, o que está a decorrer, e o que falhou. */
const DOSE_BAND = {
    taken: "taken",
    preparing: "pending",
    waiting: "pending",
    awaiting_retrieval: "pending",
    timed_out: "missed",
    retrieval_timed_out: "missed",
    missed: "missed",
};

/** A hora de cada alarme, do plano efectivo: o desejado, o aparelho pode ainda não o ter. */
function planHours() {
    const plans = state.selectedDetail?.effectiveConfigurations?.medication_reminders?.plans;
    const slots = alarmSlots();
    const hours = new Map();

    medicationPlanTimes(plans).forEach((entry) => {
        if (!entry.enabled || entry.slot < 1 || entry.slot > slots) {
            return;
        }
        hours.set(
            entry.slot,
            hhmm(entry.hour, entry.minute),
        );
    });

    return hours;
}

/** O medicamento de um alarme, pelo plano efectivo; vazio quando não tem nome. */
export function doseName(slot) {
    const plans = state.selectedDetail?.effectiveConfigurations?.medication_reminders?.plans;

    return medicationPlanTimes(plans).find((entry) => entry.slot === Number(slot))?.name || "";
}

/** A hora de um alarme, ou o nome dele quando o plano ainda não foi lido. */
export function doseLabel(slot) {
    return planHours().get(Number(slot)) || `Alarme ${slot}`;
}

export function medicationDoseStrip(alarms) {
    const slots = alarmSlots();
    if (slots === 0 || !Array.isArray(alarms) || alarms.length < slots) {
        return "";
    }

    const hours = planHours();
    const bySlot = new Map(alarms.map((entry) => [Number(entry?.slot), String(entry?.state || "idle")]));

    // Uma dose é um slot que o plano marcou; sem plano, um slot que o aparelho reportou fora do
    // repouso.
    const marked = Array.from({ length: slots }, (_unused, index) => index + 1)
        .filter((slot) => (hours.size > 0 ? hours.has(slot) : (bySlot.get(slot) || "idle") !== "idle"))
        .sort((left, right) => (hours.get(left) || "").localeCompare(hours.get(right) || "") || left - right);

    const free = Array.from({ length: slots }, (_unused, index) => index + 1)
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
    });

    // Os lugares por marcar juntam-se num só.
    const spare = free.length === 0
        ? ""
        : html`<div class="dose dose--free text-center" data-dose-free="${free.length}">
<div class="dose-slot">Alarmes</div>
<div class="dose-hour">${free.length === 1 ? free[0] : `${free[0]}–${free[free.length - 1]}`}</div>
<div class="dose-state">Livres</div>
</div>`;

    return html`<div class="dose-strip d-grid mt-3">${columns}${spare}</div>`;
}

/** A posição do carrossel como o prato a conta, «dia 7» e não `21`; sem plano, o número cru. */
export function cyclePosition(current) {
    const plans = state.selectedDetail?.effectiveConfigurations?.medication_reminders?.plans;
    if (!Array.isArray(plans) || current == null || current < 1) {
        return "";
    }

    const perDay = plans.filter((plan) => plan?.enabled !== false).length;
    if (perDay < 1) {
        return "";
    }

    const day = Math.ceil(current / perDay);
    const dose = ((current - 1) % perDay) + 1;

    return perDay === 1 ? `Dia ${day}` : `Dia ${day}, ${dose}ª dose`;
}

/** Quando sai a última dose carregada, em palavras. Vazio sem plano ou fora do período. */
export function cycleRunout(remaining) {
    const configurations = state.selectedDetail?.effectiveConfigurations;

    return runoutLabel(runoutAt({
        doses: remaining,
        plans: configurations?.medication_reminders?.plans,
        period: configurations?.medication_period,
    }));
}

/** As doses do dia: uma toma falhada ganha o valor principal; sem falhas, quantas se tomaram. */
export function medicationAlarmContent(data) {
    const alarms = Array.isArray(data?.alarms) ? data.alarms : [];
    const live = alarms.filter((entry) => entry?.state && entry.state !== "idle");
    const missed = Number(data?.missedCount ?? 0);
    const taken = Number(data?.takenCount ?? 0);

    const counts = [
        missed > 0 ? `${countLabel(missed, "falhada", "falhadas")}` : "",
        taken > 0 ? `${countLabel(taken, "tomada", "tomadas")}` : "",
    ].filter(Boolean);

    const strip = medicationDoseStrip(alarms);

    return {
        value: counts.length > 0 ? counts.join(" · ") : "Sem tomas registadas",
        // O texto das doses vivas só aparece onde a faixa não chega: a linha da lista de actividade.
        details: strip === ""
            ? live
                    .map((entry) => html`${doseLabel(entry.slot)}: ${fieldValue("state", entry.state)}`)
                    .join(" · ")
            : "",
        span: 12,
        body: strip,
    };
}
