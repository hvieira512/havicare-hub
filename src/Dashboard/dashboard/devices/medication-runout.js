/**
 * Quando é que a medicação carregada acaba: percorrem-se os alarmes do plano a partir de
 * agora. Dividir a capacidade do prato pelas doses só bate certo com o carrossel no zero.
 */

const WEEKDAYS = ["dom.", "seg.", "ter.", "qua.", "qui.", "sex.", "sáb."];
const MONTHS = [
    "jan.", "fev.", "mar.", "abr.", "mai.", "jun.",
    "jul.", "ago.", "set.", "out.", "nov.", "dez.",
];

/** Dias a procurar antes de desistir: vinte e oito compartimentos a uma dose por dia dão 28. */
const SEARCH_DAYS = 400;

/** `AAAA-MM-DD` na meia-noite local; `new Date(texto)` leria em UTC e trocava o dia. */
function localDate(text, hour, minute) {
    const parts = String(text || "").split("-");
    if (parts.length !== 3) {
        return null;
    }

    const date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]), hour, minute);
    return Number.isNaN(date.getTime()) ? null : date;
}

function planHours(plans) {
    if (!Array.isArray(plans)) {
        return [];
    }

    return plans
        .filter((plan) => plan?.enabled !== false &&
            Number.isInteger(plan?.hour) && Number.isInteger(plan?.minute))
        .map((plan) => ({ hour: plan.hour, minute: plan.minute }))
        .sort((a, b) => (a.hour * 60 + a.minute) - (b.hour * 60 + b.minute));
}

function startOfDay(date) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function daysBetween(from, to) {
    return Math.round((startOfDay(to) - startOfDay(from)) / 86400000);
}

/** O instante da última dose carregada. Sem plano, ou fora do período, não há data. */
export function runoutAt({ doses, plans, period, now = new Date() }) {
    const hours = planHours(plans);
    const left = Number(doses);
    if (hours.length === 0 || !Number.isFinite(left) || left < 1) {
        return null;
    }

    const bounded = period?.enabled === true;
    const from = bounded ? localDate(period.startDate, 0, 0) : null;
    const until = bounded ? localDate(period.endDate, 23, 59) : null;

    let remaining = left;
    for (let day = 0; day < SEARCH_DAYS; day += 1) {
        for (const at of hours) {
            const when = new Date(
                now.getFullYear(),
                now.getMonth(),
                now.getDate() + day,
                at.hour,
                at.minute,
            );
            if (when <= now || (from && when < from) || (until && when > until)) {
                continue;
            }

            remaining -= 1;
            if (remaining === 0) {
                return when;
            }
        }
    }

    return null;
}

/** A data em palavras, relativa quando está perto: é assim que se fala dela. */
export function runoutLabel(when, now = new Date()) {
    if (!(when instanceof Date) || Number.isNaN(when.getTime())) {
        return "";
    }

    const time = `${String(when.getHours()).padStart(2, "0")}:${String(when.getMinutes()).padStart(2, "0")}`;
    const days = daysBetween(now, when);
    if (days === 0) {
        return `hoje às ${time}`;
    }
    if (days === 1) {
        return `amanhã às ${time}`;
    }
    if (days === -1) {
        return `ontem às ${time}`;
    }

    return `${WEEKDAYS[when.getDay()]}, ${when.getDate()} ${MONTHS[when.getMonth()]} às ${time}`;
}
