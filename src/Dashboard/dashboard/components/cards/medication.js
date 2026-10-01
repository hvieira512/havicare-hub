import { fieldValue } from "../../format.js";
import { html, raw } from "../../html.js";
import { runoutAt, runoutLabel } from "../../devices/medication-runout.js";
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
    awaiting_retrieval: "pending",
    timed_out: "missed",
    retrieval_timed_out: "missed",
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

/**
 * A posição do carrossel na linguagem do prato.
 *
 * O prato não tem números: tem um autocolante de esquema com grupos de doses e uma marca de
 * início. O `21` que o aparelho conta por dentro não se encontra lá, mas «dia 7» conta-se em
 * sete grupos a partir da marca. As doses por dia vêm do plano que o aparelho confirmou ter.
 *
 * Sem plano não se inventa dia nenhum: fica o número cru, que é verdade mesmo quando não
 * ajuda.
 */
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

/**
 * As doses do dia, tal como o aparelho as reporta numa leitura dos nove alarmes.
 *
 * Uma toma falhada é o que faz alguém olhar para o cartão, e por isso ganha o valor
 * principal; sem falhas, o que vale é quantas foram tomadas. O corpo é a faixa do dia, uma
 * coluna por dose marcada, na ordem das horas.
 */
export function medicationAlarmContent(data) {
    const alarms = Array.isArray(data?.alarms) ? data.alarms : [];
    const live = alarms.filter((entry) => entry?.state && entry.state !== "idle");
    const missed = Number(data?.missedCount ?? 0);
    const taken = Number(data?.takenCount ?? 0);

    const counts = [
        missed > 0 ? `${missed} ${missed === 1 ? "falhada" : "falhadas"}` : "",
        taken > 0 ? `${taken} ${taken === 1 ? "tomada" : "tomadas"}` : "",
    ].filter(Boolean);

    const strip = medicationDoseStrip(alarms);

    return {
        value: counts.length > 0 ? counts.join(" · ") : "Sem tomas registadas",
        // O texto das doses vivas só aparece onde a faixa não chega -- a linha da lista de
        // actividade, que não desenha corpo nenhum. No cartão seria a mesma informação duas
        // vezes, uma em cima da outra.
        details: strip === ""
            ? live
                    .map((entry) => html`${doseLabel(entry.alarm)}: ${fieldValue("state", entry.state)}`)
                    .join(" · ")
            : "",
        span: 12,
        body: strip,
    };
}
